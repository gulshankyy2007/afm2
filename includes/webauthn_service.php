<?php
/**
 * AFM#2 - WebAuthn & FIDO2 Passkey Service
 * Pure PHP 8 implementation with zero third-party Composer dependencies.
 * Compatible with InfinityFree shared hosting and standard OpenSSL.
 */

if (!defined('AFM_APP')) {
    require_once dirname(__DIR__) . '/config/config.php';
}
require_once dirname(__DIR__) . '/config/db.php';
require_once __DIR__ . '/auth.php';

if (!function_exists('mb_substr')) {
    function mb_substr(string $str, int $start, ?int $length = null, ?string $encoding = null): string {
        return $length === null ? substr($str, $start) : substr($str, $start, $length);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $str, ?string $encoding = null): int {
        return strlen($str);
    }
}

class WebAuthnService {

    /**
     * Base64URL encoding (RFC 4648)
     */
    public static function base64url_encode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64URL decoding (RFC 4648)
     */
    public static function base64url_decode(string $data): string {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }

    /**
     * Get Relying Party ID (domain name)
     */
    public static function get_rp_id(): string {
        $envRpId = env('WEBAUTHN_RP_ID');
        if (!empty($envRpId)) {
            return trim($envRpId);
        }

        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        // Strip port number if present
        if (strpos($host, ':') !== false) {
            $host = explode(':', $host)[0];
        }
        return $host;
    }

    /**
     * Get expected Relying Party Origin (scheme://host:port)
     */
    public static function get_expected_origin(): string {
        $configuredOrigin = env('WEBAUTHN_ORIGIN');
        if (!empty($configuredOrigin)) {
            return rtrim($configuredOrigin, '/');
        }

        $scheme = is_https_request() ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        return $scheme . $host;
    }

    /**
     * Minimal CBOR decoder for WebAuthn attestation objects and COSE public keys
     */
    public static function decode_cbor(string $data, int &$offset = 0) {
        if ($offset >= strlen($data)) {
            throw new InvalidArgumentException("Unexpected end of CBOR stream");
        }

        $byte = ord($data[$offset++]);
        $major = $byte >> 5;
        $val = $byte & 0x1f;

        if ($val < 24) {
            $length = $val;
        } elseif ($val === 24) {
            if ($offset >= strlen($data)) throw new InvalidArgumentException("Truncated CBOR length");
            $length = ord($data[$offset++]);
        } elseif ($val === 25) {
            if ($offset + 2 > strlen($data)) throw new InvalidArgumentException("Truncated CBOR length");
            $length = unpack("n", substr($data, $offset, 2))[1];
            $offset += 2;
        } elseif ($val === 26) {
            if ($offset + 4 > strlen($data)) throw new InvalidArgumentException("Truncated CBOR length");
            $length = unpack("N", substr($data, $offset, 4))[1];
            $offset += 4;
        } elseif ($val === 27) {
            if ($offset + 8 > strlen($data)) throw new InvalidArgumentException("Truncated CBOR length");
            $length = unpack("J", substr($data, $offset, 8))[1];
            $offset += 8;
        } else {
            throw new InvalidArgumentException("Unsupported CBOR length value: {$val}");
        }

        switch ($major) {
            case 0: // unsigned integer
                return $length;
            case 1: // negative integer
                return -1 - $length;
            case 2: // byte string
                if ($offset + $length > strlen($data)) throw new InvalidArgumentException("Truncated CBOR byte string");
                $str = substr($data, $offset, $length);
                $offset += $length;
                return $str;
            case 3: // text string
                if ($offset + $length > strlen($data)) throw new InvalidArgumentException("Truncated CBOR text string");
                $str = substr($data, $offset, $length);
                $offset += $length;
                return $str;
            case 4: // array
                $arr = [];
                for ($i = 0; $i < $length; $i++) {
                    $arr[] = self::decode_cbor($data, $offset);
                }
                return $arr;
            case 5: // map
                $map = [];
                for ($i = 0; $i < $length; $i++) {
                    $k = self::decode_cbor($data, $offset);
                    $v = self::decode_cbor($data, $offset);
                    $map[$k] = $v;
                }
                return $map;
            case 7: // simple types
                if ($val === 20) return false;
                if ($val === 21) return true;
                if ($val === 22) return null;
                return $val;
            default:
                throw new InvalidArgumentException("Unsupported CBOR major type: {$major}");
        }
    }

