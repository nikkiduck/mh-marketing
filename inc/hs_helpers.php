<?php
/**
 * inc/hs_helpers.php — formatting helpers for the Hot Sheet email.
 * Ported unchanged from monthausint.com/hot-sheets/templates/_email_helpers.php,
 * except the placeholder image, which is now served from this site.
 */

if (!function_exists('mh_e')) {
    function mh_e(?string $val): string {
        return htmlspecialchars($val ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// "$1,234,567" — no decimals, comma-grouped. Returns '' for null.
if (!function_exists('mh_money')) {
    function mh_money(?float $price): string {
        if ($price === null) return '';
        return '$' . number_format($price, 0);
    }
}

// Uppercases a raw MlsStatus value for display, with a couple of friendlier
// labels for the statuses the hot sheet specifically calls out.
if (!function_exists('mh_status_label')) {
    function mh_status_label(string $status): string {
        $map = [
            'closed' => 'Sold',
        ];
        $key = strtolower(trim($status));
        return strtoupper($map[$key] ?? $status);
    }
}

// Mont Haus brand colors used for the hot sheet's status/price highlighting.
// Kept in one place so the template and anything that assembles data for it
// (currently hot_sheet_preview.php) agree on the same hex values.
if (!function_exists('mh_hot_sheet_colors')) {
    function mh_hot_sheet_colors(): array {
        return [
            'black'  => '#1a1a1a',
            'blue'   => '#0184BB',
            'orange' => '#F49808',
            'green'  => '#36A55A',
            'gray'   => '#6b7280',
            'purple' => '#6D28D9',   // Pocket Listing badge
        ];
    }
}

// Abbreviate common street suffix words in an address for display.
// Operates word-boundary aware so "Street" in "Streetcar Lane" stays untouched.
// "Unit 9" → "# 9" is also handled.
if (!function_exists('mh_abbreviate_address')) {
    function mh_abbreviate_address(string $address): string {
        $map = [
            'Avenue'    => 'Ave',
            'Boulevard' => 'Blvd',
            'Circle'    => 'Cir',
            'Court'     => 'Ct',
            'Drive'     => 'Dr',
            'Highway'   => 'Hwy',
            'Lane'      => 'Ln',
            'Place'     => 'Pl',
            'Road'      => 'Rd',
            'Square'    => 'Sq',
            'Street'    => 'St',
            'Terrace'   => 'Ter',
            'Trail'     => 'Trl',
            'Way'       => 'Way',   // already short — keep but list for completeness
            'Suite'     => 'Ste',
        ];
        foreach ($map as $long => $short) {
            $address = preg_replace('/\b' . preg_quote($long, '/') . '\b/i', $short, $address);
        }

        // Unit designators are handled separately, not through the map above.
        // A straight "Unit" → "#" swap doubles the marker when the source
        // already wrote one: Paperless sends "1000 Homestead Drive, Unit #13",
        // which came out as "1000 Homestead Dr, # #13". Consume any "#" that
        // follows the word so only one marker survives.
        $address = preg_replace('/\b(?:Unit|Apt|Apartment)\b\.?\s*#?\s*/i', '#', $address);

        // Collapse anything that still doubled up, and close the gap in "# 13"
        // so the unit reads as one token.
        $address = preg_replace('/#\s*#+/', '#', $address);
        $address = preg_replace('/#\s+(?=\w)/', '#', $address);

        // Clean up double-spaces left behind by any of the above
        return preg_replace('/\s{2,}/', ' ', trim($address));
    }
}

// 1x1 light-gray placeholder used when a listing has no cached photo yet
// (e.g. synced before primary_photo_url existed, or Spark had no photos).
if (!function_exists('mh_photo_placeholder_url')) {
    function mh_photo_placeholder_url(): string {
        return HOT_SHEET_ASSET_BASE . 'photo_placeholder.png';
    }
}
