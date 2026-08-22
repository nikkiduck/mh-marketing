<?php
/**
 * sso.php — Microsoft Entra ID (OpenID Connect) helper.
 *
 * Include-only. Never call this file directly over the web.
 *
 * Implements the OIDC authorization-code flow with PKCE against a single
 * Entra tenant, and full RS256 id_token validation against the tenant's
 * published JWKS. No Composer dependency — everything here uses PHP core
 * plus ext/openssl and ext/curl, both of which SiteGround provides.
 *
 * Configuration lives in sso_config.php (gitignored, .htaccess denied).
 * See sso_config.sample.php.
 */

require_once __DIR__ . '/auth.php';   // session bootstrap + session helpers

if (is_file(__DIR__ . '/sso_config.php')) {
    require_once __DIR__ . '/sso_config.php';
}

/** Clock skew tolerated on exp / nbf / iat, in seconds. */
const SSO_LEEWAY = 120;

/** How long a cached JWKS document is reused, in seconds. */
const SSO_JWKS_TTL = 43200;   // 12 hours

/**
 * True when sso_config.php exists and carries real values.
 * Every entry point checks this so the app degrades to password login
 * rather than fataling if the config was never uploaded.
 */
function sso_enabled(): bool
{
    foreach (['SSO_TENANT_ID', 'SSO_CLIENT_ID', 'SSO_CLIENT_SECRET', 'SSO_REDIRECT_URI'] as $c) {
        if (!defined($c) || trim((string) constant($c)) === '') {
            return false;
        }
        if (str_starts_with((string) constant($c), 'PASTE_')) {
            return false;   // sample file uploaded but never filled in
        }
    }
    return true;
}

// ─── base64url ────────────────────────────────────────────────────────────────

function sso_b64url_decode(string $s): string
{
    $out = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    return $out === false ? '' : $out;
}

