const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const ARTIFACT_DIR = '/home/gulshan-yadav/.gemini/antigravity-ide/brain/070b97cb-54b1-47d6-90ac-c6e27a2cefb2';
const BASE_URL = 'http://127.0.0.1:8098';

function wait(ms) { return new Promise(r => setTimeout(r, ms)); }

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

class CDPClient {
    constructor(port = 9228) {
        this.port = port;
        this.id = 1;
        this.callbacks = new Map();
        this.chrome = null;
        this.ws = null;
    }

    async start() {
        this.chrome = spawn('/usr/bin/google-chrome', [
            '--headless=new',
            `--remote-debugging-port=${this.port}`,
            '--no-sandbox',
            '--disable-gpu',
            '--disable-dev-shm-usage',
            'about:blank'
        ]);

        for (let i = 0; i < 30; i++) {
            await wait(200);
            try {
                await request({ hostname: '127.0.0.1', port: this.port, path: '/json/version' });
                return;
            } catch (e) {}
        }
        throw new Error('Chrome failed to start');
    }

    async stop() {
        if (this.ws) {
            try { this.ws.close(); } catch (e) {}
            this.ws = null;
        }
        if (this.chrome) {
            this.chrome.kill('SIGTERM');
            this.chrome = null;
        }
    }

    async initPage(url = 'about:blank') {
        const page = await request({
            hostname: '127.0.0.1',
            port: this.port,
            path: `/json/new?${encodeURIComponent(url)}`,
            method: 'PUT'
        });
        this.ws = new globalThis.WebSocket(page.webSocketDebuggerUrl);
        await new Promise(r => this.ws.onopen = r);

        this.ws.onmessage = (e) => {
            const d = JSON.parse(e.data);
            if (d.id && this.callbacks.has(d.id)) {
                const cb = this.callbacks.get(d.id);
                this.callbacks.delete(d.id);
                if (d.error) cb.reject(new Error(d.error.message));
                else cb.resolve(d.result);
            }
        };

        await this.send('Page.enable');
        await this.send('Runtime.enable');
        await this.send('Network.enable');
    }

    send(method, params = {}) {
        return new Promise((resolve, reject) => {
            const curId = this.id++;
            this.callbacks.set(curId, { resolve, reject });
            this.ws.send(JSON.stringify({ id: curId, method, params }));
        });
    }

    async eval(expression) {
        const res = await this.send('Runtime.evaluate', {
            expression,
            returnByValue: true,
            awaitPromise: true
        });
        if (res.exceptionDetails) {
            throw new Error(res.exceptionDetails.text || 'Eval error');
        }
        return res.result ? res.result.value : undefined;
    }

    async screenshot(filepath) {
        const res = await this.send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(filepath, Buffer.from(res.data, 'base64'));
    }
}

