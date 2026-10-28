<?php
/**
 * AFM#2 - Google OAuth 2.0 / OpenID Connect Service
 * Pure PHP 8 implementation with server-side authorization code flow,
 * PKCE, state validation, and cryptographic RS256 ID Token verification.
 * Zero third-party Composer dependencies; works natively on ₹0/month hosting.
 */

if (!defined('AFM_APP')) {
    require_once dirname(__DIR__) . '/config/config.php';
}
require_once dirname(__DIR__) . '/config/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/webauthn_service.php';

class GoogleAuthService {

    /**
     * Check if Google Sign-In is configured and enabled in environment
     */
    public static function is_enabled(): bool {
        $clientId = env('GOOGLE_CLIENT_ID');
        $clientSecret = env('GOOGLE_CLIENT_SECRET');
        return !empty($clientId) && !empty($clientSecret);
    }

    public static function get_client_id(): string {
        return (string)env('GOOGLE_CLIENT_ID', '');
    }

    public static function get_client_secret(): string {
        return (string)env('GOOGLE_CLIENT_SECRET', '');
    }

    /**
     * Canonical Google OAuth Redirect URI
     */
    public static function get_redirect_uri(): string {
        $configured = env('GOOGLE_REDIRECT_URI');
        if (!empty($configured)) {
            return trim($configured);
        }
        return get_base_url() . '/auth_callback.php?provider=google';
    }

    /**
     * Generate Google authorization URL with state, PKCE, and mode tracking
     *
     * @param string $mode 'login', 'link', or 'invite'
     * @param string|null $inviteToken Optional invitation token
     * @return string Google OAuth URL
     */
    public static function generate_auth_url(string $mode = 'login', ?string $inviteToken = null): string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $state = WebAuthnService::base64url_encode(random_bytes(32));
        $codeVerifier = WebAuthnService::base64url_encode(random_bytes(32));
        $codeChallenge = WebAuthnService::base64url_encode(hash('sha256', $codeVerifier, true));

        $_SESSION['afm_google_oauth'] = [
            'state'         => $state,
            'code_verifier' => $codeVerifier,
            'mode'          => $mode,
            'invite_token'  => $inviteToken,
            'created_at'    => time(),
        ];

        $params = [
            'client_id'             => self::get_client_id(),
            'redirect_uri'          => self::get_redirect_uri(),
            'response_type'         => 'code',
            'scope'                 => 'openid email profile',
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
            'prompt'                => 'select_account',
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    /**
     * Convert RSA modulus (n) and exponent (e) to standard PEM format
     */
    public static function rsa_jwk_to_pem(string $nB64, string $eB64): string {
        $n = WebAuthnService::base64url_decode($nB64);
        $e = WebAuthnService::base64url_decode($eB64);

        $derLen = function(int $len): string {
            if ($len < 0x80) return chr($len);
            if ($len <= 0xff) return "\x81" . chr($len);
            return "\x82" . chr($len >> 8) . chr($len & 0xff);
        };
        $derInt = function(string $data) use ($derLen): string {
            if (ord($data[0]) > 0x7f) $data = "\x00" . $data;
            return "\x02" . $derLen(strlen($data)) . $data;
        };

        $mod = $derInt($n);
        $exp = $derInt($e);
        $pkcs1 = "\x30" . $derLen(strlen($mod . $exp)) . $mod . $exp;
        $algorithmIdentifier = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $bitString = "\x03" . $derLen(strlen($pkcs1) + 1) . "\x00" . $pkcs1;
        $spki = "\x30" . $derLen(strlen($algorithmIdentifier . $bitString)) . $algorithmIdentifier . $bitString;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Fetch Google's public JWKS certificates
     */
    public static function fetch_google_jwks(): array {
        static $cachedKeys = null;
        if ($cachedKeys !== null) {
            return $cachedKeys;
        }

        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 5,
                'header'  => "User-Agent: AFM2-Auth/1.0\r\nAccept: application/json\r\n",
            ]
        ]);

        $json = @file_get_contents('https://www.googleapis.com/oauth2/v3/certs', false, $context);
        if (!$json) {
            return [];
        }

