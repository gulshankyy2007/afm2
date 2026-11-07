const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE_URL = 'http://127.0.0.1:8098';
const ARTIFACT_DIR = '/home/gulshan-yadav/.gemini/antigravity-ide/brain/070b97cb-54b1-47d6-90ac-c6e27a2cefb2';

const VIEWPORTS = [
    { width: 320, height: 800, name: '320x800 Mobile Compact' },
    { width: 360, height: 800, name: '360x800 Android Standard' },
    { width: 375, height: 812, name: '375x812 iPhone Standard' },
    { width: 390, height: 844, name: '390x844 iPhone 13/14' },
    { width: 412, height: 915, name: '412x915 Galaxy S21' },
    { width: 430, height: 932, name: '430x932 iPhone 14 Pro Max' },
    { width: 768, height: 1024, name: '768x1024 iPad Portrait' },
    { width: 1024, height: 768, name: '1024x768 iPad Landscape' },
    { width: 1280, height: 800, name: '1280x800 Laptop Small' },
    { width: 1366, height: 768, name: '1366x768 Desktop HD' },
    { width: 1440, height: 900, name: '1440x900 MacBook Pro' },
    { width: 1920, height: 1080, name: '1920x1080 Desktop FHD' }
];

const THEMES = [
    'midnight_ember',
    'velvet_amethyst',
    'emerald_forest',
    'vintage_paper',
    'nordic_frost',
    'solar_terracotta',
    'oceanic',
    'quiet_minimal'
];

function wait(ms) {
    return new Promise(r => setTimeout(r, ms));
}

