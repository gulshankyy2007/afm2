const { ChromeRecorder, wait, BASE_URL } = require('./recorder_core');

async function recordShowcase() {
    console.log('====================================================');
    console.log(' AFM#2 — 1080p 60fps/30fps Video Showcase Recorder   ');
    console.log('====================================================');

    const rec = new ChromeRecorder();
    await rec.startChrome();
    await rec.initPage();

    try {
        // =========================================================================
        // Clip 1: 01-opening.mp4 (8-10s)
        // =========================================================================
        console.log('\n>>> [1/9] Recording 01-opening.mp4...');
        await rec.setViewport(1920, 1080);
        await rec.navigate(`${BASE_URL}/index.php`);
        await wait(1000);

        await rec.startRecording('01-opening.mp4', 1920, 1080);
        await wait(2200); // Hero view

        await rec.eval('window.scrollBy({ top: 380, behavior: "smooth" })');
        await wait(2800); // Stats cards view

        await rec.eval('window.scrollBy({ top: -380, behavior: "smooth" })');
        await wait(2500); // Back to top
        await wait(1000);

        await rec.stopRecording('01-opening.mp4');

        // =========================================================================
        // Clip 2: 02-catalogue.mp4 (12-14s)
        // =========================================================================
        console.log('\n>>> [2/9] Recording 02-catalogue.mp4...');
        await rec.navigate(`${BASE_URL}/library.php`);
        await wait(1000);

        await rec.startRecording('02-catalogue.mp4', 1920, 1080);
        await wait(2000);

        // Smooth scroll through catalogue
        await rec.eval('window.scrollBy({ top: 400, behavior: "smooth" })');
        await wait(2500);

        // Scroll back to search
        await rec.eval('window.scrollTo({ top: 0, behavior: "smooth" })');
        await wait(1200);

        // Simulate typing into search bar
        await rec.eval(`
            const input = document.querySelector('input[type="search"], input[name="q"], #search-input, .search-input');
            if (input) {
                input.focus();
                input.value = 'Coldplay';
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        `);
        await wait(3000);

        // Clear search
        await rec.eval(`
            const input = document.querySelector('input[type="search"], input[name="q"], #search-input, .search-input');
            if (input) {
                input.value = '';
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        `);
        await wait(2000);
        await rec.eval('window.scrollBy({ top: 250, behavior: "smooth" })');
        await wait(2000);

        await rec.stopRecording('02-catalogue.mp4');

        // =========================================================================
        // Clip 3: 03-discovery.mp4 (10-12s)
        // =========================================================================
        console.log('\n>>> [3/9] Recording 03-discovery.mp4...');
        await rec.navigate(`${BASE_URL}/discovery.php`);
        await wait(1000);

        await rec.startRecording('03-discovery.mp4', 1920, 1080);
        await wait(2000);

        // Highlight discovery filter tags or buttons
        await rec.eval(`
            const pills = document.querySelectorAll('.tag, .filter-chip, .badge, .btn-outline');
            if (pills.length > 2) {
                pills[1].classList.add('active', 'hover');
            }
        `);
        await wait(1500);

        await rec.eval('window.scrollBy({ top: 350, behavior: "smooth" })');
        await wait(3000);

        await rec.eval('window.scrollBy({ top: 350, behavior: "smooth" })');
        await wait(2500);

        await rec.eval('window.scrollTo({ top: 0, behavior: "smooth" })');
        await wait(1500);

        await rec.stopRecording('03-discovery.mp4');

        // =========================================================================
        // Clip 4: 04-audition-scoring.mp4 (12-14s)
        // =========================================================================
        console.log('\n>>> [4/9] Recording 04-audition-scoring.mp4...');
        await rec.navigate(`${BASE_URL}/auditions.php`);
        await wait(1000);

        await rec.startRecording('04-audition-scoring.mp4', 1920, 1080);
        await wait(2000);

        // Interactive criteria scoring slider manipulation
        await rec.eval(`
            const sliders = document.querySelectorAll('input[type="range"]');
            if (sliders.length >= 3) {
                sliders[0].value = 9;
                sliders[0].dispatchEvent(new Event('input', { bubbles: true }));
                sliders[1].value = 8.5;
                sliders[1].dispatchEvent(new Event('input', { bubbles: true }));
            }
        `);
        await wait(2500);

        await rec.eval('window.scrollBy({ top: 400, behavior: "smooth" })');
        await wait(3500);

        await rec.eval('window.scrollBy({ top: 400, behavior: "smooth" })');
        await wait(3000);

        await rec.eval('window.scrollTo({ top: 0, behavior: "smooth" })');
        await wait(1500);

        await rec.stopRecording('04-audition-scoring.mp4');

        // =========================================================================
        // Clip 5: 05-tournament.mp4 (12-14s)
        // =========================================================================
        console.log('\n>>> [5/9] Recording 05-tournament.mp4...');
        await rec.navigate(`${BASE_URL}/tournament.php`);
        await wait(1000);

        await rec.startRecording('05-tournament.mp4', 1920, 1080);
        await wait(2500);

        await rec.eval('window.scrollBy({ top: 350, behavior: "smooth" })');
        await wait(3500);

        await rec.eval('window.scrollBy({ top: 350, behavior: "smooth" })');
        await wait(3500);

        await rec.eval('window.scrollTo({ top: 0, behavior: "smooth" })');
        await wait(2000);

        await rec.stopRecording('05-tournament.mp4');

        // =========================================================================
        // Clip 6: 06-rankings-results.mp4 (12-14s)
        // =========================================================================
        console.log('\n>>> [6/9] Recording 06-rankings-results.mp4...');
        await rec.navigate(`${BASE_URL}/results.php`);
        await wait(1000);

        await rec.startRecording('06-rankings-results.mp4', 1920, 1080);
        await wait(2500); // Podium / top cards

        await rec.eval('window.scrollBy({ top: 450, behavior: "smooth" })');
        await wait(3500); // Leaderboard table

        await rec.eval('window.scrollBy({ top: 450, behavior: "smooth" })');
        await wait(3500); // Qualifications / Export

        await rec.eval('window.scrollTo({ top: 0, behavior: "smooth" })');
        await wait(2000);

        await rec.stopRecording('06-rankings-results.mp4');

        // =========================================================================
        // Clip 7: 07-authentication-multiuser.mp4 (12-14s)
        // =========================================================================
        console.log('\n>>> [7/9] Recording 07-authentication-multiuser.mp4...');
        // Clear session cookie temporarily to display public login page
        await rec.send('Network.deleteCookies', { name: 'AFM2_SESSID', domain: '127.0.0.1' });
        await rec.navigate(`${BASE_URL}/login.php`);
        await wait(800);

        await rec.startRecording('07-authentication-multiuser.mp4', 1920, 1080);
        await wait(4000); // Show Google OIDC & WebAuthn buttons

        // Re-authenticate and transition to Account Settings
        await rec.send('Network.setCookie', {
            name: 'AFM2_SESSID',
            value: 'showcase-demo-session-12345',
            domain: '127.0.0.1',
            path: '/'
        });
        await rec.navigate(`${BASE_URL}/account.php`);
        await wait(1200);

        await wait(2500);
        await rec.eval('window.scrollBy({ top: 350, behavior: "smooth" })');
        await wait(3500);

        await rec.stopRecording('07-authentication-multiuser.mp4');

        // =========================================================================
        // Clip 8: 08-responsive-layout.mp4 (10-12s)
        // =========================================================================
        console.log('\n>>> [8/9] Recording 08-responsive-layout.mp4...');
        await rec.setViewport(390, 844, true); // Mobile iPhone viewport
        await rec.navigate(`${BASE_URL}/library.php`);
        await wait(1000);

        await rec.startRecording('08-responsive-layout.mp4', 390, 844);
        await wait(2000);

        await rec.eval('window.scrollBy({ top: 500, behavior: "smooth" })');
        await wait(2500);

        await rec.eval('window.scrollBy({ top: 600, behavior: "smooth" })');
        await wait(2500);

        await rec.eval('window.scrollTo({ top: 0, behavior: "smooth" })');
        await wait(2000);

        await rec.stopRecording('08-responsive-layout.mp4');

        // =========================================================================
        // Clip 9: 09-closing.mp4 (6-8s)
        // =========================================================================
        console.log('\n>>> [9/9] Recording 09-closing.mp4...');
        await rec.setViewport(1920, 1080, false);
        await rec.navigate(`${BASE_URL}/index.php`);
        await wait(1000);

        // Center on Hero logo / title card
        await rec.eval('window.scrollTo({ top: 0, behavior: "instant" })');
        await wait(500);

        await rec.startRecording('09-closing.mp4', 1920, 1080);
        await wait(6000); // Pristine static hold for outro fade

        await rec.stopRecording('09-closing.mp4');

        console.log('\n====================================================');
        console.log(' ALL 9 SHOWCASE CLIPS RECORDED SUCCESSFULLY!         ');
        console.log('====================================================');

    } finally {
        await rec.closePage();
        await rec.stopChrome();
    }
}

recordShowcase().catch(err => {
    console.error('Fatal recording error:', err);
    process.exit(1);
});
