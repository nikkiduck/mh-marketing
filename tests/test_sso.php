<?php
/**
 * test_sso.php — offline verification of the id_token pipeline.
 * Not deployed. Seeds the JWKS cache with a key we control, mints tokens,
 * and asserts the verifier accepts the good one and rejects each bad one.
 */

define('SSO_TENANT_ID',     'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
define('SSO_CLIENT_ID',     '11111111-2222-3333-4444-555555555555');
define('SSO_CLIENT_SECRET', 'not-used-in-this-test');
define('SSO_REDIRECT_URI',  'https://marketing.monthaus.com/oauth_callback.php');

// Minimal stand-ins so sso.php can be included without the app around it.
if (!function_exists('current_user')) { function current_user(): array { return []; } }
$_SESSION = [];

// Pull in only the parts under test, skipping auth.php's session bootstrap.
$src = file_get_contents(__DIR__ . '/../sso.php');
$src = preg_replace("#require_once __DIR__ \. '/auth\.php';.*\n#", '', $src, 1);
$src = preg_replace("#if \(is_file\(__DIR__ \. '/sso_config\.php'\)\) \{\n.*\n\}\n#", '', $src, 1);
eval('?>' . $src);

// ── A signing key we control, published as a JWK the way Entra would ─────────
$kid = 'test-key-1';
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$det = openssl_pkey_get_details($key);

$jwks = ['keys' => [[
    'kty' => 'RSA',
    'use' => 'sig',
    'kid' => $kid,
    'n'   => sso_b64url_encode($det['rsa']['n']),
    'e'   => sso_b64url_encode($det['rsa']['e']),
]]];
file_put_contents(sso_jwks_cache_path(), json_encode($jwks));

$pass = 0; $fail = 0;
function check(string $label, bool $ok): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    $label\n"; }
    else     { $fail++; echo "  FAIL  $label\n"; }
}

// ── 1. JWK → PEM must reproduce the real public key ─────────────────────────
echo "JWK to PEM\n";
$pem = sso_jwk_to_pem($jwks['keys'][0]);
check('produces a PEM', is_string($pem) && str_contains((string)$pem, 'BEGIN PUBLIC KEY'));
check('openssl parses it', $pem !== null && openssl_pkey_get_public($pem) !== false);
check('matches the original key byte for byte',
      $pem !== null && trim($pem) === trim($det['key']));

// ── helper: mint a signed token ─────────────────────────────────────────────
function mint(array $claims, $key, string $kid, string $alg = 'RS256'): string {
    $h = sso_b64url_encode(json_encode(['alg' => $alg, 'typ' => 'JWT', 'kid' => $kid]));
    $p = sso_b64url_encode(json_encode($claims));
    openssl_sign("$h.$p", $sig, $key, OPENSSL_ALGO_SHA256);
    return "$h.$p." . sso_b64url_encode($sig);
}

$now   = time();
$nonce = 'nonce-abc-123';
$good  = [
    'iss'   => 'https://login.microsoftonline.com/' . SSO_TENANT_ID . '/v2.0',
    'aud'   => SSO_CLIENT_ID,
    'tid'   => SSO_TENANT_ID,
    'oid'   => '99999999-8888-7777-6666-555555555555',
    'email' => 'Nikki.Boxer@monthaus.com',
    'nonce' => $nonce,
    'iat'   => $now, 'nbf' => $now, 'exp' => $now + 3600,
];

// ── 2. the happy path ───────────────────────────────────────────────────────
echo "\nValid token\n";
try {
    $c = sso_verify_id_token(mint($good, $key, $kid), $nonce);
    check('accepted', true);
    check('claims come back intact', $c['oid'] === $good['oid'] && $c['email'] === $good['email']);
} catch (Throwable $e) {
    check('accepted — ' . $e->getMessage(), false);
    check('claims come back intact', false);
}

// ── 3. every rejection path ─────────────────────────────────────────────────
echo "\nTokens that must be rejected\n";