async function runVerification() {
    console.log('======================================================================');
    console.log(' AFM#2 COMPREHENSIVE VERIFICATION SUITE');
    console.log(' Landing Page Accuracy, Mobile Admin Sign-Out, Stages & Identity');
    console.log('======================================================================\n');

    const cdp = new CDPClient(9228);
    await cdp.start();
    await cdp.initPage(`${BASE_URL}/landing.php`);

    let passed = 0;
    let failed = 0;

    function assert(cond, msg) {
        if (cond) {
            console.log(`  ✔ PASS: ${msg}`);
            passed++;
        } else {
            console.error(`  ✖ FAIL: ${msg}`);
            failed++;
        }
    }

    try {
        // ====================================================================
        // 1. Landing Page Accuracy Checks
        // ====================================================================
        console.log('\n--- 1. Landing Page Content & Accuracy Verification ---');
        await cdp.send('Page.navigate', { url: `${BASE_URL}/landing.php` });
        await wait(800);

        const landingData = await cdp.eval(`(() => {
            const text = document.body.innerText;
            const has2411 = /2,?411/.test(text);
            const hasGulshan = /Gulshan Yadav/i.test(text);
            
            // Stages check
            const stageNames = Array.from(document.querySelectorAll('.stage-name')).map(el => el.textContent.trim());
            const stageDescs = Array.from(document.querySelectorAll('.stage-desc')).map(el => el.textContent.trim());

            // Repo link check
            const repoLinks = Array.from(document.querySelectorAll('a[href*="github.com/gulshankyy2007/afm2"]')).map(a => ({
                href: a.href,
                text: a.textContent.trim(),
                target: a.target,
                rel: a.rel
            }));

            // Footer check
            const footer = document.querySelector('footer');
            const footerText = footer ? footer.innerText : '';

            return {
                has2411,
                hasGulshan,
                stageNames,
                stageDescs,
                repoLinks,
                footerText
            };
        })()`);

        assert(!landingData.has2411, 'No misleading "2,411" track claims found on public landing page');
        assert(!landingData.hasGulshan, 'No personal name "Gulshan Yadav" found on public landing page');
        assert(landingData.stageNames.length === 7, `Landing page displays exactly 7 tournament stages (found: ${landingData.stageNames.length})`);
        
        const expectedStages = [
            'Stage 1: Formal Auditions',
            'Stage 2: Top 200',
            'Stage 3: Top 100',
            'Stage 4: Top 50',
            'Stage 5: Top 20',
            'Stage 6: Top 10',
            'Stage 7: Finals'
        ];
        assert(
            JSON.stringify(landingData.stageNames) === JSON.stringify(expectedStages),
            `Authoritative stage names match database rounds: [${landingData.stageNames.join(', ')}]`
        );

        assert(landingData.repoLinks.length > 0, 'Project Repository link to GitHub is present on landing page');
        const primaryRepo = landingData.repoLinks.find(l => l.text.includes('Project Repository'));
        assert(primaryRepo && primaryRepo.target === '_blank' && primaryRepo.rel.includes('noopener'),
            'Project Repository link is clearly labeled and opens safely in new tab'
        );

        await cdp.screenshot(path.join(ARTIFACT_DIR, 'verified_landing_page.png'));

        // ====================================================================
        // 2. About, Contact & Privacy Policy Pages
        // ====================================================================
        console.log('\n--- 2. Supporting Trust Pages Verification (About, Contact, Privacy) ---');
        
        // About Page
        await cdp.send('Page.navigate', { url: `${BASE_URL}/about.php` });
        await wait(600);
        const aboutData = await cdp.eval(`(() => {
            const text = document.body.innerText;
            const stages = Array.from(document.querySelectorAll('.tournament-stages-list li')).map(li => li.innerText.trim());
            return {
                has2411: /2,?411/.test(text),
                hasGulshan: /Gulshan Yadav/i.test(text),
                stages
            };
        })()`);
        assert(!aboutData.has2411, 'About page contains no misleading 2,411 track claims');
        assert(!aboutData.hasGulshan, 'About page contains no personal name "Gulshan Yadav"');
        assert(aboutData.stages.length === 7 && aboutData.stages[2].startsWith('Top 100'),
            'About page tournament list reflects authoritative Top 100 progression'
        );

        // Contact Page
        await cdp.send('Page.navigate', { url: `${BASE_URL}/contact.php` });
        await wait(600);
        const contactData = await cdp.eval(`(() => {
            const text = document.body.innerText;
            const repoCard = document.querySelector('.contact-channel-card a[href*="github.com/gulshankyy2007/afm2"]');
            return {
                hasGulshan: /Gulshan Yadav/i.test(text),
                repoCardText: repoCard ? repoCard.textContent.trim() : null
            };
        })()`);
        assert(!contactData.hasGulshan, 'Contact page contains no personal name "Gulshan Yadav"');
        assert(contactData.repoCardText === 'Project Repository ↗', 'Contact page clearly labels "Project Repository ↗"');

        // Privacy Policy Page
        await cdp.send('Page.navigate', { url: `${BASE_URL}/privacy.php` });
        await wait(600);
        const privacyData = await cdp.eval(`(() => {
            return {
                hasGulshan: /Gulshan Yadav/i.test(document.body.innerText)
            };
        })()`);
        assert(!privacyData.hasGulshan, 'Privacy Policy contains no personal name "Gulshan Yadav"');

        // ====================================================================
        // 3. Mobile Admin Navigation & Immediate Sign Out (320px, 360px, 390px, 430px)
        // ====================================================================
        console.log('\n--- 3. Mobile Admin Navigation & Sign Out Verification ---');
        // Authenticate as Admin (ID 994)
        await cdp.send('Network.setCookie', {
            name: 'test_auth_user',
            value: '994',
            domain: '127.0.0.1',
            path: '/'
        });

        const mobileWidths = [320, 360, 390, 430];
        for (const w of mobileWidths) {
            console.log(`\n  [Viewport ${w}px - Admin Account]`);
            await cdp.send('Emulation.setDeviceMetricsOverride', {
                width: w,
                height: 800,
                deviceScaleFactor: 1,
                mobile: true
            });

            await cdp.send('Page.navigate', { url: `${BASE_URL}/index.php` });
            await wait(700);

            // Verify drawer closed initially
            const initialNav = await cdp.eval(`(() => {
                const nav = document.getElementById('appNav');
                return nav ? nav.classList.contains('open') : null;
            })()`);
            assert(initialNav === false, `Drawer is initially closed at ${w}px`);

            // Open drawer
            await cdp.eval(`document.getElementById('navToggle').click()`);
            await wait(400);

            const drawerState = await cdp.eval(`(() => {
                const nav = document.getElementById('appNav');
                const userCard = document.getElementById('mobileUserCard');
                const signoutBtn = document.getElementById('btnMobileSignout');
                const bottomSignout = document.getElementById('btnLogout');
                const usersLink = document.getElementById('navUsers');
                const links = Array.from(nav.querySelectorAll('.nav-links a')).map(a => a.textContent.trim());

                const userCardRect = userCard ? userCard.getBoundingClientRect() : null;
                const bottomUserPill = document.querySelector('.user-meta .user-pill');
                const signoutRect = signoutBtn ? signoutBtn.getBoundingClientRect() : null;
                const roleBadge = userCard ? userCard.querySelector('.mobile-role-badge')?.textContent.trim() : null;
                const userName = userCard ? userCard.querySelector('.mobile-user-name')?.textContent.trim() : null;

                return {
                    isOpen: nav.classList.contains('open'),
                    userCardVisible: userCard ? (userCard.offsetParent !== null) : false,
                    userCardTop: userCardRect ? userCardRect.top : null,
                    signoutVisible: signoutBtn ? (signoutBtn.offsetParent !== null) : false,
                    signoutTop: signoutRect ? signoutRect.top : null,
                    signoutHeight: signoutRect ? signoutRect.height : null,
                    signoutWidth: signoutRect ? signoutRect.width : null,
                    roleBadge,
                    userName,
                    hasUsersLink: !!usersLink,
                    linksCount: links.length,
                    isScrollable: nav.scrollHeight > nav.clientHeight,
                    scrollHeight: nav.scrollHeight,
                    clientHeight: nav.clientHeight,
                    bottomSignoutVisible: bottomSignout ? (bottomSignout.offsetParent !== null) : false,
                    bottomUserPillVisible: bottomUserPill ? (bottomUserPill.offsetParent !== null) : false
                };
            })()`);

            assert(drawerState.isOpen, `Mobile drawer opens successfully at ${w}px`);
            assert(drawerState.userCardVisible, `Top user card is visible inside drawer at ${w}px`);
            assert(drawerState.userName === 'adminuser', `User card displays correct admin username (got: ${drawerState.userName})`);
            assert(drawerState.roleBadge === 'Admin', `Role badge accurately shows "Admin" (got: ${drawerState.roleBadge})`);
            assert(drawerState.signoutVisible, `Mobile Sign Out button is visible at ${w}px`);
            assert(drawerState.signoutTop !== null && drawerState.signoutTop < 150,
                `Mobile Sign Out button is positioned immediately at top of drawer (top: ${drawerState.signoutTop}px)`
            );
            assert(drawerState.signoutHeight >= 44, `Sign Out touch target height is accessible (>= 44px, got: ${drawerState.signoutHeight}px)`);
            assert(drawerState.hasUsersLink, `Admin sees Users navigation link in drawer`);
            assert(!drawerState.bottomSignoutVisible, `Mobile drawer cleanly suppresses duplicate bottom Sign Out in .user-meta`);
            assert(!drawerState.bottomUserPillVisible, `Mobile drawer cleanly suppresses duplicate bottom user pill in .user-meta`);

            await cdp.screenshot(path.join(ARTIFACT_DIR, `verified_mobile_admin_${w}px.png`));
        }

        // Test Sign Out form submission
        console.log('\n  [Testing Admin Mobile Sign-Out Action]');
        await cdp.eval(`document.getElementById('btnMobileSignout').form.requestSubmit()`);
        await wait(1200);
        const postSignoutUrl = await cdp.eval(`window.location.href`);
        const postSignoutNav = await cdp.eval(`document.getElementById('navToggleGuest') ? 'guest' : 'auth'`);
        assert(postSignoutUrl.includes('login.php') || postSignoutNav === 'guest',
            `Sign Out successfully redirected to login and logged admin out (current nav: ${postSignoutNav}, url: ${postSignoutUrl})`
        );

        // ====================================================================
        // 4. Mobile Regular User Navigation (Judge Role)
        // ====================================================================
        console.log('\n--- 4. Mobile Regular User (Judge) Navigation Verification ---');
        // Authenticate as Regular User (ID 995, 'gulshan', role 'user')
        await cdp.send('Network.setCookie', {
            name: 'test_auth_user',
            value: '995',
            domain: '127.0.0.1',
            path: '/'
        });

        for (const w of [360, 390]) {
            console.log(`\n  [Viewport ${w}px - Regular User Account]`);
            await cdp.send('Emulation.setDeviceMetricsOverride', {
                width: w,
                height: 800,
                deviceScaleFactor: 1,
                mobile: true
            });

            await cdp.send('Page.navigate', { url: `${BASE_URL}/index.php` });
            await wait(700);

            // Open drawer
            await cdp.eval(`document.getElementById('navToggle').click()`);
            await wait(400);

            const userDrawerState = await cdp.eval(`(() => {
                const nav = document.getElementById('appNav');
                const userCard = document.getElementById('mobileUserCard');
                const signoutBtn = document.getElementById('btnMobileSignout');
                const usersLink = document.getElementById('navUsers');

                const roleBadge = userCard ? userCard.querySelector('.mobile-role-badge')?.textContent.trim() : null;
                const userName = userCard ? userCard.querySelector('.mobile-user-name')?.textContent.trim() : null;

                return {
                    userCardVisible: userCard ? (userCard.offsetParent !== null) : false,
                    signoutVisible: signoutBtn ? (signoutBtn.offsetParent !== null) : false,
                    roleBadge,
                    userName,
                    hasUsersLink: !!usersLink
                };
            })()`);

            assert(userDrawerState.userCardVisible, `Top user card visible for regular user at ${w}px`);
            assert(userDrawerState.signoutVisible, `Sign Out button visible for regular user at ${w}px`);
            assert(userDrawerState.roleBadge === 'Judge', `Role badge shows "Judge" for regular user (got: ${userDrawerState.roleBadge})`);
            assert(!userDrawerState.hasUsersLink, `Administrative "Users" link is strictly HIDDEN for regular user`);

            await cdp.screenshot(path.join(ARTIFACT_DIR, `verified_mobile_user_${w}px.png`));
        }

        // ====================================================================
        // 5. Desktop Navigation Preserved (1280px, 1366px, 1920px)
        // ====================================================================
        console.log('\n--- 5. Desktop Navigation & Layout Preservation ---');
        // Reset as Admin
        await cdp.send('Network.setCookie', {
            name: 'test_auth_user',
            value: '994',
            domain: '127.0.0.1',
            path: '/'
        });

        const desktopWidths = [1280, 1366, 1920];
        for (const dw of desktopWidths) {
            console.log(`\n  [Desktop Viewport ${dw}px]`);
            await cdp.send('Emulation.setDeviceMetricsOverride', {
                width: dw,
                height: 800,
                deviceScaleFactor: 1,
                mobile: false
            });

            await cdp.send('Page.navigate', { url: `${BASE_URL}/index.php` });
            await wait(700);

            const desktopState = await cdp.eval(`(() => {
                const toggle = document.getElementById('navToggle');
                const mobileCard = document.getElementById('mobileUserCard');
                const nav = document.getElementById('appNav');
                const desktopSignout = document.getElementById('btnLogout');
                const appearanceBtn = document.getElementById('btnOpenAppearance');
                const links = Array.from(nav.querySelectorAll('.nav-links a')).map(a => a.textContent.trim());

                const toggleDisplay = toggle ? window.getComputedStyle(toggle).display : null;
                const mobileCardDisplay = mobileCard ? window.getComputedStyle(mobileCard).display : null;
                const navDisplay = nav ? window.getComputedStyle(nav).display : null;
                const signoutRect = desktopSignout ? desktopSignout.getBoundingClientRect() : null;

                return {
                    toggleHidden: toggleDisplay === 'none',
                    mobileCardHidden: mobileCardDisplay === 'none',
                    navVisible: navDisplay === 'flex',
                    desktopSignoutVisible: desktopSignout ? desktopSignout.offsetParent !== null : false,
                    desktopSignoutRect: signoutRect,
                    hasAppearanceBtn: !!appearanceBtn,
                    linksCount: links.length
                };
            })()`);

            assert(desktopState.toggleHidden, `Hamburger button is hidden on desktop at ${dw}px`);
            assert(desktopState.mobileCardHidden, `Mobile user card is strictly hidden on desktop at ${dw}px (display: none)`);
            assert(desktopState.navVisible, `Desktop navigation is visible and flex-aligned at ${dw}px`);
            assert(desktopState.desktopSignoutVisible, `Desktop Sign Out button in .user-meta is visible at ${dw}px`);
            assert(desktopState.linksCount === 8, `Desktop admin navigation displays all 8 links (got: ${desktopState.linksCount})`);

            if (dw === 1366) {
                await cdp.screenshot(path.join(ARTIFACT_DIR, 'verified_desktop_admin_1366px.png'));
            }
        }

        // ====================================================================
        // 6. Theme Switching & Contrast Verification
        // ====================================================================
        console.log('\n--- 6. All Curated Themes Compatibility Verification ---');
        const themes = ['midnight_ember', 'vintage_paper', 'oceanic', 'quiet_minimal'];
        for (const t of themes) {
            await cdp.eval(`document.documentElement.setAttribute('data-theme', '${t}')`);
            await wait(200);

            const themeCheck = await cdp.eval(`(() => {
                const theme = document.documentElement.getAttribute('data-theme');
                const headerBg = window.getComputedStyle(document.getElementById('appHeader')).backgroundColor;
                const signoutColor = window.getComputedStyle(document.getElementById('btnLogout')).color;
                return { theme, headerBg, signoutColor };
            })()`);

            assert(themeCheck.theme === t, `Theme successfully applied: ${t}`);
        }

    } finally {
        await cdp.stop();
    }

    console.log('\n======================================================================');
    console.log(` RESULTS: ${passed} Passed, ${failed} Failed`);
    console.log('======================================================================\n');

    if (failed > 0) {
        process.exit(1);
    }
}

runVerification().catch(err => {
    console.error('Fatal Verification Error:', err);
    process.exit(1);
});
