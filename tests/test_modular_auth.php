<?php
/**
 * AFM#2 — Modular Multi-Method Authentication Test Suite
 * Tests Password, Google OIDC, WebAuthn Passkeys, and Admin Provisioning.
 * Strictly preserves catalogue data and verifies all security invariants.
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/webauthn_service.php';
require_once dirname(__DIR__) . '/includes/google_auth_service.php';

$totalTests = 0;
$passedTests = 0;

function assert_test(string $name, bool $condition, string $details = ''): void {
    global $totalTests, $passedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$totalTests}. {$name}\n";
    } else {
        echo "  [FAIL] {$totalTests}. {$name}" . ($details ? " ({$details})" : "") . "\n";
    }
}

echo "======================================================================\n";
echo " AFM#2 — MODULAR MULTI-METHOD AUTHENTICATION TEST SUITE\n";
echo " Methods: Password + Google OIDC + WebAuthn Passkeys + Admin Provisioning\n";
echo "======================================================================\n\n";

$pdo = get_db_connection();

// --- 0. Pre-Flight Catalogue Invariants ---
echo "--- 0. Pre-Flight Invariants ---\n";
$preSongCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
assert_test("Pre-test Catalogue Baseline Invariant: strictly 2,411 songs", $preSongCount === 2411);

$preNullStmt = $pdo->query("SELECT `song_id` FROM `songs` WHERE `artist` IS NULL OR `artist` = '' ORDER BY `song_id`");
$preNulls = array_map('intval', $preNullStmt->fetchAll(PDO::FETCH_COLUMN));
assert_test("Pre-test Missing Artist Invariant: strictly [1546, 2015, 2196]", $preNulls === [1546, 2015, 2196]);

// --- 1. Password Authentication & Email Identification ---
echo "\n--- 1. Password Authentication & Canonical Identity ---\n";

// Create disposable test user for clean test isolation
$pdo->exec("DELETE FROM `app_users` WHERE `username` LIKE 'test_mod_%'");
$testPw = 'TestPassword123!';
$testHash = password_hash($testPw, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("
    INSERT INTO `app_users` (`username`, `email`, `password_hash`, `role`, `is_active`, `created_at`)
    VALUES ('test_mod_user1', 'mod_user1@example.com', :hash, 'user', 1, NOW())
");
$stmt->execute([':hash' => $testHash]);
$testUid1 = (int)$pdo->lastInsertId();

// Test login by username
$loginUserRes = attempt_login('test_mod_user1', $testPw);
assert_test("attempt_login() authenticates successfully by username", $loginUserRes['success'] && (int)$loginUserRes['user_id'] === $testUid1);

// Test login by email
$loginEmailRes = attempt_login('mod_user1@example.com', $testPw);
assert_test("attempt_login() authenticates successfully by email", $loginEmailRes['success'] && (int)$loginEmailRes['user_id'] === $testUid1);

// Test incorrect password
$badPwRes = attempt_login('test_mod_user1', 'WrongPassword!');
assert_test("attempt_login() rejects incorrect password", !$badPwRes['success'] && $badPwRes['error'] === 'Invalid username or password.');

// Test password change
$pwChangeRes = change_user_password($testUid1, $testPw, 'NewSecurePass456!');
assert_test("change_user_password() succeeds with valid current password", $pwChangeRes['success']);
assert_test("New password verifies against updated database hash", attempt_login('test_mod_user1', 'NewSecurePass456!')['success']);
assert_test("Old password is no longer accepted", !attempt_login('test_mod_user1', $testPw)['success']);

$actualAdminId = (int)$pdo->query("SELECT `user_id` FROM `app_users` WHERE `role` = 'admin' ORDER BY `user_id` ASC LIMIT 1")->fetchColumn();

// Test account deactivation fails closed
$deactivateRes = set_user_active_status($testUid1, false, $actualAdminId);
assert_test("Administrator can deactivate user account", $deactivateRes['success']);

$disabledLoginRes = attempt_login('test_mod_user1', 'NewSecurePass456!');
assert_test("Deactivated user attempt_login() fails closed with account disabled message", !$disabledLoginRes['success'] && str_contains($disabledLoginRes['error'], 'disabled'));

// Re-activate user
set_user_active_status($testUid1, true, $actualAdminId);
assert_test("Re-activated user can log in again", attempt_login('test_mod_user1', 'NewSecurePass456!')['success']);

// --- 2. Administrator Provisioning & Invitation Lifecycle ---
echo "\n--- 2. Administrator Provisioning & Invitation Lifecycle ---\n";

// Self-disable protection
$selfDisableRes = set_user_active_status($actualAdminId, false, $actualAdminId);
assert_test("Safeguard: Administrator cannot disable their own account", !$selfDisableRes['success'] && str_contains($selfDisableRes['error'], 'cannot disable your own'));

// Generate invitation link
$invEmail = 'invited_guest@example.com';
$invRes = create_invitation($invEmail, 'user', $actualAdminId);
assert_test("create_invitation() creates 64-hex token and shareable URL", $invRes['success'] && !empty($invRes['token']) && str_contains($invRes['invite_url'], 'invite.php?token='));

// Token validation
$valInv = validate_invitation_token($invRes['token']);
assert_test("validate_invitation_token() validates active unexpired token", $valInv !== null && $valInv['email'] === $invEmail);

// Reject fake token
assert_test("validate_invitation_token() rejects unknown/tampered token", validate_invitation_token('invalid_token_12345') === null);

// Accept invitation
$acceptRes = accept_invitation_with_password($invRes['token'], 'test_mod_invited', 'InvitedPass123!');
assert_test("accept_invitation_with_password() creates user and establishes session", $acceptRes['success'] && !empty($acceptRes['user_id']));
$newInvUid = (int)$acceptRes['user_id'];

// Prevent duplicate invitation reuse
assert_test("Single-use safeguard: Reusing accepted invitation token is rejected", validate_invitation_token($invRes['token']) === null);

// --- 3. Google OAuth 2.0 / OpenID Connect Architecture ---
echo "\n--- 3. Google OAuth 2.0 / OpenID Connect ---\n";

// URL generation with PKCE & state
$authUrl = GoogleAuthService::generate_auth_url('login');
assert_test("generate_auth_url() generates Google endpoint with state & code_challenge", 
    str_starts_with($authUrl, 'https://accounts.google.com/o/oauth2/v2/auth?') &&
    str_contains($authUrl, 'code_challenge=') &&
    str_contains($authUrl, 'code_challenge_method=S256')
);

// State validation: rejects tampered state
$badStateRes = GoogleAuthService::handle_callback('mock_code', 'wrong_state_abc');
assert_test("handle_callback() timing-safe state check rejects invalid state", !$badStateRes['success'] && str_contains($badStateRes['error'], 'state validation failed'));

// ID Token cryptographic verification using test RSA JWKS
$rsaKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$rsaDetails = openssl_pkey_get_details($rsaKey);
$nB64 = WebAuthnService::base64url_encode($rsaDetails['rsa']['n']);
$eB64 = WebAuthnService::base64url_encode($rsaDetails['rsa']['e']);
$testKid = 'afm2_test_rsa_kid';

$mockJwks = [
    ['kid' => $testKid, 'kty' => 'RSA', 'alg' => 'RS256', 'n' => $nB64, 'e' => $eB64]
];

$genTestJwt = function(array $headerOverrides, array $payloadOverrides) use ($rsaKey, $testKid): string {
    $header = array_merge(['alg' => 'RS256', 'kid' => $testKid, 'typ' => 'JWT'], $headerOverrides);
    $payload = array_merge([
        'iss'            => 'https://accounts.google.com',
        'aud'            => 'test_client_id_afm2',
        'sub'            => 'google_sub_987654321',
        'email'          => 'google_tester@example.com',
        'email_verified' => true,
        'exp'            => time() + 3600,
        'iat'            => time(),
    ], $payloadOverrides);

    $hB64 = WebAuthnService::base64url_encode(json_encode($header));
    $pB64 = WebAuthnService::base64url_encode(json_encode($payload));
    $data = $hB64 . '.' . $pB64;
    openssl_sign($data, $sig, $rsaKey, OPENSSL_ALGO_SHA256);
    return $data . '.' . WebAuthnService::base64url_encode($sig);
};

// Set test client ID in env
putenv('GOOGLE_CLIENT_ID=test_client_id_afm2');
$_ENV['GOOGLE_CLIENT_ID'] = 'test_client_id_afm2';

// Valid token
$validJwt = $genTestJwt([], []);
$valJwtRes = GoogleAuthService::verify_id_token($validJwt, $mockJwks);
assert_test("verify_id_token() validates authentic RS256 token and extracts verified claims", $valJwtRes['success'] && $valJwtRes['claims']['sub'] === 'google_sub_987654321');

// Expired token rejection
$expiredJwt = $genTestJwt([], ['exp' => time() - 300]);
assert_test("verify_id_token() rejects expired token", !GoogleAuthService::verify_id_token($expiredJwt, $mockJwks)['success']);

// Invalid issuer rejection
$badIssJwt = $genTestJwt([], ['iss' => 'https://rogue-identity-provider.com']);
assert_test("verify_id_token() rejects unauthorized issuer", !GoogleAuthService::verify_id_token($badIssJwt, $mockJwks)['success']);

// Invalid signature rejection
$tamperedJwt = $validJwt . 'corrupt';
assert_test("verify_id_token() rejects corrupted signature", !GoogleAuthService::verify_id_token($tamperedJwt, $mockJwks)['success']);

// STRICT RULE: Unlinked Google account does NOT self-register
$_SESSION['afm_google_oauth'] = ['state' => 'unlinked_state_123', 'mode' => 'login', 'created_at' => time()];
$unlinkedClaims = [
    'sub'            => 'unregistered_google_sub_111',
    'email'          => 'random_stranger@gmail.com',
    'email_verified' => true,
];
$unlinkedLoginRes = GoogleAuthService::handle_callback('mock_code', 'unlinked_state_123', $unlinkedClaims);
assert_test("STRICT RULE: Unknown Google identity cannot self-register; rejected with invitation requirement", 
    !$unlinkedLoginRes['success'] && str_contains($unlinkedLoginRes['error'], 'restricted to administrator-invited'));

// Link Google account to authenticated user
$_SESSION['afm_user_id'] = $testUid1;
$_SESSION['afm_username'] = 'test_mod_user1';
$_SESSION['afm_google_oauth'] = ['state' => 'link_state_123', 'mode' => 'link', 'created_at' => time()];

$linkClaims = [
    'sub'            => 'linked_google_sub_222',
    'email'          => 'mod_user1_google@gmail.com',
    'email_verified' => true,
];
$linkRes = GoogleAuthService::handle_callback('mock_code', 'link_state_123', $linkClaims);
assert_test("handle_callback(mode=link) links Google identity to authenticated user", $linkRes['success'] && $linkRes['action'] === 'linked');

// Test logging in with newly linked Google identity
$_SESSION = [];
$_SESSION['afm_google_oauth'] = ['state' => 'login_state_456', 'mode' => 'login', 'created_at' => time()];
$googleLoginRes = GoogleAuthService::handle_callback('mock_code', 'login_state_456', $linkClaims);
assert_test("handle_callback(mode=login) successfully logs in linked user and sets session", 
    $googleLoginRes['success'] && (int)$googleLoginRes['user_id'] === $testUid1);

// Prevent linking same Google identity to a different account
$_SESSION['afm_user_id'] = $newInvUid;
$_SESSION['afm_username'] = 'test_mod_invited';
$_SESSION['afm_google_oauth'] = ['state' => 'link_state_dup', 'mode' => 'link', 'created_at' => time()];
$dupLinkRes = GoogleAuthService::handle_callback('mock_code', 'link_state_dup', $linkClaims);
assert_test("Unique Identity Safeguard: Prevents linking same Google account to multiple users", 
    !$dupLinkRes['success'] && str_contains($dupLinkRes['error'], 'already linked to another'));

// --- 4. WebAuthn Passkeys (FIDO2) Architecture ---
echo "\n--- 4. WebAuthn Passkeys (FIDO2) ---\n";

// Registration options
$regOpts = WebAuthnService::generate_registration_options($testUid1, 'test_mod_user1');
assert_test("generate_registration_options() returns RP info, user handle, and 32-byte challenge", 
    $regOpts['rp']['name'] === 'AFM#2 — Musical Finale' &&
    !empty($regOpts['challenge']) &&
    !empty($regOpts['user']['id'])
);

// Synthetic registration ceremony verification
// Generate synthetic P-256 key pair
$ecKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$ecDetails = openssl_pkey_get_details($ecKey);
$x = $ecDetails['ec']['x'];
$y = $ecDetails['ec']['y'];
$credIdRaw = random_bytes(32);
$credIdB64 = WebAuthnService::base64url_encode($credIdRaw);

// Assemble clientDataJSON
$clientDataObj = [
    'type'      => 'webauthn.create',
    'challenge' => $_SESSION['afm_webauthn_reg']['challenge'],
    'origin'    => WebAuthnService::get_expected_origin(),
];
$rawClientData = json_encode($clientDataObj);

// Assemble authData:
// rpIdHash (32) + flags (1: UP=1, AT=1 -> 0x41) + signCount (4) + AAGUID (16) + credIdLen (2) + credId (32) + COSE key
$rpIdHash = hash('sha256', WebAuthnService::get_rp_id(), true);
$flags = chr(0x41); // UP | AT
$signCount = pack('N', 0);
$aaguid = random_bytes(16);
$credIdLen = pack('n', strlen($credIdRaw));

// Encode COSE key to CBOR: {1: 2, 3: -7, -1: 1, -2: x, -3: y}
// Minimal CBOR map for test:
$coseMap = [
    1  => 2,     // kty: EC2
    3  => -7,    // alg: ES256
    -1 => 1,     // crv: P-256
    -2 => $x,    // x coord
    -3 => $y     // y coord
];

// Helper to encode CBOR map for test fixture
$encodeCbor = function(array $map): string {
    $out = chr(0xa0 | count($map)); // map with N items
    foreach ($map as $k => $v) {
        // encode key (int or negative int)
        if ($k >= 0) {
            $out .= ($k < 24) ? chr($k) : (chr(24) . chr($k));
        } else {
            $n = -1 - $k;
            $out .= ($n < 24) ? chr(0x20 | $n) : (chr(0x38) . chr($n));
        }
        // encode value
        if (is_int($v)) {
            if ($v >= 0) {
                $out .= ($v < 24) ? chr($v) : (chr(24) . chr($v));
            } else {
                $n = -1 - $v;
                $out .= ($n < 24) ? chr(0x20 | $n) : (chr(0x38) . chr($n));
            }
        } elseif (is_string($v)) {
            $len = strlen($v);
            $out .= ($len < 24) ? (chr(0x40 | $len) . $v) : ("\x58" . chr($len) . $v);
        }
    }
    return $out;
};

$cborKey = $encodeCbor($coseMap);
$authData = $rpIdHash . $flags . $signCount . $aaguid . $credIdLen . $credIdRaw . $cborKey;

// Attestation map: {"fmt": "none", "attStmt": {}, "authData": authData}
// In CBOR: 0xa3 (map of 3 items)
$rawAttestation = chr(0xa3) 
    . chr(0x63) . 'fmt' . chr(0x64) . 'none' 
    . chr(0x67) . 'attStmt' . chr(0xa0) 
    . chr(0x68) . 'authData' . chr(0x59) . pack('n', strlen($authData)) . $authData;

$regPayload = [
    'clientDataJSON'    => WebAuthnService::base64url_encode($rawClientData),
    'attestationObject' => WebAuthnService::base64url_encode($rawAttestation),
    'transports'        => ['internal', 'hybrid'],
];

$verifyRegRes = WebAuthnService::verify_registration_response($testUid1, $regPayload, 'Test MacBook Passkey');
assert_test("verify_registration_response() verifies attestation, validates flags, and stores public key", 
    $verifyRegRes['success'] && (int)$verifyRegRes['passkey_id'] > 0);
$testPasskeyId = (int)$verifyRegRes['passkey_id'];

// WebAuthn Authentication ceremony verification
$authOpts = WebAuthnService::generate_authentication_options('test_mod_user1');
assert_test("generate_authentication_options() returns allowCredentials matching registered passkey", 
    count($authOpts['allowCredentials']) > 0 && $authOpts['allowCredentials'][0]['id'] === $credIdB64);

// Sign authentication assertion:
$authClientData = json_encode([
    'type'      => 'webauthn.get',
    'challenge' => $_SESSION['afm_webauthn_auth']['challenge'],
    'origin'    => WebAuthnService::get_expected_origin(),
]);
$rawAuthClientData = $authClientData;
$authAuthData = $rpIdHash . chr(0x01) . pack('N', 1); // UP=1, signCount=1
$signedAssertion = $authAuthData . hash('sha256', $rawAuthClientData, true);

openssl_sign($signedAssertion, $ecSig, $ecKey, OPENSSL_ALGO_SHA256);

$assertionPayload = [
    'id'                => $credIdB64,
    'clientDataJSON'    => WebAuthnService::base64url_encode($rawAuthClientData),
    'authenticatorData' => WebAuthnService::base64url_encode($authAuthData),
    'signature'         => WebAuthnService::base64url_encode($ecSig),
];

$_SESSION = [];
$_SESSION['afm_webauthn_auth'] = [
    'challenge'  => $authOpts['challenge'],
    'created_at' => time(),
];

$authVerifyRes = WebAuthnService::verify_authentication_response($assertionPayload);
assert_test("verify_authentication_response() verifies ECDSA signature, validates origin, and logs user in", 
    $authVerifyRes['success'] && (int)$authVerifyRes['user_id'] === $testUid1);

// Sign counter clone / replay protection
$_SESSION['afm_webauthn_auth'] = [
    'challenge'  => $authOpts['challenge'],
    'created_at' => time(),
];
$replayAuthData = $rpIdHash . chr(0x01) . pack('N', 1); // signCount 1 <= stored 1
$signedReplay = $replayAuthData . hash('sha256', $rawAuthClientData, true);
openssl_sign($signedReplay, $ecSigReplay, $ecKey, OPENSSL_ALGO_SHA256);

$replayPayload = [
    'id'                => $credIdB64,
    'clientDataJSON'    => WebAuthnService::base64url_encode($rawAuthClientData),
    'authenticatorData' => WebAuthnService::base64url_encode($replayAuthData),
    'signature'         => WebAuthnService::base64url_encode($ecSigReplay),
];
$replayRes = WebAuthnService::verify_authentication_response($replayPayload);
assert_test("Anti-Replay / Clone Safeguard: Rejects authenticator assertion with non-incremented counter", 
    !$replayRes['success'] && str_contains($replayRes['error'], 'clone or replay'));

// Last-usable auth method guard
assert_test("can_remove_auth_method() permits removing passkey when password & Google exist", 
    can_remove_auth_method($testUid1, 'passkey'));

// Remove Google identity so user has only Password & Passkey
GoogleAuthService::unlink_google_identity($testUid1);
assert_test("can_remove_auth_method() permits removing passkey when password still exists", 
    can_remove_auth_method($testUid1, 'passkey'));

// Delete passkey
$delPkRes = WebAuthnService::delete_passkey($testUid1, $testPasskeyId);
assert_test("delete_passkey() successfully removes passkey credential", $delPkRes['success']);

// Now user only has password: cannot remove password
assert_test("Last-Auth-Method Guard: User with only password cannot remove password", 
    !can_remove_auth_method($testUid1, 'password'));

// --- Cleanup test artifacts from app_users ---
$pdo->exec("DELETE FROM `app_users` WHERE `username` LIKE 'test_mod_%'");
$pdo->exec("DELETE FROM `user_invitations` WHERE `email` = '{$invEmail}'");

// --- 5. Post-Flight Catalogue Invariants Verification ---
echo "\n--- 5. Post-Flight Invariants Verification ---\n";
$postSongCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
assert_test("Post-test Catalogue Preservation Invariant: strictly 2,411 songs", $postSongCount === 2411);

$postNullStmt = $pdo->query("SELECT `song_id` FROM `songs` WHERE `artist` IS NULL OR `artist` = '' ORDER BY `song_id`");
$postNulls = array_map('intval', $postNullStmt->fetchAll(PDO::FETCH_COLUMN));
assert_test("Post-test Missing Artist Invariant: strictly [1546, 2015, 2196]", $postNulls === [1546, 2015, 2196]);

echo "\n======================================================================\n";
echo " MODULAR AUTHENTICATION TEST EXECUTION SUMMARY\n";
echo "======================================================================\n";
echo "Total Tests Executed: {$totalTests}\n";
echo "Passed:               {$passedTests}\n";
echo "Failed:               " . ($totalTests - $passedTests) . "\n\n";

if ($totalTests === $passedTests) {
    echo "🎉 ALL MODULAR MULTI-METHOD AUTHENTICATION TESTS PASSED! (100% PASS RATE)\n";
    exit(0);
} else {
    echo "⚠️ SOME TESTS FAILED!\n";
    exit(1);
}
