const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const FFMPEG_PATH = '/home/gulshan-yadav/.local/bin/ffmpeg';
const OUTPUT_DIR = '/mnt/data/GY/Music/AFM#2/artifacts/afm2-showcase/raw';
const BASE_URL = 'http://127.0.0.1:8088';
const SESSION_COOKIE = 'showcase-demo-session-12345';

function wait(ms) {
    return new Promise(r => setTimeout(r, ms));
}

async function request(options, body = null) {
    return new Promise((resolve, reject) => {
        const req = http.request(options, (res) => {
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => {
                try {
                    resolve(JSON.parse(data));
                } catch (e) {
                    resolve(data);
                }
            });
        });
        req.on('error', reject);
        if (body) req.write(body);
        req.end();
    });
}

class ChromeRecorder {
    constructor() {
        this.chrome = null;
        this.pageId = null;
        this.ws = null;
        this.msgId = 1;
        this.latestFrame = null;
        this.ffmpeg = null;
        this.recordingInterval = null;
        this.framesWritten = 0;
    }

    async startChrome() {
        console.log('[Chrome] Launching headless browser...');
        this.chrome = spawn('/usr/bin/google-chrome', [
            '--headless=new',
            '--remote-debugging-port=9222',
            '--window-size=1920,1080',
            '--no-sandbox',
            '--disable-gpu',
            '--disable-dev-shm-usage',
            'about:blank'
        ]);

        for (let i = 0; i < 25; i++) {
            await wait(200);
            try {
                await request({ hostname: '127.0.0.1', port: 9222, path: '/json/version', method: 'GET' });
                console.log('[Chrome] Headless Chrome is ready.');
                return;
            } catch (e) {}
        }
        throw new Error('Chrome failed to start on port 9222');
    }

    async stopChrome() {
        if (this.chrome) {
            console.log('[Chrome] Shutting down browser process...');
            this.chrome.kill('SIGTERM');
            this.chrome = null;
        }
    }

    async initPage(initialUrl = `${BASE_URL}/login.php`) {
        const pageInfo = await request({
            hostname: '127.0.0.1',
            port: 9222,
            path: `/json/new?${encodeURIComponent(initialUrl)}`,
            method: 'PUT'
        });
        this.pageId = pageInfo.id;
        console.log(`[Page] Created page tab: ${this.pageId}`);

        await new Promise((resolve, reject) => {
            this.ws = new WebSocket(pageInfo.webSocketDebuggerUrl);
            this.ws.onopen = () => {
                console.log('[WebSocket] Connected to page debugger.');
                resolve();
            };
            this.ws.onerror = reject;
            this.ws.onmessage = (event) => this.handleMessage(JSON.parse(event.data));
        });

        await this.send('Page.enable');
        await this.send('DOM.enable');
        await this.send('Network.enable');

        // Set authenticated session cookie
        await this.send('Network.setCookie', {
            name: 'AFM2_SESSID',
            value: SESSION_COOKIE,
            domain: '127.0.0.1',
            path: '/'
        });
    }

    async closePage() {
        if (this.ws) {
            this.ws.close();
            this.ws = null;
        }
        if (this.pageId) {
            await request({
                hostname: '127.0.0.1',
                port: 9222,
                path: `/json/close/${this.pageId}`,
                method: 'GET'
            }).catch(() => {});
            this.pageId = null;
        }
    }

    send(method, params = {}) {
        return new Promise((resolve) => {
            const id = this.msgId++;
            const handler = (event) => {
                const msg = JSON.parse(event.data);
                if (msg.id === id) {
                    this.ws.removeEventListener('message', handler);
                    resolve(msg.result);
                }
            };
            this.ws.addEventListener('message', handler);
            this.ws.send(JSON.stringify({ id, method, params }));
        });
    }

    handleMessage(msg) {
        if (msg.method === 'Page.screencastFrame') {
            this.latestFrame = Buffer.from(msg.params.data, 'base64');
            const ackId = this.msgId++;
            this.ws.send(JSON.stringify({
                id: ackId,
                method: 'Page.screencastFrameAck',
                params: { sessionId: msg.params.sessionId }
            }));
        }
    }

    async setViewport(width = 1920, height = 1080, mobile = false) {
        await this.send('Emulation.setDeviceMetricsOverride', {
            width,
            height,
            deviceScaleFactor: 1,
            mobile
        });
    }

    async navigate(url) {
        console.log(`[Navigate] Loading ${url}...`);
        await this.send('Page.navigate', { url });
        await wait(1800); // Wait for fonts, CSS transitions, and DOM ready
    }

    async eval(expression) {
        return await this.send('Runtime.evaluate', { expression, returnByValue: true });
    }

    async startRecording(outputFilename, width = 1920, height = 1080) {
        const fullPath = path.join(OUTPUT_DIR, outputFilename);
        console.log(`[Record] Starting recording -> ${outputFilename} (${width}x${height} @ 30fps)...`);
        this.latestFrame = null;
        this.framesWritten = 0;

        this.ffmpeg = spawn(FFMPEG_PATH, [
            '-y',
            '-f', 'image2pipe',
            '-vcodec', 'mjpeg',
            '-framerate', '30',
            '-i', '-',
            '-c:v', 'libx264',
            '-preset', 'fast',
            '-crf', '18',
            '-pix_fmt', 'yuv420p',
            fullPath
        ]);

        this.ffmpeg.stderr.on('data', () => {});

        await this.send('Page.startScreencast', {
            format: 'jpeg',
            quality: 92,
            maxWidth: width,
            maxHeight: height,
            everyNthFrame: 1
        });

        // Wait for first frame
        while (!this.latestFrame) {
            await wait(30);
        }

        // Emit at constant 30 fps
        this.recordingInterval = setInterval(() => {
            if (this.latestFrame && this.ffmpeg && this.ffmpeg.stdin.writable) {
                this.ffmpeg.stdin.write(this.latestFrame);
                this.framesWritten++;
            }
        }, 1000 / 30);
    }

    async stopRecording(outputFilename) {
        console.log(`[Record] Stopping recording for ${outputFilename}...`);
        clearInterval(this.recordingInterval);
        this.recordingInterval = null;

        await this.send('Page.stopScreencast');
        this.ffmpeg.stdin.end();

        await new Promise((resolve) => {
            this.ffmpeg.on('close', (code) => {
                console.log(`[Record] FFmpeg finalized (${code}). Total frames: ${this.framesWritten}`);
                resolve();
            });
        });

        const fullPath = path.join(OUTPUT_DIR, outputFilename);
        const stat = fs.statSync(fullPath);
        console.log(`[Record] Saved ${outputFilename} (${(stat.size / 1024 / 1024).toFixed(2)} MB)`);
    }
}

// Module export for runner
module.exports = { ChromeRecorder, wait, BASE_URL, OUTPUT_DIR };
