<?php
/**
 * inc/hs_template.php — renders one Hot Sheet email: one AREA (2026-10-07).
 *
 * Ported from monthausint.com/hot-sheets/templates/hot_sheet_template.php and
 * reshaped for the per-area Hot Sheets (Nikki, 2026-10-07): the Sale Listings
 * and Rentals emails became one email per area, laid out as
 *
 *   banner · area name
 *   MLS Listings | Pocket Listings | Buyer's Rep | Rentals   (jump menu; only sections with content)
 *   Latest Updates   sale changes AND new rentals since Monday, newest first
 *   MLS Listings     on market: active, pending, recently sold
 *   Pocket Listings  off market                              (when any)
 *   Buyer Representation                                     (when any)
 *   Rentals          every rental in the area                (when any)
 *
 * The card markup is the hub's, unchanged. Rows carry their own `url`
 * (site.monthaus.com listing pages); images come from HOT_SHEET_ASSET_BASE;
 * the "since" date is passed in by the data builder; the footer carries the
 * unsubscribe link.
 *
 * This file only renders. What counts as an update, which area a row is in
 * and what colour a status gets is inc/hs_data.php's business: this stays a
 * dumb function of its input.
 *
 * $a is hs_area_data():
 *   [
 *     'area_name'       => 'Vail Valley',
 *     'since_label'     => '10/6/2026',
 *     'updates'         => [ <row>, ... ],
 *     'mls_listings'    => [ <row>, ... ],
 *     'pocket_listings' => [ <row>, ... ],
 *     'buyer_rep'       => [ <row>, ... ],
 *     'rentals'         => [ <row>, ... ],
 *   ]
 *
 * Each <row>:
 *   [
 *     'address'           => 'Street',
 *     'city'              => 'Aspen', 'state_abbr' => 'CO', 'postal_code' => '',
 *     'is_rental'         => bool,                    // a rental card shows no status line (unless badged) and no price
 *     'price'             => float|null,
 *     'close_price'       => float|null,
 *     'status'            => string,                  // raw MlsStatus, e.g. 'Pending'
 *     'status_color'      => 'blue'|'green'|null,     // null = default black
 *     'price_color'       => 'orange'|'green'|null,   // null = default black
 *     'brokers'           => string[],                // any number, MH-first order
 *     'broker_emails'     => [name => email],
 *     'primary_photo_url' => string|null,
 *     'url'               => string|null,             // public listing page
 *     'badge_label'       => string|null,             // 'New Listing', 'New Rental', 'Pending', 'Closed', 'New Price'
 *     'badge_color'       => 'blue'|'green'|'orange'|null,
 *     'type_badge_label'  => 'Buyer Rep'|'Pocket Listing'|null,
 *   ]
 */

require_once __DIR__ . '/hs_helpers.php';

/**
 * One listing card: photo + ribbon on the left, details in the middle,
 * "VIEW LISTING" button on the right.
 */
