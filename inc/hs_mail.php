<?php
/**
 * inc/hs_mail.php — sending for the Hot Sheet emails.
 *
 * SendGrid's REST API (as the hub did), because it takes the List-Unsubscribe
 * headers directly. HOT_SHEET_ALLOWED_RECIPIENTS (inc/config.php) is enforced
 * HERE, at the last step before the network, so no caller can send around it.
 */

/** True when this address may be emailed. An empty list means no restriction. */
function hs_recipient_allowed(string $email): bool {
    $list = defined('HOT_SHEET_ALLOWED_RECIPIENTS') ? trim((string)HOT_SHEET_ALLOWED_RECIPIENTS) : '';
    if ($list === '') return true;
    $allowed = array_map(fn($e) => strtolower(trim($e)), explode(',', $list));
    return in_array(strtolower(trim($email)), $allowed, true);
}

function hs_unsubscribe_url(string $token): string {
    return rtrim(SITE_URL, '/') . '/unsubscribe.php?t=' . rawurlencode($token);
}

/**
 * Send one email. Returns [outcome, detail]: outcome is 'sent', 'failed' or
 * 'blocked' (not on the allowlist; nothing left this server).
 */
function hs_send_email(string $to, string $subject, string $html, string $unsubscribe_url = ''): array {
    if (!hs_recipient_allowed($to)) return ['blocked', 'not on HOT_SHEET_ALLOWED_RECIPIENTS'];
    if (!defined('SENDGRID_API_KEY') || SENDGRID_API_KEY === '') return ['failed', 'SENDGRID_API_KEY not set in inc/db.php'];

    $text = trim(html_entity_decode(strip_tags(preg_replace(['#<(style|head)\b.*?</\1>#is', '#<br\s*/?>#i', '#</(tr|div|p)>#i'],
                                                            ['', "\n", "\n"], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $text = preg_replace("/\n\s*\n+/", "\n\n", $text);

    $payload = [
        'personalizations' => [['to' => [['email' => $to]]]],
        'from'    => ['email' => HOT_SHEET_FROM_EMAIL, 'name' => HOT_SHEET_FROM_NAME],
        'subject' => $subject,
        'content' => [['type' => 'text/plain', 'value' => $text], ['type' => 'text/html', 'value' => $html]],
    ];
    if ($unsubscribe_url !== '') {
        // RFC 8058 one-click: mail clients POST "List-Unsubscribe=One-Click"
        // to this URL, which unsubscribe.php accepts.
        $payload['headers'] = [
            'List-Unsubscribe'      => '<' . $unsubscribe_url . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }

    $ch = curl_init(getenv('MH_SENDGRID_URL') ?: 'https://api.sendgrid.com/v3/mail/send');   // env: test harness only
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . SENDGRID_API_KEY, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body = (string)curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($code >= 200 && $code < 300) return ['sent', "HTTP {$code}"];
    return ['failed', "HTTP {$code}" . ($err ? " {$err}" : '') . ' ' . substr($body, 0, 300)];
}