    /**
     * Convert COSE Key (CBOR map) into standard PEM format for OpenSSL
     */
    public static function cose_to_pem(array $cose): string {
        $kty = $cose[1] ?? null; // 2 = EC2, 3 = RSA

        if ($kty === 2) {
            // EC2 (P-256)
            $crv = $cose[-1] ?? null; // 1 = P-256
            $x = $cose[-2] ?? null;
            $y = $cose[-3] ?? null;

            if ($crv !== 1 || strlen($x) !== 32 || strlen($y) !== 32) {
                throw new InvalidArgumentException("Invalid or unsupported EC2 COSE parameters (must be P-256)");
            }

            // SubjectPublicKeyInfo for P-256: 3059301306072a8648ce3d020106082a8648ce3d03010703420004 . x . y
            $header = hex2bin("3059301306072a8648ce3d020106082a8648ce3d03010703420004");
            $der = $header . $x . $y;
            return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        } elseif ($kty === 3) {
            // RSA (RS256)
            $n = $cose[-1] ?? null;
            $e = $cose[-2] ?? null;

            if (empty($n) || empty($e)) {
                throw new InvalidArgumentException("Missing RSA modulus or exponent in COSE key");
            }

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

        throw new InvalidArgumentException("Unsupported COSE key type (kty: " . var_export($kty, true) . ")");
    }

    /**
     * Generate registration options for navigator.credentials.create()
     */
    public static function generate_registration_options(int $userId, string $username): array {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $challenge = random_bytes(32);
        $userHandle = hash('sha256', "afm2_user_{$userId}_" . APP_SECRET);

        $_SESSION['afm_webauthn_reg'] = [
            'challenge'   => self::base64url_encode($challenge),
            'user_id'     => $userId,
            'user_handle' => $userHandle,
            'created_at'  => time(),
        ];

        // Fetch existing credentials for this user to exclude re-registering the same key
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("SELECT `credential_id` FROM `user_passkeys` WHERE `user_id` = :uid");
        $stmt->execute([':uid' => $userId]);
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $excludeCredentials = [];
        foreach ($existing as $credId) {
            $excludeCredentials[] = [
                'type' => 'public-key',
                'id'   => $credId,
            ];
        }

        return [
            'rp' => [
                'name' => 'AFM#2 — Musical Finale',
                'id'   => self::get_rp_id(),
            ],
            'user' => [
                'id'          => self::base64url_encode($userHandle),
                'name'        => $username,
                'displayName' => $username,
            ],
            'challenge'              => self::base64url_encode($challenge),
            'pubKeyCredParams'       => [
                ['type' => 'public-key', 'alg' => -7],   // ES256 (ECDSA P-256)
                ['type' => 'public-key', 'alg' => -257], // RS256 (RSA 2048)
            ],
            'timeout'                => 60000,
            'attestation'            => 'none',
            'authenticatorSelection' => [
                'residentKey'      => 'preferred',
                'requireResidentKey' => false,
                'userVerification' => 'preferred',
            ],
            'excludeCredentials'     => $excludeCredentials,
        ];
    }

    /**
     * Verify registration ceremony response from navigator.credentials.create()
     */
    public static function verify_registration_response(int $userId, array $data, string $deviceName = 'Passkey'): array {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $sessionData = $_SESSION['afm_webauthn_reg'] ?? null;
        if (!$sessionData || empty($sessionData['challenge'])) {
            return ['success' => false, 'error' => 'WebAuthn registration session expired or missing challenge.'];
        }

        if (time() - (int)($sessionData['created_at'] ?? 0) > 300) {
            unset($_SESSION['afm_webauthn_reg']);
            return ['success' => false, 'error' => 'Registration challenge timed out. Please try again.'];
        }

        if ((int)$sessionData['user_id'] !== $userId) {
            unset($_SESSION['afm_webauthn_reg']);
            return ['success' => false, 'error' => 'Authenticated user does not match registration session.'];
        }

        $rawClientData = self::base64url_decode($data['clientDataJSON'] ?? '');
        $clientData = json_decode($rawClientData, true);
        if (!$clientData || !is_array($clientData)) {
            return ['success' => false, 'error' => 'Invalid clientDataJSON in registration response.'];
        }

        // Verify ceremony type
        if (($clientData['type'] ?? '') !== 'webauthn.create') {
            return ['success' => false, 'error' => 'Invalid WebAuthn ceremony type (expected webauthn.create).'];
        }

        // Verify challenge
        if (!hash_equals($sessionData['challenge'], $clientData['challenge'] ?? '')) {
            return ['success' => false, 'error' => 'WebAuthn challenge mismatch.'];
        }

        // Verify origin (tolerate localhost / 127.0.0.1 variation in dev)
        $expectedOrigin = self::get_expected_origin();
        $submittedOrigin = rtrim($clientData['origin'] ?? '', '/');
        $originValid = hash_equals($expectedOrigin, $submittedOrigin);
        if (!$originValid && APP_ENV === 'development') {
            // In development, tolerate localhost vs 127.0.0.1
            $expectedHost = parse_url($expectedOrigin, PHP_URL_HOST);
            $submittedHost = parse_url($submittedOrigin, PHP_URL_HOST);
            if (in_array($expectedHost, ['localhost', '127.0.0.1']) && in_array($submittedHost, ['localhost', '127.0.0.1'])) {
                $originValid = true;
            }
        }
        if (!$originValid) {
            return ['success' => false, 'error' => "WebAuthn origin mismatch. Expected {$expectedOrigin}, got {$submittedOrigin}."];
        }

        // Decode attestationObject
        $rawAttestation = self::base64url_decode($data['attestationObject'] ?? '');
        try {
            $attestation = self::decode_cbor($rawAttestation);
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Failed to parse attestationObject CBOR: ' . $e->getMessage()];
        }

        $authData = $attestation['authData'] ?? '';
        if (strlen($authData) < 37) {
            return ['success' => false, 'error' => 'authData in attestationObject is truncated.'];
        }

        // Verify RP ID hash
        $rpIdHash = substr($authData, 0, 32);
        $expectedRpIdHash = hash('sha256', self::get_rp_id(), true);
        if (!hash_equals($expectedRpIdHash, $rpIdHash)) {
            return ['success' => false, 'error' => 'Relying party ID hash mismatch.'];
        }

        // Flags byte (byte 32)
        $flags = ord($authData[32]);
        $up = ($flags & 0x01) !== 0; // User Present
        $at = ($flags & 0x40) !== 0; // Attested Credential Data

        if (!$up) {
            return ['success' => false, 'error' => 'User presence (UP) flag was not set by authenticator.'];
        }
        if (!$at) {
            return ['success' => false, 'error' => 'Attested credential data (AT) flag was not set.'];
        }

        // Parse Attested Credential Data (starts at offset 37)
        $offset = 37;
        $aaguid = bin2hex(substr($authData, $offset, 16));
        $offset += 16;

        $credIdLen = unpack("n", substr($authData, $offset, 2))[1];
        $offset += 2;

        $credIdBytes = substr($authData, $offset, $credIdLen);
        $offset += $credIdLen;
        $credentialId = self::base64url_encode($credIdBytes);

        // Decode credentialPublicKey from CBOR
        $cborKeyData = substr($authData, $offset);
        $keyOffset = 0;
        try {
            $coseKey = self::decode_cbor($cborKeyData, $keyOffset);
            $pem = self::cose_to_pem($coseKey);
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Failed to decode credential public key: ' . $e->getMessage()];
        }

        // Sign count (bytes 33-36)
        $signCount = unpack("N", substr($authData, 33, 4))[1];

        // Store credential in database
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare("
                INSERT INTO `user_passkeys` 
                    (`user_id`, `credential_id`, `public_key_pem`, `sign_count`, `aaguid`, `device_name`, `user_handle`, `transports`, `created_at`, `last_used_at`)
                VALUES 
                    (:uid, :cid, :pem, :sc, :aaguid, :name, :handle, :transports, NOW(), NOW())
            ");
            $cleanName = mb_substr(trim($deviceName), 0, 100, 'UTF-8') ?: 'Passkey';
            $transportsJson = !empty($data['transports']) ? json_encode($data['transports']) : null;

            $stmt->execute([
                ':uid'        => $userId,
                ':cid'        => $credentialId,
                ':pem'        => $pem,
                ':sc'         => $signCount,
                ':aaguid'     => $aaguid,
                ':name'       => $cleanName,
                ':handle'     => $sessionData['user_handle'],
                ':transports' => $transportsJson,
            ]);

            $passkeyId = (int)$pdo->lastInsertId();
            unset($_SESSION['afm_webauthn_reg']);

            return [
                'success'       => true,
                'passkey_id'    => $passkeyId,
                'device_name'   => $cleanName,
                'credential_id' => $credentialId,
            ];
        } catch (PDOException $e) {
            error_log("AFM2 WebAuthn registration storage error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to save passkey credential. It may already be registered.'];
        }
    }

    /**
     * Generate authentication options for navigator.credentials.get()
     */
    public static function generate_authentication_options(?string $username = null): array {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $challenge = random_bytes(32);
        $_SESSION['afm_webauthn_auth'] = [
            'challenge'  => self::base64url_encode($challenge),
            'created_at' => time(),
        ];

        $allowCredentials = [];
        if (!empty($username)) {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare("
                SELECT p.`credential_id`, p.`transports` 
                FROM `user_passkeys` p
                JOIN `app_users` u ON u.`user_id` = p.`user_id`
                WHERE u.`username` = :uname AND u.`is_active` = 1
            ");
            $stmt->execute([':uname' => trim($username)]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $r) {
                $item = [
                    'type' => 'public-key',
                    'id'   => $r['credential_id'],
                ];
                if (!empty($r['transports'])) {
                    $trans = json_decode($r['transports'], true);
                    if (is_array($trans)) {
                        $item['transports'] = $trans;
                    }
                }
                $allowCredentials[] = $item;
            }
        }

        return [
            'challenge'        => self::base64url_encode($challenge),
            'rpId'             => self::get_rp_id(),
            'timeout'          => 60000,
            'userVerification' => 'preferred',
            'allowCredentials' => $allowCredentials,
        ];
    }

    /**
     * Verify authentication ceremony response from navigator.credentials.get()
     */
    public static function verify_authentication_response(array $data): array {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $sessionData = $_SESSION['afm_webauthn_auth'] ?? null;
        if (!$sessionData || empty($sessionData['challenge'])) {
            return ['success' => false, 'error' => 'Authentication challenge expired or missing.'];
        }

        if (time() - (int)($sessionData['created_at'] ?? 0) > 300) {
            unset($_SESSION['afm_webauthn_auth']);
            return ['success' => false, 'error' => 'Authentication challenge timed out. Please try again.'];
        }

        $credentialId = trim($data['id'] ?? '');
        if (empty($credentialId)) {
            return ['success' => false, 'error' => 'Missing credential ID in WebAuthn response.'];
        }

        $rawClientData = self::base64url_decode($data['clientDataJSON'] ?? '');
        $clientData = json_decode($rawClientData, true);
        if (!$clientData || !is_array($clientData)) {
            return ['success' => false, 'error' => 'Invalid clientDataJSON in authentication response.'];
        }

        if (($clientData['type'] ?? '') !== 'webauthn.get') {
            return ['success' => false, 'error' => 'Invalid WebAuthn ceremony type (expected webauthn.get).'];
        }

        if (!hash_equals($sessionData['challenge'], $clientData['challenge'] ?? '')) {
            return ['success' => false, 'error' => 'WebAuthn challenge mismatch.'];
        }

        // Verify origin
        $expectedOrigin = self::get_expected_origin();
        $submittedOrigin = rtrim($clientData['origin'] ?? '', '/');
        $originValid = hash_equals($expectedOrigin, $submittedOrigin);
        if (!$originValid && APP_ENV === 'development') {
            $expectedHost = parse_url($expectedOrigin, PHP_URL_HOST);
            $submittedHost = parse_url($submittedOrigin, PHP_URL_HOST);
            if (in_array($expectedHost, ['localhost', '127.0.0.1']) && in_array($submittedHost, ['localhost', '127.0.0.1'])) {
                $originValid = true;
            }
        }
        if (!$originValid) {
            return ['success' => false, 'error' => "WebAuthn origin mismatch. Expected {$expectedOrigin}, got {$submittedOrigin}."];
        }

        // Fetch credential from database
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("
            SELECT p.`passkey_id`, p.`user_id`, p.`public_key_pem`, p.`sign_count`, u.`username`, u.`is_active`
            FROM `user_passkeys` p
            JOIN `app_users` u ON u.`user_id` = p.`user_id`
            WHERE p.`credential_id` = :cid
            LIMIT 1
        ");
        $stmt->execute([':cid' => $credentialId]);
        $cred = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cred) {
            return ['success' => false, 'error' => 'Passkey credential not recognized for this system.'];
        }

        if ((int)$cred['is_active'] !== 1) {
            return ['success' => false, 'error' => 'This account has been disabled. Please contact the administrator.'];
        }

        // Parse authenticatorData
        $rawAuthData = self::base64url_decode($data['authenticatorData'] ?? '');
        if (strlen($rawAuthData) < 37) {
            return ['success' => false, 'error' => 'authenticatorData is truncated.'];
        }

        // Verify RP ID hash
        $rpIdHash = substr($rawAuthData, 0, 32);
        $expectedRpIdHash = hash('sha256', self::get_rp_id(), true);
        if (!hash_equals($expectedRpIdHash, $rpIdHash)) {
            return ['success' => false, 'error' => 'Relying party ID hash mismatch in authenticator data.'];
        }

        // Verify User Present (UP) bit
        $flags = ord($rawAuthData[32]);
        if (($flags & 0x01) === 0) {
            return ['success' => false, 'error' => 'User presence (UP) bit not asserted by authenticator.'];
        }

        // Verify cryptographic signature
        $rawSig = self::base64url_decode($data['signature'] ?? '');
        $clientDataHash = hash('sha256', $rawClientData, true);
        $signedData = $rawAuthData . $clientDataHash;

        $verifyResult = openssl_verify($signedData, $rawSig, $cred['public_key_pem'], OPENSSL_ALGO_SHA256);
        if ($verifyResult !== 1) {
            return ['success' => false, 'error' => 'Cryptographic signature verification failed for passkey.'];
        }

        // Sign count check (replay & clone prevention)
        $newSignCount = unpack("N", substr($rawAuthData, 33, 4))[1];
        $previousSignCount = (int)$cred['sign_count'];

        // If both counters are non-zero, enforce strictly increasing counter
        if ($newSignCount > 0 && $previousSignCount > 0 && $newSignCount <= $previousSignCount) {
            error_log("[SECURITY ALERT] WebAuthn replay or cloned authenticator detected for user {$cred['username']} (sign_count {$newSignCount} <= {$previousSignCount})");
            return ['success' => false, 'error' => 'Potential authenticator clone or replay detected. Access denied.'];
        }

        // Update credential sign_count and last_used_at
        $upd = $pdo->prepare("UPDATE `user_passkeys` SET `sign_count` = :sc, `last_used_at` = NOW() WHERE `passkey_id` = :pid");
        $upd->execute([':sc' => $newSignCount, ':pid' => $cred['passkey_id']]);

        unset($_SESSION['afm_webauthn_auth']);

        // Establish canonical user session
        $sessResult = establish_authenticated_session((int)$cred['user_id']);
        if (!$sessResult['success']) {
            return $sessResult;
        }

        return [
            'success'  => true,
            'user_id'  => (int)$cred['user_id'],
            'username' => $cred['username'],
        ];
    }

    /**
     * List registered passkeys for a user
     */
    public static function list_user_passkeys(int $userId): array {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("
            SELECT `passkey_id`, `device_name`, `created_at`, `last_used_at`
            FROM `user_passkeys`
            WHERE `user_id` = :uid
            ORDER BY `created_at` DESC
        ");
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Rename a passkey
     */
    public static function rename_passkey(int $userId, int $passkeyId, string $newName): array {
        $clean = mb_substr(trim($newName), 0, 100, 'UTF-8');
        if (empty($clean)) {
            return ['success' => false, 'error' => 'Passkey name cannot be empty.'];
        }

        $pdo = get_db_connection();
        $stmt = $pdo->prepare("UPDATE `user_passkeys` SET `device_name` = :name WHERE `passkey_id` = :pid AND `user_id` = :uid");
        $stmt->execute([':name' => $clean, ':pid' => $passkeyId, ':uid' => $userId]);

        return ['success' => true];
    }

    /**
     * Delete a passkey (with last usable authentication method check)
     */
    public static function delete_passkey(int $userId, int $passkeyId): array {
        if (!can_remove_auth_method($userId, 'passkey')) {
            return ['success' => false, 'error' => 'Cannot remove your last usable authentication method. Please add another method or set a password first.'];
        }

        $pdo = get_db_connection();
        $stmt = $pdo->prepare("DELETE FROM `user_passkeys` WHERE `passkey_id` = :pid AND `user_id` = :uid");
        $stmt->execute([':pid' => $passkeyId, ':uid' => $userId]);

        if ($stmt->rowCount() > 0) {
            return ['success' => true];
        }
        return ['success' => false, 'error' => 'Passkey not found or already removed.'];
    }
}
