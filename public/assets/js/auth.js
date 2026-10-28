/**
 * AFM#2 - WebAuthn & Client Authentication Helpers
 * Handles browser FIDO2 / Passkey registration and assertion ceremonies.
 * Pure Vanilla JavaScript; zero external client-side dependencies.
 */

(function() {
    'use strict';

    // ==================== Buffer & Base64URL Converters ====================

    function base64urlToBuffer(base64url) {
        if (!base64url) return new ArrayBuffer(0);
        let base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
        const pad = base64.length % 4;
        if (pad) {
            base64 += '='.repeat(4 - pad);
        }
        const binary = atob(base64);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes.buffer;
    }

    function bufferToBase64url(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content');
        const input = document.querySelector('input[name="csrf_token"]');
        return input ? input.value : '';
    }

    // ==================== Passkey Registration Flow (account.php) ====================

    const btnRegister = document.getElementById('btnRegisterPasskey');
    const regStatus = document.getElementById('passkeyStatusMsg');

    if (btnRegister) {
        btnRegister.addEventListener('click', async function() {
            if (!window.PublicKeyCredential) {
                alert('WebAuthn / Passkeys are not supported by this browser or connection. HTTPS or localhost is required.');
                return;
            }

            const deviceName = prompt('Enter a friendly name for this passkey (e.g. MacBook Touch ID, iPhone, YubiKey):', 'My Passkey');
            if (deviceName === null) return; // cancelled

            btnRegister.disabled = true;
            btnRegister.textContent = 'Preparing ceremony...';
            if (regStatus) {
                regStatus.style.display = 'block';
                regStatus.style.background = 'rgba(56,189,248,0.12)';
                regStatus.style.border = '1px solid rgba(56,189,248,0.3)';
                regStatus.style.color = '#7dd3fc';
                regStatus.textContent = 'Touch your biometric sensor or insert security key when prompted...';
            }

            try {
                // 1. Fetch challenge & options
                const optRes = await fetch('api.php?action=webauthn_register_options');
                const optData = await optRes.json();

                if (!optData.success || !optData.options) {
                    throw new Error(optData.error || 'Failed to retrieve registration options from server.');
                }

                const options = optData.options;
                options.challenge = base64urlToBuffer(options.challenge);
                options.user.id = base64urlToBuffer(options.user.id);

                if (options.excludeCredentials && Array.isArray(options.excludeCredentials)) {
                    options.excludeCredentials = options.excludeCredentials.map(cred => ({
                        ...cred,
                        id: base64urlToBuffer(cred.id)
                    }));
                }

                // 2. Invoke browser authenticator
                const credential = await navigator.credentials.create({ publicKey: options });

                if (!credential) {
                    throw new Error('Authenticator did not return a credential.');
                }

                // 3. Serialize credential for verification
                const response = credential.response;
                const transports = typeof response.getTransports === 'function' ? response.getTransports() : null;

                const payload = {
                    device_name: deviceName || 'Passkey',
                    csrf_token: getCsrfToken(),
                    credential: {
                        id: credential.id,
                        rawId: bufferToBase64url(credential.rawId),
                        type: credential.type,
                        clientDataJSON: bufferToBase64url(response.clientDataJSON),
                        attestationObject: bufferToBase64url(response.attestationObject),
                        transports: transports
                    }
                };

                // 4. Send to server for verification and storage
                const verifyRes = await fetch('api.php?action=webauthn_register_verify', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken()
                    },
                    body: JSON.stringify(payload)
                });

                const verifyData = await verifyRes.json();

                if (verifyData.success) {
                    if (regStatus) {
                        regStatus.style.background = 'rgba(34,197,94,0.12)';
                        regStatus.style.border = '1px solid rgba(34,197,94,0.3)';
                        regStatus.style.color = '#86efac';
                        regStatus.textContent = '🎉 Passkey successfully registered! Refreshing page...';
                    }
                    setTimeout(() => window.location.reload(), 1200);
                } else {
                    throw new Error(verifyData.error || 'Passkey verification failed.');
                }

            } catch (err) {
                console.error('WebAuthn Registration Error:', err);
                if (regStatus) {
                    regStatus.style.background = 'rgba(239,68,68,0.12)';
                    regStatus.style.border = '1px solid rgba(239,68,68,0.3)';
                    regStatus.style.color = '#fca5a5';
                    regStatus.textContent = 'Registration failed: ' + (err.message || 'Ceremony was cancelled or not supported.');
                } else {
                    alert('Registration failed: ' + err.message);
                }
            } finally {
                btnRegister.disabled = false;
                btnRegister.textContent = '+ Register New Passkey';
            }
        });
    }

    // ==================== Passkey Sign-In Flow (login.php) ====================

    const btnPasskeyLogin = document.getElementById('btnPasskeyLogin');
    const loginMsg = document.getElementById('passkeyLoginMsg');

    if (btnPasskeyLogin) {
        btnPasskeyLogin.addEventListener('click', async function() {
            if (!window.PublicKeyCredential) {
                alert('WebAuthn / Passkeys are not supported by this browser. Please use your password to sign in.');
                return;
            }

            btnPasskeyLogin.disabled = true;
            btnPasskeyLogin.textContent = 'Waiting for passkey...';
            if (loginMsg) {
                loginMsg.style.display = 'block';
                loginMsg.style.background = 'rgba(56,189,248,0.12)';
                loginMsg.style.border = '1px solid rgba(56,189,248,0.3)';
                loginMsg.style.color = '#7dd3fc';
                loginMsg.textContent = 'Please touch your biometric sensor or confirm your passkey prompt...';
            }

            try {
                // 1. Get authentication options from server
                const usernameInput = document.getElementById('username');
                const username = usernameInput ? usernameInput.value.trim() : '';

                const optUrl = 'api.php?action=webauthn_auth_options' + (username ? '&username=' + encodeURIComponent(username) : '');
                const optRes = await fetch(optUrl);
                const optData = await optRes.json();

                if (!optData.success || !optData.options) {
                    throw new Error(optData.error || 'Failed to retrieve authentication challenge from server.');
                }

                const options = optData.options;
                options.challenge = base64urlToBuffer(options.challenge);

                if (options.allowCredentials && Array.isArray(options.allowCredentials)) {
                    options.allowCredentials = options.allowCredentials.map(cred => ({
                        ...cred,
                        id: base64urlToBuffer(cred.id)
                    }));
                }

                // 2. Invoke browser authenticator
                const assertion = await navigator.credentials.get({ publicKey: options });

                if (!assertion) {
                    throw new Error('Authenticator did not return an assertion.');
                }

                // 3. Serialize assertion response
                const response = assertion.response;
                const payload = {
                    credential: {
                        id: assertion.id,
                        rawId: bufferToBase64url(assertion.rawId),
                        type: assertion.type,
                        clientDataJSON: bufferToBase64url(response.clientDataJSON),
                        authenticatorData: bufferToBase64url(response.authenticatorData),
                        signature: bufferToBase64url(response.signature),
                        userHandle: response.userHandle ? bufferToBase64url(response.userHandle) : null
                    }
                };

                // 4. Verify on server and establish session
                const verifyRes = await fetch('api.php?action=webauthn_auth_verify', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });

                const verifyData = await verifyRes.json();

                if (verifyData.success) {
                    if (loginMsg) {
                        loginMsg.style.background = 'rgba(34,197,94,0.12)';
                        loginMsg.style.border = '1px solid rgba(34,197,94,0.3)';
                        loginMsg.style.color = '#86efac';
                        loginMsg.textContent = '🎉 Authentication successful! Redirecting to journal...';
                    }
                    window.location.href = 'index.php';
                } else {
                    throw new Error(verifyData.error || 'Passkey verification was rejected by server.');
                }

            } catch (err) {
                console.error('WebAuthn Authentication Error:', err);
                if (loginMsg) {
                    loginMsg.style.background = 'rgba(239,68,68,0.12)';
                    loginMsg.style.border = '1px solid rgba(239,68,68,0.3)';
                    loginMsg.style.color = '#fca5a5';
                    loginMsg.textContent = 'Sign-in failed: ' + (err.message || 'Passkey ceremony was cancelled.');
                }
            } finally {
                btnPasskeyLogin.disabled = false;
                btnPasskeyLogin.innerHTML = '<span style="font-size: 1.1rem;">🛡️</span> Sign in with a Passkey';
            }
        });
    }

})();