function sso_b64url_encode(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

// ─── Tenant endpoints ─────────────────────────────────────────────────────────

function sso_authority(): string
{
    return 'https://login.microsoftonline.com/' . rawurlencode(SSO_TENANT_ID);
}

function sso_authorize_endpoint(): string { return sso_authority() . '/oauth2/v2.0/authorize'; }
function sso_token_endpoint(): string     { return sso_authority() . '/oauth2/v2.0/token'; }
function sso_jwks_endpoint(): string      { return sso_authority() . '/discovery/v2.0/keys'; }
function sso_logout_endpoint(): string    { return sso_authority() . '/oauth2/v2.0/logout'; }

/**
 * The two issuer forms Entra uses for the v2.0 endpoint. Both are legitimate;
 * which one appears depends on tenant configuration.
 */
function sso_expected_issuers(): array
{
    return [
        'https://login.microsoftonline.com/' . SSO_TENANT_ID . '/v2.0',
        'https://sts.windows.net/' . SSO_TENANT_ID . '/',
    ];
}

// ─── Step 1: build the authorize redirect ─────────────────────────────────────

/**
 * Generates state, nonce and a PKCE verifier, stores them in the session,
 * and returns the URL to send the browser to.
 *
 * @param string $return_to Path on this site to land on after sign-in.
 * @param bool   $silent    Use prompt=none — fail rather than show a prompt.
 */
function sso_authorize_url(string $return_to = '/index.php', bool $silent = false): string
{
    $state    = bin2hex(random_bytes(32));
    $nonce    = bin2hex(random_bytes(32));
    $verifier = sso_b64url_encode(random_bytes(64));

    // Only ever return to a path on this site — never an attacker-supplied host.
    if ($return_to === '' || $return_to[0] !== '/' || str_starts_with($return_to, '//')) {
        $return_to = '/index.php';
    }

    $_SESSION['sso_state']     = $state;
    $_SESSION['sso_nonce']     = $nonce;
    $_SESSION['sso_verifier']  = $verifier;
    $_SESSION['sso_return_to'] = $return_to;
    $_SESSION['sso_started']   = time();

    $params = [
        'client_id'             => SSO_CLIENT_ID,
        'response_type'         => 'code',
        // Default query response mode on purpose: form_post arrives as a
        // cross-site POST, which SameSite would strip the session cookie from.
        'response_mode'         => 'query',
        'redirect_uri'          => SSO_REDIRECT_URI,
        'scope'                 => 'openid profile email',
        'state'                 => $state,
        'nonce'                 => $nonce,
        'code_challenge'        => sso_b64url_encode(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
    ];
    if ($silent) {
        $params['prompt'] = 'none';
    }

    return sso_authorize_endpoint() . '?' . http_build_query($params);
}

// ─── Step 2: exchange the code for tokens ─────────────────────────────────────

/**
 * @throws RuntimeException on any transport or protocol error.
 */
function sso_exchange_code(string $code, string $verifier): array
{
    $body = http_build_query([
        'client_id'     => SSO_CLIENT_ID,
        'client_secret' => SSO_CLIENT_SECRET,
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => SSO_REDIRECT_URI,
        'code_verifier' => $verifier,
        'scope'         => 'openid profile email',
    ]);

    $ch = curl_init(sso_token_endpoint());
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Could not reach Microsoft to complete sign-in: ' . $err);
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('Microsoft returned an unreadable token response.');
    }
    if ($http !== 200 || isset($json['error'])) {
        // error_description is Microsoft's own text; it is safe to log but we
        // do not surface it verbatim to the browser.
        error_log('SSO token exchange failed: ' . $raw);
        throw new RuntimeException('Microsoft rejected the sign-in request.');
    }
    if (empty($json['id_token'])) {
        throw new RuntimeException('Microsoft did not return an identity token.');
    }

    return $json;
}

// ─── Step 3: verify the id_token ──────────────────────────────────────────────

/**
 * Validates signature, issuer, audience, tenant, lifetime and nonce.
 * Returns the decoded claim set.
 *
 * @throws RuntimeException if any check fails.
 */
function sso_verify_id_token(string $jwt, string $expected_nonce): array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        throw new RuntimeException('Malformed identity token.');
    }
    [$b64_header, $b64_payload, $b64_sig] = $parts;

    $header  = json_decode(sso_b64url_decode($b64_header), true);
    $claims  = json_decode(sso_b64url_decode($b64_payload), true);
    $sig     = sso_b64url_decode($b64_sig);

    if (!is_array($header) || !is_array($claims) || $sig === '') {
        throw new RuntimeException('Malformed identity token.');
    }

    // Algorithm is pinned, not read from the token. This is what stops the
    // classic "alg: none" and RS256→HS256 confusion attacks.
    if (($header['alg'] ?? '') !== 'RS256') {
        throw new RuntimeException('Identity token used an unexpected signing algorithm.');
    }
    $kid = $header['kid'] ?? '';
    if ($kid === '') {
        throw new RuntimeException('Identity token did not name a signing key.');
    }

    $pem = sso_public_key_for_kid($kid);
    if ($pem === null) {
        throw new RuntimeException('Could not find the key Microsoft signed with.');
    }

    $verified = openssl_verify($b64_header . '.' . $b64_payload, $sig, $pem, OPENSSL_ALGO_SHA256);
    if ($verified !== 1) {
        throw new RuntimeException('Identity token signature did not verify.');
    }

    // ── Claim checks ─────────────────────────────────────────────────────────
    $now = time();

    if (!in_array($claims['iss'] ?? '', sso_expected_issuers(), true)) {
        throw new RuntimeException('Identity token came from an unexpected issuer.');
    }
    $aud = $claims['aud'] ?? '';
    $aud_ok = is_array($aud)
        ? in_array(SSO_CLIENT_ID, $aud, true)
        : hash_equals(SSO_CLIENT_ID, (string) $aud);
    if (!$aud_ok) {
        throw new RuntimeException('Identity token was issued for a different application.');
    }
    // tid pins the token to our directory — this is what blocks a token minted
    // by any other Microsoft tenant.
    if (!hash_equals(SSO_TENANT_ID, (string) ($claims['tid'] ?? ''))) {
        throw new RuntimeException('Identity token came from a different Microsoft directory.');
    }
    if (!isset($claims['exp']) || $now >= ((int) $claims['exp'] + SSO_LEEWAY)) {
        throw new RuntimeException('Identity token has expired. Please try again.');
    }
    if (isset($claims['nbf']) && $now < ((int) $claims['nbf'] - SSO_LEEWAY)) {
        throw new RuntimeException('Identity token is not valid yet.');
    }
    if (isset($claims['iat']) && $now < ((int) $claims['iat'] - SSO_LEEWAY)) {
        throw new RuntimeException('Identity token is not valid yet.');
    }
    if (!hash_equals($expected_nonce, (string) ($claims['nonce'] ?? ''))) {
        throw new RuntimeException('Identity token did not match this sign-in attempt.');
    }
    if (empty($claims['oid'])) {
        throw new RuntimeException('Identity token carried no account identifier.');
    }

    return $claims;
}