$rejects = [
    'tampered payload' => function () use ($good, $key, $kid, $nonce) {
        $t = mint($good, $key, $kid);
        [$h, $p, $s] = explode('.', $t);
        $evil = $good; $evil['email'] = 'attacker@example.com';
        return [$h . '.' . sso_b64url_encode(json_encode($evil)) . '.' . $s, $nonce];
    },
    'alg: none' => function () use ($good, $kid, $nonce) {
        $h = sso_b64url_encode(json_encode(['alg' => 'none', 'typ' => 'JWT', 'kid' => $kid]));
        $p = sso_b64url_encode(json_encode($good));
        return ["$h.$p.", $nonce];
    },
    'alg: HS256 signed with the public key' => function () use ($good, $kid, $det, $nonce) {
        $h = sso_b64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $kid]));
        $p = sso_b64url_encode(json_encode($good));
        $sig = hash_hmac('sha256', "$h.$p", $det['key'], true);
        return ["$h.$p." . sso_b64url_encode($sig), $nonce];
    },
    'signed by a different key' => function () use ($good, $kid, $nonce) {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        return [mint($good, $other, $kid), $nonce];
    },
    'unknown kid' => function () use ($good, $key, $nonce) {
        return [mint($good, $key, 'some-other-kid'), $nonce];
    },
    'wrong audience' => function () use ($good, $key, $kid, $nonce) {
        $c = $good; $c['aud'] = 'some-other-app-id';
        return [mint($c, $key, $kid), $nonce];
    },
    'wrong tenant (tid)' => function () use ($good, $key, $kid, $nonce) {
        $c = $good; $c['tid'] = 'ffffffff-ffff-ffff-ffff-ffffffffffff';
        return [mint($c, $key, $kid), $nonce];
    },
    'wrong issuer' => function () use ($good, $key, $kid, $nonce) {
        $c = $good; $c['iss'] = 'https://login.microsoftonline.com/evil/v2.0';
        return [mint($c, $key, $kid), $nonce];
    },
    'expired' => function () use ($good, $key, $kid, $nonce) {
        $c = $good; $c['exp'] = time() - 3600;
        return [mint($c, $key, $kid), $nonce];
    },
    'not yet valid (nbf in the future)' => function () use ($good, $key, $kid, $nonce) {
        $c = $good; $c['nbf'] = time() + 3600;
        return [mint($c, $key, $kid), $nonce];
    },
    'replayed with a different nonce' => function () use ($good, $key, $kid) {
        return [mint($good, $key, $kid), 'a-different-nonce'];
    },
    'no oid claim' => function () use ($good, $key, $kid, $nonce) {
        $c = $good; unset($c['oid']);
        return [mint($c, $key, $kid), $nonce];
    },
    'not a JWT at all' => function () use ($nonce) {
        return ['garbage', $nonce];
    },
];

foreach ($rejects as $label => $make) {
    [$tok, $n] = $make();
    try {
        sso_verify_id_token($tok, $n);
        check("$label", false);            // getting here is the failure
    } catch (RuntimeException $e) {
        check("$label", true);
    }
}

// ── 4. aud as an array (Entra sometimes does this) ──────────────────────────
echo "\nAudience as an array\n";
try {
    $c = $good; $c['aud'] = ['some-other-app', SSO_CLIENT_ID];
    sso_verify_id_token(mint($c, $key, $kid), $nonce);
    check('accepted when our client id is in the list', true);
} catch (Throwable $e) {
    check('accepted when our client id is in the list — ' . $e->getMessage(), false);
}

// ── 5. return_to must never leave this site ────────────────────────────────
echo "\nOpen-redirect guard on return_to\n";
foreach ([
    'https://evil.example.com/x' => false,
    '//evil.example.com/x'       => false,
    'agent.php'                  => false,
    '/agent.php?id=12'           => true,
] as $candidate => $should_keep) {
    $_SESSION = [];
    sso_authorize_url($candidate);
    $kept = ($_SESSION['sso_return_to'] === $candidate);
    check(sprintf('%-30s %s', $candidate, $should_keep ? 'kept' : 'rewritten to /index.php'),
          $kept === $should_keep);
}

// ── 6. PKCE challenge derives correctly from the stored verifier ────────────
echo "\nPKCE\n";
$_SESSION = [];
$url = sso_authorize_url('/index.php');
parse_str(parse_url($url, PHP_URL_QUERY), $q);
check('S256 method advertised', ($q['code_challenge_method'] ?? '') === 'S256');
check('challenge = base64url(sha256(verifier))',
      ($q['code_challenge'] ?? '') === sso_b64url_encode(hash('sha256', $_SESSION['sso_verifier'], true)));
check('state and nonce are 64 hex chars each',
      strlen($_SESSION['sso_state']) === 64 && strlen($_SESSION['sso_nonce']) === 64);
check('state in the URL matches the session', ($q['state'] ?? '') === $_SESSION['sso_state']);
check('response_mode is query, not form_post', ($q['response_mode'] ?? '') === 'query');

echo "\n" . str_repeat('─', 52) . "\n";
echo ($fail === 0 ? "PASS" : "FAIL") . "  —  $pass passed, $fail failed\n";
@unlink(sso_jwks_cache_path());
exit($fail === 0 ? 0 : 1);