function render_hot_sheet_card(array $row, bool $divider = false): string {
    $colors = mh_hot_sheet_colors();
    $is_rental = !empty($row['is_rental']);

    $photo       = $row['primary_photo_url'] ?: mh_photo_placeholder_url();
    $address     = mh_e(mh_abbreviate_address($row['address'] ?? ''));
    $city        = trim($row['city']        ?? '');
    $state_abbr  = trim($row['state_abbr']  ?? '');
    $postal_code = trim($row['postal_code'] ?? '');
    // "Aspen, CO 81611" — omit gracefully if any part is missing
    $location_parts = array_filter([$city, $state_abbr ? ($state_abbr . ($postal_code ? ' ' . $postal_code : '')) : $postal_code]);
    $location_line  = mh_e(implode(', ', $location_parts));

    $badge_label = $row['badge_label'] ?? null;
    $badge_hex   = $colors[$row['badge_color'] ?? 'gray'] ?? $colors['gray'];

    $status_hex = $row['status_color'] ? ($colors[$row['status_color']] ?? $colors['black']) : $colors['black'];
    $price_hex  = $row['price_color']  ? ($colors[$row['price_color']]  ?? $colors['black']) : $colors['black'];

    // Every MLS row carries its public listing page in `url` (built by
    // site.monthaus.com from LISTING_URL_BASE). Manual rows may carry
    // listing_url. Pocket listings are off market: no button at all.
    $manual_url  = trim((string)($row['url'] ?? $row['listing_url'] ?? ''));
    $hide_link   = !empty($row['hide_link']);
    if ($hide_link) {
        $has_link    = false;
        $listing_url = '';
    } elseif ($manual_url !== '') {
        $has_link    = true;
        $listing_url = $manual_url;
    } else {
        $has_link    = false;
        $listing_url = '';
    }

    $brokers = $row['brokers'] ?? [];

    ob_start();
    ?>
    <table class="card-wrap" role="presentation" border="0" cellpadding="0" cellspacing="0" width="560" style="width:560px; margin-bottom:22px;">
      <tr>
        <!-- Photo -->
        <td class="card-photo" width="200" valign="top" style="padding:0; line-height:0; font-size:0;">
          <?php if ($has_link): ?>
          <a href="<?= mh_e($listing_url) ?>" target="_blank" style="display:block; line-height:0; font-size:0;">
          <?php endif; ?>
          <img src="<?= mh_e($photo) ?>" width="200" height="130" alt=""
               style="display:block; width:200px; height:130px; object-fit:cover; background-color:#eeeeee;" />
          <?php if ($has_link): ?>
          </a>
          <?php endif; ?>
        </td>

        <td class="card-spacer" width="20" style="font-size:0; line-height:0;">&nbsp;</td>

        <!-- Details -->
        <td class="card-details" valign="top" style="font-family:Arial, Helvetica, sans-serif; font-size:14px; color:#1a1a1a; padding-top:2px;">
          <?php
            // Involvement pill — manual entries only. Solid background, white
            // text: orange for Buyer Rep, purple for Pocket Listing. Rendered
            // as a table so Outlook honours the background colour.
            $type_label = trim((string)($row['type_badge_label'] ?? ''));
            $type_hex   = $colors[$row['type_badge_color'] ?? 'gray'] ?? $colors['gray'];
          ?>
          <?php if ($type_label !== ''): ?>
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin-bottom:5px;">
              <tr><td style="background-color:<?= $type_hex ?>; padding:3px 8px;
                             font-family:Arial, Helvetica, sans-serif; font-size:10px; font-weight:bold;
                             color:#ffffff; text-transform:uppercase; letter-spacing:.5px; white-space:nowrap;">
                <?= mh_e($type_label) ?>
              </td></tr>
            </table>
          <?php endif; ?>
          <?php if (!$is_rental || $badge_label):
            // Status line: use badge label (New Listing, New Rental, New Price,
            // Closed, etc.) when set; fall back to raw MlsStatus for regular
            // sale cards. A rental card in a section shows no status at all.
            $status_text = $badge_label
                ? strtoupper($badge_label)
                : mh_status_label($row['status'] ?? '');
            $status_line_hex = $badge_label ? $badge_hex : $status_hex;
          ?>
            <div class="m-status" style="font-size:11px; font-weight:bold; color:<?= $status_line_hex ?>; text-transform:uppercase; letter-spacing:.4px; margin-bottom:4px;">
              <?= mh_e($status_text) ?>
            </div>
          <?php endif; ?>
          <div class="m-addr" style="font-weight:bold; font-size:15px; color:#1a1a1a; margin-bottom:2px;"><?= $address ?></div>
          <?php if ($location_line): ?>
          <div class="m-loc" style="font-size:13px; color:#6b7280; margin-bottom:4px;"><?= $location_line ?></div>
          <?php endif; ?>
          <?php if (!$is_rental):
            $is_closed   = strcasecmp($row['status'] ?? '', 'Closed') === 0;
            $close_price = $row['close_price'] ?? null;
            if ($is_closed && $close_price !== null):
          ?>
            <div style="font-size:10px; color:#6b7280; text-transform:uppercase; letter-spacing:.3px; margin-bottom:1px;">Sold Price</div>
            <div class="m-price" style="font-size:14px; color:#1a1a1a; margin-bottom:6px;">
              <?= mh_e(mh_money($close_price)) ?>
            </div>
          <?php elseif ($row['price'] !== null): ?>
            <div class="m-price" style="font-size:14px; color:<?= $price_hex ?>; margin-bottom:6px;">
              <?= ($badge_label === 'New Price' ? 'Now ' : '') . mh_e(mh_money($row['price'])) ?>
            </div>
          <?php endif; ?>
          <?php endif; ?>

          <?php
            // Names that resolve to a roster email become mailto links.
            // Anyone without a roster email renders as plain text.
            $broker_emails = $row['broker_emails'] ?? [];
          ?>
          <?php foreach ($brokers as $broker_name):
              $bmail = $broker_emails[$broker_name] ?? null; ?>
            <div class="m-broker" style="font-size:13px; color:#1a1a1a;">
              <?php if ($bmail): ?>
                <a href="mailto:<?= mh_e($bmail) ?>" style="color:#1a1a1a; text-decoration:none;"><?= mh_e($broker_name) ?></a>
              <?php else: ?>
                <?= mh_e($broker_name) ?>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </td>

        <td class="card-spacer" width="20" style="font-size:0; line-height:0;">&nbsp;</td>

        <!-- Button -->
        <td class="card-btn" width="110" valign="top" align="right" style="padding-top:2px;">
          <?php if ($has_link): ?>
          <a href="<?= mh_e($listing_url) ?>" target="_blank"
             style="display:inline-block; background-color:#1a1a1a; color:#ffffff; text-decoration:none;
                    font-family:Arial, Helvetica, sans-serif; font-size:10px; font-weight:bold;
                    letter-spacing:.5px; text-transform:uppercase; padding:10px 12px; white-space:nowrap;">
            View Listing
          </a>
          <?php elseif (!$hide_link): ?>
          <span style="display:inline-block; background-color:#cccccc; color:#666666;
                       font-family:Arial, Helvetica, sans-serif; font-size:10px; font-weight:bold;
                       letter-spacing:.5px; text-transform:uppercase; padding:10px 12px; white-space:nowrap;">
            Link Pending
          </span>
          <?php endif; ?>
        </td>
      </tr>
      <?php if ($divider): ?>
      <!-- Hairline separator. Drawn as a filled cell rather than a CSS border,
           because Outlook drops borders on table cells inconsistently. -->
      <tr>
        <td colspan="5" style="padding:18px 0 0 0;">
          <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
            <tr>
              <td style="height:1px; line-height:1px; font-size:0; background-color:#e8e8e8;">&nbsp;</td>
            </tr>
          </table>
        </td>
      </tr>
      <?php endif; ?>
    </table>
    <?php
    return ob_get_clean();
}

