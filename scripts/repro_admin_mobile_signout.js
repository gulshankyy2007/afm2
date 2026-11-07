const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const ARTIFACT_DIR = '/home/gulshan-yadav/.gemini/antigravity-ide/brain/070b97cb-54b1-47d6-90ac-c6e27a2cefb2';

function wait(ms) { return new Promise(r => setTimeout(r, ms)); }
function request(options) {
    return new Promise((resolve, reject) => {
        const req = http.request(options, (res) => {
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => resolve(JSON.parse(data)));
        });
        req.on('error', reject);
        req.end();
    });
}

async function testMobileSignout() {
    const chrome = spawn('/usr/bin/google-chrome', [
        '--headless=new',
        '--remote-debugging-port=9227',
        '--no-sandbox',
        '--disable-gpu',
        '--disable-dev-shm-usage',
        'about:blank'
    ]);
    for (let i = 0; i < 30; i++) {
        await wait(200);
        try {
            await request({ hostname: '127.0.0.1', port: 9227, path: '/json/version' });
            break;
        } catch (e) {}
    }

    const page = await request({ hostname: '127.0.0.1', port: 9227, path: '/json/new', method: 'PUT' });
    const ws = new globalThis.WebSocket(page.webSocketDebuggerUrl);

    let id = 1;
    const callbacks = new Map();
    ws.onmessage = (e) => {
        const d = JSON.parse(e.data);
        if (d.id && callbacks.has(d.id)) {
            callbacks.get(d.id)(d.result);
            callbacks.delete(d.id);
        }
    };
    function send(method, params = {}) {
        return new Promise(res => {
            const curId = id++;
            callbacks.set(curId, res);
            ws.send(JSON.stringify({ id: curId, method, params }));
        });
    }

    await new Promise(r => ws.onopen = r);
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Network.enable');

    // Test as Admin (test_auth_user = 994)
    await send('Network.setCookie', {
        name: 'test_auth_user',
        value: '994',
        domain: '127.0.0.1',
        path: '/'
    });

    const widths = [320, 360, 390, 430];
    for (const w of widths) {
        console.log(`\n=== Testing Mobile Admin at width ${w}px ===`);
        await send('Emulation.setDeviceMetricsOverride', {
            width: w,
            height: 800,
            deviceScaleFactor: 1,
            mobile: true
        });

        await send('Page.navigate', { url: 'http://127.0.0.1:8098/' });
        await wait(800);

        // Click navToggle
        await send('Runtime.evaluate', {
            expression: 'document.getElementById("navToggle")?.click();'
        });
        await wait(400);

        const navState = await send('Runtime.evaluate', {
            expression: `(() => {
                const nav = document.getElementById("appNav");
                const btnLogout = document.getElementById("btnLogout");
                const toggle = document.getElementById("navToggle");
                const userMeta = nav?.querySelector(".user-meta");
                const links = Array.from(nav?.querySelectorAll(".nav-links a") || []).map(a => a.textContent.trim());

                let btnLogoutRect = null;
                let btnLogoutComputed = null;
                if (btnLogout) {
                    const r = btnLogout.getBoundingClientRect();
                    const s = window.getComputedStyle(btnLogout);
                    btnLogoutRect = { top: r.top, left: r.left, width: r.width, height: r.height };
                    btnLogoutComputed = { display: s.display, visibility: s.visibility, opacity: s.opacity };
                }

                let navRect = null;
                if (nav) {
                    const r = nav.getBoundingClientRect();
                    navRect = { top: r.top, left: r.left, width: r.width, height: r.height, scrollHeight: nav.scrollHeight };
                }

                return {
                    navOpen: nav?.classList.contains("open"),
                    navAriaExpanded: toggle?.getAttribute("aria-expanded"),
                    navRect,
                    links,
                    btnLogoutRect,
                    btnLogoutComputed,
                    userMetaExists: !!userMeta,
                    userMetaHTML: userMeta?.outerHTML
                };
            })()`,
            returnByValue: true
        });

        console.log('Nav State:', JSON.stringify(navState.result.value, null, 2));

        const shot = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(path.join(ARTIFACT_DIR, `admin_nav_open_${w}px.png`), Buffer.from(shot.data, 'base64'));
        console.log(`Saved screenshot: admin_nav_open_${w}px.png`);
    }

    ws.close();
    chrome.kill();
}

testMobileSignout();
