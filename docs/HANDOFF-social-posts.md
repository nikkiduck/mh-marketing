# Social posts on the agent portal (2026-10-11)

Nikki, 2026-10-11: instead of Canva templates through the Canva Connect API
(researched the same day: possible, but autofill needs every agent to connect
their own Canva account, and a private integration needs Canva Enterprise),
the portal makes the posts itself from data it already has. Agents post the
picture to Instagram themselves; no auto-posting, by decision.

## What it does (as reworked 2026-10-12 to Nikki's notes)

`/portal/social.php`: four steps on one page with a live preview.

1. Template and look. Listing General and Open House (named so 2026-10-12; keys photo and photo_openhouse; Nikki,
   2026-10-12, "something different and prettier"): the photo fills the
   post and the headline plus two lines sit over a fade at the bottom. The
   first family, Featured Listing and Open House (photo on top, a text
   block below with up to four lines), is kept in `social_templates()` with
   `offered => false`: not on the picker, refused if posted; flip the flag
   to bring it back. Look: dark ink, or light on the brand's
   cream (on Full Photo the fade is cream and the text ink).
2. Listing: a dropdown of the account's live listings (hs_listing_state,
   the site's Mont Haus feed; list or co-list agent id in agent_mls_ids for
   the account and its teams, or the agent's name; cross-board copies
   collapsed).
3. Photo: the listing's photos from the site (`api/listings.php?scope=photos`)
   plus the agent's own uploads (drop zone or file picker; JPG, PNG, WEBP up
   to 20 MB; phone rotation honoured), then a framing box: drag to move,
   zoom slider or scroll wheel to crop closer. The box applies the same
   CSS as the post (zoom x the frame, cover, focus), so it is faithful.
4. Words: a label for the photo (ready-made ones including Open House, no
   label, or a custom one up to 28 characters), the Open House date and
   times (only on that template), a Headline, and Text 1 to 4 each with a
   style dropdown (Small Caps Gold, Large Serif, Light, Italic Serif, Plain)
   defaulting
   to town / price / details / note. The first three are pre-filled from
   the listing. A blank line takes no room, so the ones below move up.

The post: the label pill top-left and the Mont Haus logo top-right, both
on a soft fade across the top of the photo (ink on dark, cream on light;
the bottom fade's lighter twin; settled 2026-10-13 after a drop shadow on
the logo was rejected), so the words at the bottom have the full width:
a short rule, the headline (balanced over two lines only when very long,
the unit number never alone) and the lines. No agent
headshot or contact details (Nikki, 2026-10-12).

Download PNG posts the form to `portal/social_export.php`. (A Share… button
for the phone share sheet was tried and removed 2026-10-12: it opened the
sheet and then errored.)

## How it is built

- `inc/social_render.php`: the templates. One function, `social_render()`,
  turns a normalised post into a complete HTML document of exactly
  1080 x 1440. Standalone (no DB, no session) so
  `tests/render_social_sample.php` can render sample data anywhere.
- `inc/social.php`: the gate, the agent's profile, the listings query, the
  photo fetch (kept in the session so a posted photo is checked against
  it), `social_post_from_request()` (every field validated, lengths capped,
  unknown values replaced by defaults), the Chromium export, and a copy of
  each export under `CREATIVES_DIR/social/<intake id>/`.