function render_hot_sheet_section_title(string $text, string $padding = '28px 0 16px 0', ?string $subtitle = null, ?string $anchor = null): string {
    // Both name and id — older clients honour name, newer ones id. Gmail's web
    // client strips both, so the jump menu is a convenience, not a guarantee.
    $inner = '';
    if ($anchor !== null) {
        $inner .= '<a name="' . mh_e($anchor) . '" id="' . mh_e($anchor) . '"></a>';
    }
    $inner .= '<div style="font-size:20px; font-weight:bold; line-height:1.2;">' . mh_e($text) . '</div>';
    if ($subtitle !== null) {
        $inner .= '<div style="font-size:11px; color:#6b7280; margin-top:3px; letter-spacing:.1px;">' . mh_e($subtitle) . '</div>';
    }
    return '<tr><td style="padding:' . $padding . '; font-family:Arial, Helvetica, sans-serif; color:#1a1a1a;">'
         . $inner . '</td></tr>';
}

function render_hot_sheet_subhead(string $text): string {
    return '<tr><td style="padding:10px 0 14px 0; font-family:Georgia, \'Times New Roman\', serif; '
         . 'font-style:italic; font-size:16px; color:#1a1a1a;">' . mh_e($text) . '</td></tr>';
}

function render_hot_sheet_cards(array $rows): string {
    if (empty($rows)) {
        return '<tr><td style="font-family:Arial, Helvetica, sans-serif; font-size:13px; color:#999999; padding:4px 0 18px;">'
             . 'Nothing to show right now.</td></tr>';
    }
    $html = '';
    $last  = count($rows) - 1;
    foreach ($rows as $i => $row) {
        // No divider under the final card — the section heading that follows
        // already separates it.
        $html .= '<tr><td style="padding:0;">'
               . render_hot_sheet_card($row, $i < $last)
               . '</td></tr>';
    }
    return $html;
}