/**
 * Returns the PEM public key for a given kid, refreshing the cached JWKS once
 * if the kid is unknown. That refresh is what carries us through Microsoft's
 * routine signing-key rollover without an outage.
 */
function sso_public_key_for_kid(string $kid): ?string
{
    foreach ([false, true] as $force_refresh) {
        $jwks = sso_fetch_jwks($force_refresh);
        foreach ($jwks['keys'] ?? [] as $jwk) {
            if (hash_equals((string) ($jwk['kid'] ?? ''), $kid)) {
                $pem = sso_jwk_to_pem($jwk);
                if ($pem !== null) {
                    return $pem;
                }
            }
        }
    }
    return null;
}

function sso_jwks_cache_path(): string
{
    return rtrim(sys_get_temp_dir(), '/') . '/mh_jwks_' . sha1(SSO_TENANT_ID) . '.json';
}

function sso_fetch_jwks(bool $force_refresh = false): array
{
    $cache = sso_jwks_cache_path();

    if (!$force_refresh && is_file($cache) && (time() - filemtime($cache)) < SSO_JWKS_TTL) {
        $cached = json_decode((string) file_get_contents($cache), true);
        if (is_array($cached) && !empty($cached['keys'])) {
            return $cached;
        }
    }

    $ch = curl_init(sso_jwks_endpoint());
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = ($raw === false) ? null : json_decode($raw, true);

    if ($http === 200 && is_array($json) && !empty($json['keys'])) {
        @file_put_contents($cache, $raw, LOCK_EX);
        @chmod($cache, 0600);
        return $json;
    }

    // Network hiccup: fall back to whatever we have rather than locking
    // everyone out. Signature verification still runs against these keys.
    if (is_file($cache)) {
        $stale = json_decode((string) file_get_contents($cache), true);
        if (is_array($stale) && !empty($stale['keys'])) {
            error_log('SSO: JWKS refresh failed, using cached keys.');
            return $stale;
        }
    }

    throw new RuntimeException('Could not retrieve Microsoft signing keys.');
}

// ─── JWK (RSA) → PEM ──────────────────────────────────────────────────────────
//
// Builds a DER SubjectPublicKeyInfo from the modulus and exponent, then
// base64s it into a PEM that openssl_verify() accepts.

function sso_der_length(int $len): string
{
    if ($len < 0x80) {
        return chr($len);
    }
    $bytes = ltrim(pack('N', $len), "\x00");
    return chr(0x80 | strlen($bytes)) . $bytes;
}

/** DER INTEGER, kept positive by prefixing a zero byte when the top bit is set. */
function sso_der_integer(string $bin): string
{
    $bin = ltrim($bin, "\x00");
    if ($bin === '') {
        $bin = "\x00";
    }
    if ((ord($bin[0]) & 0x80) !== 0) {
        $bin = "\x00" . $bin;
    }
    return "\x02" . sso_der_length(strlen($bin)) . $bin;
}

function sso_der_sequence(string $bin): string
{
    return "\x30" . sso_der_length(strlen($bin)) . $bin;
}