function request(options, body = null) {
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

function parseRgb(colorStr) {
    if (!colorStr) return null;
    const match = colorStr.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
    if (match) {
        return [parseInt(match[1], 10), parseInt(match[2], 10), parseInt(match[3], 10)];
    }
    if (colorStr.startsWith('#')) {
        let hex = colorStr.slice(1);
        if (hex.length === 3) hex = hex.split('').map(c => c + c).join('');
        if (hex.length === 6) {
            return [
                parseInt(hex.slice(0, 2), 16),
                parseInt(hex.slice(2, 4), 16),
                parseInt(hex.slice(4, 6), 16)
            ];
        }
    }
    return null;
}

function getLuminance([r, g, b]) {
    const a = [r, g, b].map(v => {
        v /= 255;
        return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    });
    return a[0] * 0.2126 + a[1] * 0.7152 + a[2] * 0.0722;
}

function getContrastRatio(rgb1, rgb2) {
    const l1 = getLuminance(rgb1);
    const l2 = getLuminance(rgb2);
    const brighter = Math.max(l1, l2);
    const darker = Math.min(l1, l2);
    return (brighter + 0.05) / (darker + 0.05);
}

class CDPClient {
    constructor() {
        this.chrome = null;
        this.pageId = null;
        this.ws = null;
        this.msgId = 1;
        this.callbacks = new Map();
    }

    async startChrome() {
        console.log('[CDP] Launching Headless Chrome on port 9222...');
        this.chrome = spawn('/usr/bin/google-chrome', [
            '--headless=new',
            '--remote-debugging-port=9222',
            '--no-sandbox',
            '--disable-gpu',
            '--disable-dev-shm-usage',
            'about:blank'
        ]);

        for (let i = 0; i < 30; i++) {
            await wait(200);
            try {
                await request({ hostname: '127.0.0.1', port: 9222, path: '/json/version', method: 'GET' });
                console.log('[CDP] Headless Chrome initialized successfully.');
                return;
            } catch (e) {}
        }
        throw new Error('Chrome failed to start on port 9222');
    }

    async stopChrome() {
        if (this.ws) {
            this.ws.close();
            this.ws = null;
        }
        if (this.chrome) {
            this.chrome.kill('SIGTERM');
            this.chrome = null;
        }
    }

    async initPage(url = `${BASE_URL}/index.php`) {
        const pageInfo = await request({
            hostname: '127.0.0.1',
            port: 9222,
            path: `/json/new?${encodeURIComponent(url)}`,
            method: 'PUT'
        });
        this.pageId = pageInfo.id;

        await new Promise((resolve, reject) => {
            this.ws = new WebSocket(pageInfo.webSocketDebuggerUrl);
            this.ws.onopen = resolve;
            this.ws.onerror = reject;
            this.ws.onmessage = (event) => {
                const data = JSON.parse(event.data);
                if (data.id && this.callbacks.has(data.id)) {
                    const cb = this.callbacks.get(data.id);
                    this.callbacks.delete(data.id);
                    if (data.error) cb.reject(new Error(data.error.message));
                    else cb.resolve(data.result);
                }
            };
        });

        await this.send('Page.enable');
        await this.send('DOM.enable');
        await this.send('Network.enable');
    }

    send(method, params = {}) {
        return new Promise((resolve, reject) => {
            const id = this.msgId++;
            this.callbacks.set(id, { resolve, reject });
            this.ws.send(JSON.stringify({ id, method, params }));
        });
    }

    async navigate(url) {
        await this.send('Page.navigate', { url });
        await wait(600);
    }

    async setCookie(name, value) {
        await this.send('Network.setCookie', {
            name,
            value,
            domain: '127.0.0.1',
            path: '/'
        });
    }

    async setViewport(width, height) {
        await this.send('Emulation.setDeviceMetricsOverride', {
            width,
            height,
            deviceScaleFactor: 1,
            mobile: width < 769
        });
        await wait(200);
    }

    async evaluate(expression) {
        const res = await this.send('Runtime.evaluate', {
            expression,
            returnByValue: true,
            awaitPromise: true
        });
        return res?.result?.value;
    }

    async captureScreenshot(filename) {
        const res = await this.send('Page.captureScreenshot', { format: 'png' });
        const filePath = path.join(ARTIFACT_DIR, filename);
        fs.writeFileSync(filePath, Buffer.from(res.data, 'base64'));
        console.log(`  [Screenshot] Saved: ${filename}`);
        return filePath;
    }
}

async function run() {
    const cdp = new CDPClient();
    let passed = 0;
    let failed = 0;
    const testResults = [];

    const record = (category, testName, isPass, detail = '') => {
        if (isPass) {
            passed++;
            console.log(`  ✔ [PASS] ${category}: ${testName} ${detail}`);
        } else {
            failed++;
            console.error(`  ✘ [FAIL] ${category}: ${testName} - ${detail}`);
        }
        testResults.push({ category, testName, isPass, detail });
    };

    try {
        await cdp.startChrome();
        await cdp.initPage(`${BASE_URL}/index.php`);

        console.log('\n======================================================================');
        console.log(' SECTION 1: 12-VIEWPORT RESPONSIVE & MOBILE DRAWER MATRIX');
        console.log('======================================================================');

        // Test Guest Landing page across all 12 viewports
        for (const vp of VIEWPORTS) {
            await cdp.setViewport(vp.width, vp.height);
            await cdp.setCookie('test_auth_user', 'guest');
            await cdp.navigate(`${BASE_URL}/index.php`);

            const overflowData = await cdp.evaluate(`(() => {
                const docWidth = document.documentElement.offsetWidth;
                const scrollWidth = document.documentElement.scrollWidth;
                const winWidth = window.innerWidth;
                return { docWidth, scrollWidth, winWidth, hasOverflow: scrollWidth > winWidth };
            })()`);

            const noOverflow = !overflowData.hasOverflow;
            record('Viewport Matrix', `${vp.name} (${vp.width}x${vp.height}) - Zero Overflow`, noOverflow,
                `scrollWidth: ${overflowData.scrollWidth}px, innerWidth: ${overflowData.winWidth}px`);

            if (vp.width <= 768) {
                // Test interactive mobile navigation drawer via click
                const toggleResult = await cdp.evaluate(`(() => {
                    const toggle = document.getElementById('navToggleGuest') || document.getElementById('navToggle');
                    const nav = document.getElementById('guestNav') || document.getElementById('appNav');
                    if (!toggle || !nav) return { success: false, reason: 'Toggle or Nav element not found' };

                    // Initial state check
                    const initiallyClosed = !nav.classList.contains('open');

                    // Click toggle
                    toggle.click();
                    const opened = nav.classList.contains('open');
                    const ariaExpanded = toggle.getAttribute('aria-expanded') === 'true';
                    const navHeight = nav.offsetHeight;

                    // Check touch targets
                    const links = Array.from(nav.querySelectorAll('.nav-links a'));
                    const linksCount = links.length;
                    const allLinksTappable = links.every(a => a.offsetHeight >= 44);

                    // Click again to close
                    toggle.click();
                    const closedAgain = !nav.classList.contains('open');

                    return {
                        success: initiallyClosed && opened && ariaExpanded && navHeight > 100 && closedAgain && allLinksTappable,
                        linksCount,
                        navHeight,
                        allLinksTappable
                    };
                })()`);

                record('Mobile Nav Drawer', `${vp.name} Hamburger Click Interactive Toggle`, toggleResult.success,
                    `Links: ${toggleResult.linksCount}, Drawer Height: ${toggleResult.navHeight}px, Touch >=44px: ${toggleResult.allLinksTappable}`);
            }
        }

        console.log('\n======================================================================');
        console.log(' SECTION 2: ALL 8 THEMES VISUAL QUALITY & CONTRAST RATIO AUDIT');
        console.log('======================================================================');

        await cdp.setViewport(1366, 768);
        await cdp.navigate(`${BASE_URL}/index.php`);

        for (const theme of THEMES) {
            const themeEval = await cdp.evaluate(`((themeName) => {
                document.documentElement.setAttribute('data-theme', themeName);
                const computed = getComputedStyle(document.documentElement);
                const bgMain = computed.getPropertyValue('--bg-main').trim();
                const textPrimary = computed.getPropertyValue('--text-primary').trim();
                const primary = computed.getPropertyValue('--primary').trim();
                const borderColor = computed.getPropertyValue('--border-color').trim();
                const cardGlassBg = computed.getPropertyValue('--card-glass-bg').trim();
                const navDrawerBg = computed.getPropertyValue('--nav-drawer-bg').trim();
                return { bgMain, textPrimary, primary, borderColor, cardGlassBg, navDrawerBg };
            })('${theme}')`);

            const rgbBg = parseRgb(themeEval.bgMain);
            const rgbText = parseRgb(themeEval.textPrimary);
            let contrast = 0;
            if (rgbBg && rgbText) {
                contrast = getContrastRatio(rgbText, rgbBg);
            }

            const isContrastAA = contrast >= 4.5;
            record('Theme Audit', `${theme} Contrast Ratio`, isContrastAA,
                `Contrast: ${contrast.toFixed(2)}:1 (Min 4.5:1 required). Text: ${themeEval.textPrimary}, Bg: ${themeEval.bgMain}`);

            const hasAllTokens = Boolean(themeEval.primary && themeEval.borderColor && themeEval.cardGlassBg && themeEval.navDrawerBg);
            record('Theme Tokens', `${theme} Core Custom Properties Defined`, hasAllTokens,
                `Primary: ${themeEval.primary}, Border: ${themeEval.borderColor}`);

            // Capture screenshot for visual proof
            await cdp.captureScreenshot(`theme_${theme}_desktop_1366px.png`);
        }

        console.log('\n======================================================================');
        console.log(' SECTION 3: KEY APPLICATION PAGES RESPONSIVE & THEME AUDIT');
        console.log('======================================================================');

        const pagesToAudit = [
            { path: '/index.php', auth: 'guest', name: 'Public Landing Page' },
            { path: '/about.php', auth: 'guest', name: 'About AFM#2' },
            { path: '/privacy.php', auth: 'guest', name: 'Privacy Policy' },
            { path: '/terms.php', auth: 'guest', name: 'Terms of Use' },
            { path: '/contact.php', auth: 'guest', name: 'Contact AFM#2' },
            { path: '/login.php', auth: 'guest', name: 'Sign In Page' },
            { path: '/index.php', auth: '994', name: 'Authenticated Journal / Dashboard' },
            { path: '/results.php', auth: '994', name: 'Tournament Results & Archive' },
            { path: '/account.php', auth: '994', name: 'Account Security & Passkeys' },
            { path: '/users.php', auth: '994', name: 'User Administration' },
            { path: '/library.php', auth: '994', name: 'Music Library & Catalogue' },
            { path: '/recordings.php', auth: '994', name: 'YouTube Audio & Embeds' },
            { path: '/discovery.php', auth: '994', name: 'Discovery Shortlist' },
            { path: '/auditions.php', auth: '994', name: 'Auditions & Scorecard' },
            { path: '/tournament.php', auth: '994', name: 'Tournament Progression' }
        ];

        for (const page of pagesToAudit) {
            // Test Mobile 360x800
            await cdp.setViewport(360, 800);
            await cdp.setCookie('test_auth_user', page.auth);
            await cdp.navigate(`${BASE_URL}${page.path}`);

            const mobileOverflow = await cdp.evaluate(`(() => {
                return document.documentElement.scrollWidth <= window.innerWidth;
            })()`);
            record('Page Mobile (360x800)', `${page.name} - Zero Overflow`, mobileOverflow);

            // Test Desktop 1366x768
            await cdp.setViewport(1366, 768);
            await cdp.navigate(`${BASE_URL}${page.path}`);
            const desktopOverflow = await cdp.evaluate(`(() => {
                return document.documentElement.scrollWidth <= window.innerWidth;
            })()`);
            record('Page Desktop (1366x768)', `${page.name} - Zero Overflow`, desktopOverflow);

            // Capture representative screenshots
            if (page.path === '/results.php') {
                await cdp.setViewport(360, 800);
                await cdp.captureScreenshot('verified_results_mobile_360px.png');
                await cdp.setViewport(1366, 768);
                await cdp.captureScreenshot('verified_results_desktop_1366px.png');
            } else if (page.path === '/users.php') {
                await cdp.setViewport(360, 800);
                await cdp.captureScreenshot('verified_users_mobile_360px.png');
                await cdp.setViewport(1366, 768);
                await cdp.captureScreenshot('verified_users_desktop_1366px.png');
            } else if (page.path === '/account.php') {
                await cdp.setViewport(360, 800);
                await cdp.captureScreenshot('verified_account_mobile_360px.png');
            }
        }

        // Test Vintage Paper mobile drawer open
        await cdp.setViewport(360, 800);
        await cdp.setCookie('test_auth_user', 'guest');
        await cdp.navigate(`${BASE_URL}/index.php`);
        await cdp.evaluate(`(() => {
            document.documentElement.setAttribute('data-theme', 'vintage_paper');
            document.getElementById('navToggleGuest').click();
        })()`);
        await wait(300);
        await cdp.captureScreenshot('verified_drawer_open_vintage_paper_360px.png');

        // Test Nordic Frost mobile drawer open
        await cdp.evaluate(`(() => {
            document.documentElement.setAttribute('data-theme', 'nordic_frost');
        })()`);
        await wait(300);
        await cdp.captureScreenshot('verified_drawer_open_nordic_frost_360px.png');

        // Test Midnight Ember mobile hero composition
        await cdp.evaluate(`(() => {
            document.documentElement.setAttribute('data-theme', 'midnight_ember');
            document.getElementById('navToggleGuest').click(); // close drawer
        })()`);
        await wait(300);
        await cdp.captureScreenshot('verified_landing_hero_midnight_360px.png');

        console.log('\n======================================================================');
        console.log(' SECTION 4: THEME SWITCHING & PERSISTENCE API INTEGRATION');
        console.log('======================================================================');

        await cdp.setCookie('test_auth_user', '994');
        await cdp.navigate(`${BASE_URL}/index.php`);

        const themeSwitchTest = await cdp.evaluate(`(async () => {
            const btnAppearance = document.getElementById('btnOpenAppearance');
            const modal = document.getElementById('appearanceModal');
            if (!btnAppearance || !modal) return { success: false, reason: 'Modal or button missing' };

            btnAppearance.click();
            const modalOpened = modal.classList.contains('active');

            // Select Nordic Frost card
            const nordicCard = modal.querySelector('[data-theme-val="nordic_frost"]');
            if (!nordicCard) return { success: false, reason: 'Nordic Frost card missing' };
            nordicCard.click();

            const themeApplied = document.documentElement.getAttribute('data-theme') === 'nordic_frost';

            // Close modal
            const closeBtn = document.getElementById('closeAppearanceModal');
            if (closeBtn) closeBtn.click();
            const modalClosed = !modal.classList.contains('active');

            return { success: modalOpened && themeApplied && modalClosed };
        })()`);

        record('Theme Switching', 'Interactive Appearance Modal & Live Preview', themeSwitchTest.success);

    } catch (err) {
        console.error('[Error during verification]:', err);
        failed++;
    } finally {
        await cdp.stopChrome();
    }

    console.log('\n======================================================================');
    console.log(` VERIFICATION SUMMARY: ${passed} Passed, ${failed} Failed out of ${passed + failed} Tests`);
    console.log('======================================================================');

    process.exit(failed > 0 ? 1 : 0);
}

run();