/**
 * Builds the full HTML email document for one area.
 *
 * @param array $a  hs_area_data(): area_name, since_label, updates, mls_listings, pocket_listings, buyer_rep, rentals
 */
function render_hot_sheet_email(array $a, string $unsubscribe_url = ''): string {
    $area_name   = (string)($a['area_name'] ?? '');
    $updates     = $a['updates']         ?? [];
    $mls_rows    = $a['mls_listings']    ?? [];
    $pocket_rows = $a['pocket_listings'] ?? [];
    $buyer_rows  = $a['buyer_rep']       ?? [];
    $rental_rows = $a['rentals']         ?? [];

    // The window start is decided by the data builder (hs_update_window()),
    // so the heading and the rows under it can never disagree.
    $since_date = (string)($a['since_label'] ?? '');

    $base_url   = HOT_SHEET_ASSET_BASE;
    $banner_url = $base_url . 'header_hotsheet-listings.png';
    $banner_alt = 'Mont Haus International Realty: Hot Sheet';

    // Jump menu — only lists sections that actually have content.
    $jump = [['mls-listings', 'MLS Listings']];
    if ($pocket_rows) $jump[] = ['pocket-listings', 'Pocket Listings'];
    if ($buyer_rows)  $jump[] = ['buyer-rep',       "Buyer's Rep"];
    if ($rental_rows) $jump[] = ['rentals',         'Rentals'];

    ob_start();
    ?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml"
      xmlns:o="urn:schemas-microsoft-com:office:office" lang="en">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="format-detection" content="telephone=no, date=no, address=no, email=no" />
  <title>Mont Haus | Hot Sheet<?= $area_name !== '' ? ' | ' . mh_e($area_name) : '' ?></title>
  <!--[if mso]>
  <noscript><xml><o:OfficeDocumentSettings><o:AllowPNG/><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
  <![endif]-->
  <style type="text/css">
    body, table, td, a { -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
    table, td { mso-table-lspace:0pt; mso-table-rspace:0pt; }
    img { -ms-interpolation-mode:bicubic; border:0; outline:none; text-decoration:none; }
    body { margin:0 !important; padding:0 !important; width:100% !important; }
    @media screen and (max-width:480px) {
      /* Container */
      .email-container { width:100% !important; max-width:100% !important; min-width:320px !important; }

      /* Card: stack cells vertically */
      .card-wrap { width:100% !important; }
      .card-wrap .card-photo { display:block !important; width:100% !important; }
      .card-wrap .card-photo img { width:100% !important; height:180px !important; max-width:100% !important; }
      .card-wrap .card-spacer { display:none !important; width:0 !important; max-width:0 !important; overflow:hidden !important; }
      .card-wrap .card-details { display:block !important; width:100% !important; padding:10px 0 6px 0 !important; }
      .card-wrap .card-btn { display:block !important; width:100% !important; padding:8px 0 0 0 !important; text-align:center !important; }
      .card-wrap .card-btn a,
      .card-wrap .card-btn span { display:block !important; width:100% !important; box-sizing:border-box !important;
                                   text-align:center !important; font-size:13px !important; padding:14px 20px !important; }

      /* Bigger text on mobile */
      .m-status { font-size:12px !important; }
      .m-addr   { font-size:17px !important; }
      .m-loc    { font-size:14px !important; }
      .m-price  { font-size:16px !important; }
      .m-broker { font-size:14px !important; }
      .m-area   { font-size:24px !important; }
    }
  </style>
</head>
<body id="body" style="margin:0; padding:0; background-color:#f4f4f4; font-family:Arial, Helvetica, sans-serif;">

<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f4f4f4;">
  <tr>
    <td align="center" valign="top" style="padding:0;">

      <!--[if (gte mso 9)|(IE)]>
      <table align="center" border="0" cellspacing="0" cellpadding="0" width="600"><tr><td align="center" valign="top" width="600">
      <![endif]-->

      <table class="email-container" role="presentation" border="0" cellpadding="0" cellspacing="0" width="600"
             style="max-width:600px; width:100%; background-color:#ffffff;">

        <!-- Banner -->
        <tr>
          <td align="center" valign="top" style="padding:0; line-height:0; font-size:0;">
            <img src="<?= mh_e($banner_url) ?>" alt="<?= mh_e($banner_alt) ?>" width="600"
                 style="display:block; width:100%; max-width:600px; height:auto;" />
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:8px 20px 30px 20px;">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">

              <?php if ($area_name !== ''): ?>
              <!-- Area -->
              <tr>
                <td style="padding:22px 0 0 0; font-family:Georgia, 'Times New Roman', serif; color:#1a1a1a;">
                  <div class="m-area" style="font-size:28px; line-height:1.15;"><?= mh_e($area_name) ?></div>
                  <div style="font-family:Arial, Helvetica, sans-serif; font-size:11px; color:#6b7280; margin-top:4px; letter-spacing:.4px; text-transform:uppercase;">Mont Haus Hot Sheet</div>
                </td>
              </tr>
              <?php endif; ?>

              <?php if (count($jump) > 1): ?>
              <tr>
                <td style="padding:18px 0 0 0;">
                  <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                    <tr>
                      <?php foreach ($jump as $ji => $item): ?>
                        <?php if ($ji > 0): ?>
                        <td style="font-family:Arial, Helvetica, sans-serif; font-size:11px; color:#d1d5db; padding:0 8px;">|</td>
                        <?php endif; ?>
                        <td style="font-family:Arial, Helvetica, sans-serif; font-size:11px; letter-spacing:.4px;
                                   text-transform:uppercase; font-weight:bold; white-space:nowrap;">
                          <a href="#<?= mh_e($item[0]) ?>" style="color:#0184BB; text-decoration:none;"><?= mh_e($item[1]) ?></a>
                        </td>
                      <?php endforeach; ?>
                    </tr>
                  </table>
                </td>
              </tr>
              <?php endif; ?>

              <?= render_hot_sheet_section_title('Latest Updates', '28px 0 16px 0', $since_date !== '' ? 'since ' . $since_date : null) ?>
              <?= render_hot_sheet_cards($updates) ?>

              <?= render_hot_sheet_section_title('MLS Listings', '10px 0 18px 0', 'On-Market: active, pending, recently sold', 'mls-listings') ?>
              <?= render_hot_sheet_cards($mls_rows) ?>

              <?php if ($pocket_rows): ?>
                <?= render_hot_sheet_section_title('Pocket Listings', '10px 0 18px 0', 'Off-Market: Not listed on the MLS', 'pocket-listings') ?>
                <?= render_hot_sheet_cards($pocket_rows) ?>
              <?php endif; ?>

              <?php if ($buyer_rows): ?>
                <?= render_hot_sheet_section_title('Buyer Representation', '10px 0 18px 0', 'Mont Haus represents the buyer', 'buyer-rep') ?>
                <?= render_hot_sheet_cards($buyer_rows) ?>
              <?php endif; ?>

              <?php if ($rental_rows): ?>
                <?= render_hot_sheet_section_title('Rentals', '10px 0 18px 0', $area_name !== '' ? 'Mont Haus rentals in ' . $area_name : 'Mont Haus rentals', 'rentals') ?>
                <?= render_hot_sheet_cards($rental_rows) ?>
              <?php endif; ?>

            </table>
          </td>
        </tr>

        <?php if ($unsubscribe_url !== ''): ?>
        <!-- Footer. The unsubscribe link is required before this goes to anyone
             outside Mont Haus, and is the right thing to carry anyway. -->
        <tr>
          <td align="center" style="padding:18px 20px 26px 20px; border-top:1px solid #eeeeee;
                                    font-family:Arial, Helvetica, sans-serif; font-size:11px; color:#9ca3af;">
            Mont Haus International Realty
            &nbsp;·&nbsp;
            <a href="<?= mh_e($unsubscribe_url) ?>" style="color:#9ca3af; text-decoration:underline;">Unsubscribe</a>
          </td>
        </tr>
        <?php endif; ?>

      </table>

      <!--[if (gte mso 9)|(IE)]>
      </td></tr></table>
      <![endif]-->

    </td>
  </tr>
</table>

</body>
</html>
    <?php
    return ob_get_clean();
}