        $data = json_decode($json, true);
        $keys = $data['keys'] ?? [];
        $cachedKeys = $keys;
        return $keys;
    }

    /**
     * Parse and cryptographically verify a Google OIDC ID Token
     *
     * @param string $idToken The JWT string
     * @param array|null $overrideKeys Optional JWKS key fixtures for testing without external HTTP
     * @return array{success: bool, claims?: array, error?: string}
     */
    public static function verify_id_token(string $idToken, ?array $overrideKeys = null): array {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return ['success' => false, 'error' => 'Malformed ID token (must have 3 dot-separated segments).'];
        }

        list($headerB64, $payloadB64, $signatureB64) = $parts;

        $header = json_decode(WebAuthnService::base64url_decode($headerB64), true);
        $payload = json_decode(WebAuthnService::base64url_decode($payloadB64), true);

        if (!$header || !$payload) {
            return ['success' => false, 'error' => 'Unable to decode ID token JSON payload.'];
        }

        // 1. Verify Header Algorithm
        if (($header['alg'] ?? '') !== 'RS256') {
            return ['success' => false, 'error' => 'Unsupported ID token signing algorithm (expected RS256).'];
        }

        // 2. Verify Issuer
        $allowedIssuers = ['https://accounts.google.com', 'accounts.google.com'];
        if (!in_array($payload['iss'] ?? '', $allowedIssuers, true)) {
            return ['success' => false, 'error' => 'Invalid ID token issuer.'];
        }

        // 3. Verify Audience
        $clientId = self::get_client_id();
        $aud = $payload['aud'] ?? '';
        if (!empty($clientId) && $aud !== $clientId) {
            return ['success' => false, 'error' => 'ID token audience does not match GOOGLE_CLIENT_ID.'];
        }

        // 4. Verify Expiry with 60s clock skew tolerance
        $now = time();
        $exp = (int)($payload['exp'] ?? 0);
        if ($exp < ($now - 60)) {
            return ['success' => false, 'error' => 'ID token has expired.'];
        }

        // 5. Verify Subject
        $sub = trim($payload['sub'] ?? '');
        if (empty($sub)) {
            return ['success' => false, 'error' => 'Missing subject identifier (sub) in ID token.'];
        }

        // 6. Cryptographic Signature Verification
        $kid = $header['kid'] ?? '';
        $keys = $overrideKeys !== null ? $overrideKeys : self::fetch_google_jwks();

        $matchingKey = null;
        foreach ($keys as $k) {
            if (($k['kid'] ?? '') === $kid) {
                $matchingKey = $k;
                break;
            }
        }

        if (!$matchingKey) {
            return ['success' => false, 'error' => 'No matching signing key found for ID token kid.'];
        }

        try {
            $pem = self::rsa_jwk_to_pem($matchingKey['n'], $matchingKey['e']);
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Failed to assemble RSA public key: ' . $e->getMessage()];
        }

        $signedData = $headerB64 . '.' . $payloadB64;
        $signature = WebAuthnService::base64url_decode($signatureB64);

        $verify = openssl_verify($signedData, $signature, $pem, OPENSSL_ALGO_SHA256);
        if ($verify !== 1) {
            return ['success' => false, 'error' => 'Cryptographic signature verification failed for ID token.'];
        }

        return [
            'success' => true,
            'claims'  => $payload,
        ];
    }

    /**
     * Exchange authorization code for tokens via Google's token endpoint
     */
    public static function exchange_code(string $code, string $codeVerifier): array {
        $postData = http_build_query([
            'code'          => $code,
            'client_id'     => self::get_client_id(),
            'client_secret' => self::get_client_secret(),
            'redirect_uri'  => self::get_redirect_uri(),
            'grant_type'    => 'authorization_code',
            'code_verifier' => $codeVerifier,
        ]);

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'timeout' => 8,
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n"
                           . "User-Agent: AFM2-Auth/1.0\r\n"
                           . "Content-Length: " . strlen($postData) . "\r\n",
                'content' => $postData,
            ]
        ]);

        $response = @file_get_contents('https://oauth2.googleapis.com/token', false, $context);
        if (!$response) {
            return ['success' => false, 'error' => 'Failed to contact Google token endpoint.'];
        }

        $data = json_decode($response, true);
        if (empty($data['id_token'])) {
            $err = $data['error_description'] ?? $data['error'] ?? 'Unknown token exchange error';
            return ['success' => false, 'error' => "Google token exchange failed: {$err}"];
        }

        return [
            'success'      => true,
            'id_token'     => $data['id_token'],
            'access_token' => $data['access_token'] ?? null,
        ];
    }

    /**
     * Handle complete Google OAuth callback
     *
     * @param string $code Authorization code from Google
     * @param string $state State string from query
     * @param array|null $mockClaims Optional pre-verified claims for testing
     * @return array Result of authentication or linking
     */
    public static function handle_callback(string $code, string $state, ?array $mockClaims = null): array {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $sessionData = $_SESSION['afm_google_oauth'] ?? null;
        if (!$sessionData || empty($sessionData['state'])) {
            return ['success' => false, 'error' => 'Google OAuth session expired or missing state. Please try again.'];
        }

        if (time() - (int)($sessionData['created_at'] ?? 0) > 300) {
            unset($_SESSION['afm_google_oauth']);
            return ['success' => false, 'error' => 'Google OAuth session timed out. Please try again.'];
        }

        // Timing-safe state verification
        if (!hash_equals($sessionData['state'], $state)) {
            unset($_SESSION['afm_google_oauth']);
            return ['success' => false, 'error' => 'Security state validation failed (possible CSRF attack).'];
        }

        $mode = $sessionData['mode'] ?? 'login';
        $codeVerifier = $sessionData['code_verifier'] ?? '';
        $inviteToken = $sessionData['invite_token'] ?? null;
        unset($_SESSION['afm_google_oauth']);

        // Token resolution (mock or live exchange)
        if ($mockClaims !== null) {
            $claims = $mockClaims;
        } else {
            $tokenRes = self::exchange_code($code, $codeVerifier);
            if (!$tokenRes['success']) {
                return $tokenRes;
            }

            $verifyRes = self::verify_id_token($tokenRes['id_token']);
            if (!$verifyRes['success']) {
                return $verifyRes;
            }
            $claims = $verifyRes['claims'];
        }

        $sub = trim($claims['sub'] ?? '');
        $email = trim($claims['email'] ?? '');
        $emailVerified = !empty($claims['email_verified']);

        if (empty($sub)) {
            return ['success' => false, 'error' => 'Google identity did not return a valid subject identifier.'];
        }

        $pdo = get_db_connection();

        // ==================== MODE: LINK ====================
        if ($mode === 'link') {
            if (!is_authenticated()) {
                return ['success' => false, 'error' => 'You must be signed in to AFM#2 to link a Google account.'];
            }
            $currentUserId = get_current_user_id();

            // Check if this Google identity is already linked to another user
            $stmt = $pdo->prepare("SELECT `user_id` FROM `user_auth_identities` WHERE `provider` = 'google' AND `provider_subject` = :sub LIMIT 1");
            $stmt->execute([':sub' => $sub]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                if ((int)$existing['user_id'] === $currentUserId) {
                    return ['success' => true, 'action' => 'already_linked', 'message' => 'This Google account is already linked to your profile.'];
                }
                return ['success' => false, 'error' => 'This Google account is already linked to another AFM#2 user.'];
            }

            // Link identity to current user
            $ins = $pdo->prepare("
                INSERT INTO `user_auth_identities` 
                    (`user_id`, `provider`, `provider_subject`, `email`, `email_verified`, `created_at`, `last_used_at`)
                VALUES 
                    (:uid, 'google', :sub, :email, :ev, NOW(), NOW())
            ");
            $ins->execute([
                ':uid'   => $currentUserId,
                ':sub'   => $sub,
                ':email' => $email ?: null,
                ':ev'    => $emailVerified ? 1 : 0,
            ]);

            return ['success' => true, 'action' => 'linked', 'email' => $email];
        }

        // ==================== MODE: INVITE ACCEPTANCE ====================
        if ($mode === 'invite' && !empty($inviteToken)) {
            $invitation = validate_invitation_token($inviteToken);
            if (!$invitation) {
                return ['success' => false, 'error' => 'Invalid or expired invitation link.'];
            }

            // Verify Google email matches invitation email
            if (empty($email) || strcasecmp($email, $invitation['email']) !== 0) {
                return ['success' => false, 'error' => "Google account email ({$email}) does not match the invitation email ({$invitation['email']})."];
            }

            // Check if this Google subject is already linked to someone else
            $stmt = $pdo->prepare("SELECT `user_id` FROM `user_auth_identities` WHERE `provider` = 'google' AND `provider_subject` = :sub LIMIT 1");
            $stmt->execute([':sub' => $sub]);
            if ($stmt->fetch()) {
                return ['success' => false, 'error' => 'This Google account is already linked to another user.'];
            }

            // Create new user for invitation
            $username = explode('@', $email)[0];
            $baseUsername = preg_replace('/[^a-zA-Z0-9_\-]/', '', $username);
            $cleanUsername = $baseUsername ?: 'user_' . substr(bin2hex(random_bytes(4)), 0, 8);

            // Ensure unique username
            $uStmt = $pdo->prepare("SELECT COUNT(*) FROM `app_users` WHERE `username` = :u");
            $uStmt->execute([':u' => $cleanUsername]);
            if ((int)$uStmt->fetchColumn() > 0) {
                $cleanUsername .= '_' . substr(bin2hex(random_bytes(2)), 0, 4);
            }

            $pdo->beginTransaction();
            try {
                // Dummy unusable password hash for accounts provisioned purely via Google
                $dummyHash = '$2y$12$' . bin2hex(random_bytes(16)) . 'OAUTH_ONLY_NO_PASSWORD';
                $uIns = $pdo->prepare("
                    INSERT INTO `app_users` (`username`, `email`, `password_hash`, `role`, `is_active`, `created_at`, `last_login`)
                    VALUES (:u, :email, :hash, :role, 1, NOW(), NOW())
                ");
                $uIns->execute([
                    ':u'     => $cleanUsername,
                    ':email' => $email,
                    ':hash'  => $dummyHash,
                    ':role'  => $invitation['role'] ?? 'user',
                ]);
                $newUserId = (int)$pdo->lastInsertId();

                // Link Google Identity
                $idIns = $pdo->prepare("
                    INSERT INTO `user_auth_identities` 
                        (`user_id`, `provider`, `provider_subject`, `email`, `email_verified`, `created_at`, `last_used_at`)
                    VALUES 
                        (:uid, 'google', :sub, :email, :ev, NOW(), NOW())
                ");
                $idIns->execute([
                    ':uid'   => $newUserId,
                    ':sub'   => $sub,
                    ':email' => $email,
                    ':ev'    => $emailVerified ? 1 : 0,
                ]);

                // Mark invitation used
                $invUpd = $pdo->prepare("UPDATE `user_invitations` SET `used_at` = NOW() WHERE `invitation_id` = :id");
                $invUpd->execute([':id' => $invitation['invitation_id']]);

                $pdo->commit();

                // Establish session
                return establish_authenticated_session($newUserId);
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("AFM2 Google invite acceptance error: " . $e->getMessage());
                return ['success' => false, 'error' => 'Failed to create account from invitation.'];
            }
        }

        // ==================== MODE: LOGIN ====================
        // Look up identity
        $stmt = $pdo->prepare("
            SELECT i.`user_id`, u.`username`, u.`is_active`
            FROM `user_auth_identities` i
            JOIN `app_users` u ON u.`user_id` = i.`user_id`
            WHERE i.`provider` = 'google' AND i.`provider_subject` = :sub
            LIMIT 1
        ");
        $stmt->execute([':sub' => $sub]);
        $identity = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$identity) {
            // STRICT HARD CONSTRAINT: No public self-registration.
            // A valid Google account does NOT create an account automatically.
            return [
                'success' => false,
                'error'   => 'No AFM#2 account is linked to this Google account. Access is restricted to administrator-invited users only.'
            ];
        }

        if ((int)$identity['is_active'] !== 1) {
            return [
                'success' => false,
                'error'   => 'This AFM#2 account has been disabled. Please contact the administrator.'
            ];
        }

        // Update last used timestamp
        $upd = $pdo->prepare("UPDATE `user_auth_identities` SET `last_used_at` = NOW() WHERE `provider` = 'google' AND `provider_subject` = :sub");
        $upd->execute([':sub' => $sub]);

        // Establish canonical user session
        return establish_authenticated_session((int)$identity['user_id']);
    }

    /**
     * Unlink Google identity from user profile
     */
    public static function unlink_google_identity(int $userId): array {
        if (!can_remove_auth_method($userId, 'google')) {
            return ['success' => false, 'error' => 'Cannot unlink Google. It is your only active sign-in method. Please set a password or register a passkey first.'];
        }

        $pdo = get_db_connection();
        $stmt = $pdo->prepare("DELETE FROM `user_auth_identities` WHERE `user_id` = :uid AND `provider` = 'google'");
        $stmt->execute([':uid' => $userId]);

        if ($stmt->rowCount() > 0) {
            return ['success' => true];
        }
        return ['success' => false, 'error' => 'No linked Google account found.'];
    }
}