function sso_jwk_to_pem(array $jwk): ?string
{
    if (($jwk['kty'] ?? '') !== 'RSA') {
        return null;
    }
    $n = sso_b64url_decode((string) ($jwk['n'] ?? ''));
    $e = sso_b64url_decode((string) ($jwk['e'] ?? ''));
    if ($n === '' || $e === '') {
        return null;
    }

    $rsa_public_key = sso_der_sequence(sso_der_integer($n) . sso_der_integer($e));

    // AlgorithmIdentifier ::= SEQUENCE { rsaEncryption OID, NULL }
    $alg_id = sso_der_sequence(hex2bin('06092a864886f70d010101') . hex2bin('0500'));

    // BIT STRING with zero unused bits, wrapping the RSAPublicKey
    $bit_string = "\x03" . sso_der_length(strlen($rsa_public_key) + 1) . "\x00" . $rsa_public_key;

    $spki = sso_der_sequence($alg_id . $bit_string);

    return "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($spki), 64, "\n")
         . "-----END PUBLIC KEY-----\n";
}

// ─── Resolving the Microsoft account to a portal user ─────────────────────────

/**
 * Finds the users row for a verified set of claims.
 *
 *   1. match on the immutable Entra object id, if we have already linked
 *   2. otherwise match on email and store the oid for next time
 *   3. otherwise refuse — accounts are never created from a Microsoft sign-in
 *
 * @return array{0: ?array, 1: string}  [user row or null, error message]
 */
function sso_resolve_user(mysqli $conn, array $claims): array
{
    $oid = (string) $claims['oid'];

    // Entra puts the address in different claims depending on tenant setup.
    $email = strtolower(trim((string) (
        $claims['email']
        ?? $claims['preferred_username']
        ?? $claims['upn']
        ?? ''
    )));

    $columns = 'id, first_name, last_name, email, role, is_active, mls_id, agent_key, must_change_password';

    // 1 ── already linked
    $stmt = $conn->prepare("SELECT $columns FROM users WHERE entra_object_id = ? LIMIT 1");
    if (!$stmt) {
        return [null, 'Sign-in is temporarily unavailable.'];
    }
    $stmt->bind_param('s', $oid);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user) {
        return [$user, ''];
    }

    // 2 ── first sign-in: match on email, then remember the oid
    if ($email === '') {
        return [null, 'Microsoft did not share an email address for this account.'];
    }

    $stmt = $conn->prepare("SELECT $columns FROM users WHERE LOWER(email) = ? LIMIT 1");
    if (!$stmt) {
        return [null, 'Sign-in is temporarily unavailable.'];
    }
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        // 3 ── unknown account. Deny by design.
        return [null, 'There is no Mont Haus portal account for ' . $email . '. Contact Nikki to have one created.'];
    }

    $link = $conn->prepare("UPDATE users SET entra_object_id = ?, sso_linked_at = NOW() WHERE id = ?");
    if ($link) {
        $link->bind_param('si', $oid, $user['id']);
        $link->execute();
        $link->close();
    }

    return [$user, ''];
}

/**
 * Populates exactly the session keys login.php sets, so auth.php,
 * require_login() and require_role() behave identically either way.
 */
function sso_establish_session(mysqli $conn, array $user): void
{
    session_regenerate_id(true);

    $_SESSION['login_attempts']  = 0;
    $_SESSION['user_id']         = $user['id'];
    $_SESSION['user_email']      = $user['email'];
    $_SESSION['user_first_name'] = $user['first_name'];
    $_SESSION['user_last_name']  = $user['last_name'];
    $_SESSION['user_role']       = $user['role'];
    $_SESSION['agent_mls_id']    = $user['mls_id']    ?? '';
    $_SESSION['agent_key']       = $user['agent_key'] ?? '';
    $_SESSION['auth_method']     = 'microsoft';

    // An SSO account has no password to change, but honour the flag if an
    // admin set it on an account that still has one.
    if (!empty($user['must_change_password'])) {
        $_SESSION['must_change_password'] = true;
    }

    if ($upd = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")) {
        $upd->bind_param('i', $user['id']);
        $upd->execute();
        $upd->close();
    }
}

/** Clears the one-shot values used by a single sign-in attempt. */
function sso_clear_flow_state(): void
{
    unset(
        $_SESSION['sso_state'],
        $_SESSION['sso_nonce'],
        $_SESSION['sso_verifier'],
        $_SESSION['sso_return_to'],
        $_SESSION['sso_started']
    );
}
