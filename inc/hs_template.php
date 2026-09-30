<?php
/**
 * inc/hs_template.php — renders a Hot Sheet email, Sale Listings or Rentals.
 *
 * Ported from monthausint.com/hot-sheets/templates/hot_sheet_template.php.
 * Changes: rows carry their own `url` (site.monthaus.com listing pages, no
 * Lofty lookup); images come from HOT_SHEET_ASSET_BASE; the "since" date is
 * passed in by the data builder instead of computed here; a footer carries the
 * unsubscribe link. Everything else, including the markup, is as it was.
 *
 * Pass $type = 'listings' (default) or 'rentals' to render_hot_sheet_email().
 *
 * This file only renders. All "what counts as an update, what color is this
 * status" business logic lives in hot_sheet_preview.php (and eventually the
 * send script) — keeps this a dumb function of its input.
 *
 * Expected $data shape passed to render_hot_sheet_email():
 *   [
 *     'sale_updates'    => [ <row>, ... ],
 *     'sale_listings'   => [ <row>, ... ],
 *     'rental_updates'  => [ <row>, ... ],
 *     'rental_listings' => [ <row>, ... ],
 *   ]
 *
 * Each <row>:
 *   [
 *     'address'           => 'Street, City',
 *     'price'             => float|null,
 *     'status'            => string,                  // raw MlsStatus, e.g. 'Pending'
 *     'status_color'      => 'blue'|'green'|null,     // null = default black
 *     'price_color'       => 'orange'|'green'|null,   // null = default black
 *     'brokers'           => string[],                // any number, MH-first order
 *     'primary_photo_url' => string|null,
 *     'url'               => string|null,             // public listing page
 *     'badge_label'       => string|null,             // ribbon text: 'New','Pending','Closed','Price Change'
 *     'badge_color'       => 'blue'|'green'|'orange'|null,
 *   ]
 */

require_once __DIR__ . '/hs_helpers.php';

/**
 * One listing card: photo + ribbon on the left, details in the middle,
 * "VIEW FULL LISTING" button on the right.
 */
function render_hot_sheet_card(array $row, bool $is_rental, bool $divider = false): string {
    $colors = mh_hot_sheet_colors();

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
          <?php if (!$is_rental):
            // Status line: use badge label (New Listing, New Price, Closed, etc.)
            // when set; fall back to raw MlsStatus for regular listing cards.
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

function render_hot_sheet_cards(array $rows, bool $is_rental): string {
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
               . render_hot_sheet_card($row, $is_rental, $i < $last)
               . '</td></tr>';
    }
    return $html;
}

/**
 * Builds the full HTML email document.
 *
 * @param array  $data  Keys: sale_updates, sale_listings, rental_updates, rental_listings
 * @param string $type  'listings' (Sale Listings email) or 'rentals' (Rentals email)
 */
function render_hot_sheet_email(array $data, string $type = 'listings', string $unsubscribe_url = ''): string {
    $is_rental = ($type === 'rentals');

    $sale_updates    = $data['sale_updates']    ?? [];
    $sale_listings   = $data['sale_listings']   ?? [];
    $rental_updates  = $data['rental_updates']  ?? [];
    $rental_listings = $data['rental_listings'] ?? [];

    $updates  = $is_rental ? $rental_updates  : $sale_updates;
    $listings = $is_rental ? $rental_listings : $sale_listings;

    // The window start is decided by the data builder (hs_update_window()),
    // so the heading and the rows under it can never disagree.
    $since_date = (string)($data['since_label'] ?? '');

    $base_url   = HOT_SHEET_ASSET_BASE;
    $banner_img = $is_rental ? 'header_hotsheet-rentals.png' : 'header_hotsheet-listings.png';
    $banner_url = $base_url . $banner_img;
    $banner_alt = $is_rental
        ? 'Mont Haus International Realty: Rentals Hot Sheet'
        : 'Mont Haus International Realty: Listings Hot Sheet';

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
  <title>Mont Haus | Hot Sheet</title>
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

              <?php if ($is_rental): ?>

                <?= render_hot_sheet_section_title('New Rentals', '28px 0 16px 0', $since_date !== '' ? 'added since ' . $since_date : null) ?>
                <?= render_hot_sheet_cards($updates, $is_rental) ?>

                <?= render_hot_sheet_section_title('All Rentals', '10px 0 18px 0') ?>
                <?= render_hot_sheet_cards($listings, $is_rental) ?>

              <?php else: ?>
                <?php
                // Sectioned layout, organised by how Mont Haus is involved.
                // Latest Updates repeats items that also appear in a section
                // below — same as the previous New Activity / All Listings split.
                $mls_rows    = $data['mls_listings']    ?? $listings;
                $pocket_rows = $data['pocket_listings'] ?? [];
                $buyer_rows  = $data['buyer_rep']       ?? [];
                ?>

                <?php
                // Jump menu — only lists sections that actually have content.
                $jump = [['mls-listings', 'MLS Listings']];
                if ($pocket_rows) $jump[] = ['pocket-listings', 'Pocket Listings'];
                if ($buyer_rows)  $jump[] = ['buyer-rep',       "Buyer's Rep"];
                ?>
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
                <?= render_hot_sheet_cards($updates, false) ?>

                <?= render_hot_sheet_section_title('MLS Listings', '10px 0 18px 0', 'On-Market: active, pending, recently sold', 'mls-listings') ?>
                <?= render_hot_sheet_cards($mls_rows, false) ?>

                <?php if ($pocket_rows): ?>
                  <?= render_hot_sheet_section_title('Pocket Listings', '10px 0 18px 0', 'Off-Market: Not listed on the MLS', 'pocket-listings') ?>
                  <?= render_hot_sheet_cards($pocket_rows, false) ?>
                <?php endif; ?>

                <?php if ($buyer_rows): ?>
                  <?= render_hot_sheet_section_title('Buyer Representation', '10px 0 18px 0', 'Mont Haus represents the buyer', 'buyer-rep') ?>
                  <?= render_hot_sheet_cards($buyer_rows, false) ?>
                <?php endif; ?>

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