- `portal/social_render.php` (preview HTML), `portal/social_export.php`
  (PNG), `portal/social_photos.php` (JSON: the listing's photos, then the
  uploads), `portal/social_upload.php` (store an upload) and
  `portal/social_asset.php` (serve one, this account's only) all run
  `portal_context()` then `social_gate()`; the POST endpoints check the
  CSRF token. Uploads live in CREATIVES_DIR/social/<intake id>/uploads/ as
  <hex>.jpg (2000 px) + <hex>-thumb.jpg; the export reads the file from
  disk (`social_render($p, null, true)` writes a file:// URL) because
  Chromium has no portal session.
- `assets/css/social.css`, `assets/fonts/mh/` (Cormorant Garamond and Jost
  as self-hosted variable woff2, so the render never waits on Google Fonts).
- ONE render path: the preview iframe's srcdoc and the exported PNG are the
  same HTML. Never draw the post in JavaScript.

## The export

Headless Chromium (Debian package `chromium`, installed 2026-10-11 with
Nikki's OK, 154.0) screenshots the HTML as www-data:

    chromium --headless=new --no-sandbox --disable-gpu --disable-dev-shm-usage
      --hide-scrollbars --force-device-scale-factor=1 --window-size=1080,1440
      --virtual-time-budget=10000 --user-data-dir=<tmp>/profile
      --screenshot=<out>.png file://<in>.html

HOME is pointed at the temp dir (`<system temp>/mh-social`, under Apache's
private /tmp), one render at a time through a flock (the instance has under
1 GB of RAM; a render takes about 3 s and 1.2 to 2 MB), 60 s cap through
`timeout`. The logo loads over https from this site and the photo from the public
site's img.php at 1600 wide; the FONTS are embedded in the export HTML as
base64 data URIs (about 140 KB). The export page is a local file, and web
fonts are the one resource a browser fetches under cross-origin rules, so
from file:// they fell back to Liberation on the real download path
(2026-10-12: Times-like type) while CLI test renders looked fine. A failure logs
`social_export: chromium rc=…` to Apache's PHP error log and the agent sees a
"could not be made, try again" page.

## Opened 2026-10-12

After Nikki's review the page went to every portal login (the team beta
allowlist): nav entry "Instagram Posts" in `portal_header()`, a home card
on portal/index.php, `SOCIAL_BETA_EMAILS` emptied. The gate below still
works if it ever needs hiding again.

## Hidden while it was built

`SOCIAL_BETA_EMAILS` in inc/config.php (Nikki alone). Not in the nav; anyone
else gets a plain 404 from `social_gate()`. Nikki's login has no linked
agent account, so she opens it as an admin preview:
`/portal/social.php?preview=30` (Jonathan, who has the Weber Boxer listings).
To open it up: add the nav entry in `portal_header()` (`'social' =>
['Social posts', '/portal/social.php']`), a home card, and clear the
constant.

## Not done yet / ideas

- More templates, a square 1080 x 1080 and a Story 1080 x 1920 variant.
- "Your recent posts": list the kept copies. Deleting an upload.
- Pinch to zoom on phones (the slider works there today).
- The Aspen board's photos carry a "Property of Aspen/Glenwood MLS"
  watermark in the image itself; nothing we can do except pick another photo.

## Listing Flyer (2026-10-13)

A second template on the same foundation: `portal/flyer.php`, a printed
Letter-size PDF after the InDesign display template (Nikki's "Listings
Display Flyer" folder). Same listings, photos, uploads and gate as the
Instagram tool; its own layout (`inc/flyer_render.php`, 612 x 792 pt, Jost)
and rules (`inc/flyer.php`). The agent picks up to six photos in order (the
first is the hero, then rows of two and three; fewer photos, fewer rows),
may add a headline and notes under the details, and chooses the QR code:
the listing's page on the site, one of their dynamic QR codes, or none.
The PDF is Chromium's print of the same HTML the preview shows
(`--print-to-pdf`); the QR is an inline SVG from `qrencode` (installed
2026-10-13) with the triangle mark in the centre. The hero photo uses the
site's full 2048 rendition (img.php allows it since 2026-10-13), about 220
dpi across the page: fine for a flyer, not for a glossy brochure.

Later on 2026-10-13 (Nikki): a coloured banner under the figures box (preset
words and colours, or custom words in a preset colour; Pending, Under
Contract and rentals start with theirs), a headline suggested from the
opening of the listing description (the agent edits it; typing marks it as
theirs so a listing change does not overwrite it), the headline on one line
at whatever size fits, the address and price larger. Templates in the menu
is now a landing page (portal/templates.php) with the two tools, and each
tool page switches to the other.

## Story templates (2026-10-15)

One more card on the picker, Story (1080 x 1920; a Story Open House is
built but not offered, Nikki: one Story, its lines free text): the size
follows the template (a size switch was tried for an hour and dropped:
"this is a totally different template", Nikki). Same looks and controls as
the posts. The label, words and both fades are inset by Instagram's chrome,
about 230 px at the top and 170 at the bottom, so those bands show only the
photo; there is no logo: a large ghosted triangle mark (970 px wide, base on the
bottom edge, right corner at x = 1300) bleeds off the lower right like the
brochure cover, and the label pill sits upper right.
`social_template_format()` and `social_format_*()` in inc/social_render.php;
the framing box, the preview frame and Chromium's window size follow the
template; the file name gets "-story".

Later on 2026-10-15 (Nikki): the page is "Instagram Templates", step 1 is
"Pick Template" with Post: Listing, Post: Open House and Story; the
Templates landing card reads "Instagram Post/Story". The Story has a
Watermark choice (shown for the Story only): the ghosted triangle
(default), the M mark flush to the lower-right corner, or none.

Also 2026-10-15 (Nikki): the Templates landing is in two groups, Instagram
Templates and Print Templates (the Listing Flyer); the pill switch between
the two tools is gone, each page has an "All templates" link back, and the
flyer page carries a "Print Templates" eyebrow.

Also 2026-10-15 (Nikki): the watermark choice applies to the posts as well
(triangle default, M mark at 745 px flush to the corner, or none); a story
may carry the full logo above the rule (a checkbox, on by default); the
light-look triangle uses a tight-canvas SVG (triangle_mark-dark.svg) so it
sits on the edge like the white one.

## Pick Template simplified (2026-10-15, evening)

Tag `templates-v1-20261015` holds the version before this (Nikki: "save this
spot"). Step 1 is now a Size switch (Post 4:5 / Story 9:16) and a Template
dropdown (Listing / Open House / Other / General) in place of the cards; the
look, watermark and story-logo choices follow. Listing and Open House work
as before (listing dropdown, its photos and uploads, pre-filled words).
Other hides the listing step, offers only the agent's uploads and leaves
every line blank with no label. Open House is back on the story, with the
date and time under the badge at upper right; on both sizes the date and
time line is free text ("Today 11am-2pm"), built from the pickers until the
agent types in it.

Later on 2026-10-15: Other starts with the Mont Haus mountains photo
(assets/images/social-placeholder.jpg, a selectable tile ahead of the
uploads), "Alpine Roots. Global Vision." and "Mont Haus International
Realty"; the story's date and time line is bold in the text colour with a
soft radial shade behind that corner rather than a text shadow.

## Stock mountain photos (2026-10-15)

The Other template offers the Mont Haus stock library after the agent's own
uploads: 40 Adobe Stock photos from Nikki's "Mont Haus/Mtn Photos" folder
(renamed there by location, season and stock ID the same day), copied to
the server as assets/stock/<name>.jpg (2048 px, q82) and thumbs/<name>.jpg
(400 px), with assets/stock/stock.json holding a caption per name that the
picker shows as a hover tooltip. The images are git-ignored (45 MB); the
manifest is tracked. Colorado and abroad alike (Nikki: a global mountain
real estate company based in Colorado). To add one: a 2048 jpg, a 400
thumb, a line in stock.json, scp all three.

Also 2026-10-15: the story's Mont Haus logo is a three-way choice, upper
right (badge moves to upper left, date line under it at the left), lower
left above the rule (default), or none; the checkbox is gone.

## Uploads belong to the person (2026-10-18)

Uploads were keyed by the portal account, so an admin previewing an agent
uploaded into the agent's folder: Nikki's test photos appeared on
Jonathan's portal as "Yours". They are keyed by users.id now
(CREATIVES_DIR/social/users/<id>/uploads), the two files were moved to
Nikki's folder, and the photo grid is in groups with headings: Your
uploads, then Listing photos or Mont Haus stock photos. A separate Your
Uploads tab can come when the lists grow (Nikki raised it).
