# The Social Media Plan templates (admin side, 2026-11-02)

Nikki, 2026-11-02: "Using this plan as a starting point and the design
foundation in the current marketing templates, create on my Admin marketing
portal a series of dedicated admin-only social templates." The plan is her
docx "Mont Haus Social Media Plan"; its words are reproduced in
`plan_definition()` (inc/plan.php) and on social_plan.php.

## The plan, as written

Weekly volume: 8 pieces of content (3 property, 1 market stats, 1 location,
1 broker, 2 branding).

1. Property Posts + Stories, 3 per week (post and story): New Listings,
   Featured Listings, Just Sold; sales and/or rentals. Each story should be a
   multi-image slideshow, not a single photo.
2. Market Stats, 1 story per week: stats from our key markets, including
   submarkets; each market its own story (Aspen one story, Basalt another);
   rotate through markets week to week.
3. Location Feature, 1 story per week: office feature (images of the office
   or views from it); team in place (brokers based at that location); local
   real estate angle (a neighborhood, a notable sale, a local trend).
4. Broker Feature, 1 story per week: spotlight one broker and tie them to
   their market; celebrate milestones (notable closings, production
   recognition, awards, designations); rotate through six hooks, swapping in
   the broker's name and market ("Thinking of buying or selling in Montrose?
   Call Jackson Horn with Mont Haus." and five more, in `plan_hooks()`).
5. Branding, 2 stories per week, following the seasonal branding strategy.

Sample week: Monday Property (New Listing); Tuesday Market Stats + Branding;
Wednesday Property (Featured Listing); Thursday Location Feature; Friday
Property (Just Sold or Rental) + Branding; Saturday Broker Feature.

Note: broker photography should show brokers in their element (at listings,
around town, outdoors, local backdrops), not just straight headshots; plan
seasonal lifestyle shoots to build a photo library for each broker.

## What was built

Everything is admin only (`require_role('admin')`), reached from the roster
header's **Social Plan** button, the admin **Templates** page's "Social Media
Plan (admin only)" group, and the buttons on social_plan.php. Nothing is on
the agent portal.

| Page | What it makes |
|---|---|
| `social_plan.php` | This Week's Plan: the sample week, Monday to Saturday, each piece with the week's suggested pick and a button into the right tool with it preselected; previous / next week; the plan's words and notes under the table. |
| `template_property.php` | Property post + story SET, from EVERY Mont Haus listing in the feed (not one agent's): New Listing / Featured / Just Sold / Rental. On a story, "Make a story set" ticks the photos in play order; cover + photo frames + end card, downloaded as a ZIP of PNGs. |
| `template_broker.php` | Broker Feature: a broker, their market words, a hook or a milestone, their headshot or a lifestyle shot or a stock photo of their market; the split layout (photo top, words below) by default. |
| `template_location.php` | Location Feature: an office, an area or a town; angle Office / Team in place / Local angle; the team's faces in a row above the headline (split layout). |
| `template_branding.php` | Branding: a season, one of its taglines, a stock photo suggested for the season; centred words and the logo, as General Social draws them. |
| `market_snapshot.php` | Market Stats: the existing Market Snapshot (unchanged); the plan page links the week's market with `?market=`. |

Endpoints (admin only, same checks): `plan_render.php` (POST, one frame as
HTML for the preview iframe), `plan_export.php` (POST, the PNG, or the story
set as a ZIP), `plan_photos.php` (GET, the photos to pick from by subject).
Uploads reuse the portal's `social_upload.php`, `social_delete.php` and
`social_asset.php` through `social_url()` with `?preview=admin`, exactly as
General Social on the admin side does (the admin's own context from
`social_context()`, uploads keyed by users.id, export copies in
CREATIVES_DIR/social/admin-<user id>/).

## How it is built

- `inc/plan.php`: the gate (`plan_gate()`), the plan's words, the week and
  rotations (`plan_week()`, `plan_rotate()`), the brokers (`plan_agents()`:
  active, approved agents with `market` from the service-area text and
  `region` for the stock library), the locations (`plan_locations()`:
  offices, the website's Communities areas and towns, each with the brokers
  based there, `plan_location_team()`), the listings (`plan_listings()`:
  hs_listing_state live, or closed within 30 days, with `new` from a
  new_listing change or first_seen within 21 days), the photo tiles, the
  request parser (`plan_post_from_request()`), the ZIP export.
- `inc/plan_render.php`: `plan_render_split()`, the one new layout. Shares
  `social_palette()` and `social_font_css()`, which were extracted from
  social_render() for it (the renders before and after the extraction were
  byte-identical on all 38 sample posts).
- Everything full-photo or centred is drawn by `social_render()` with the
  "other" family (`photo_other`, `square_other`, `story_other`), including
  every frame of a property story set: the cover is the post's words, a
  photo frame is the same template with no headline, no logo and a two-line
  caption, the end card is the centred layout with the logo centred.
- `inc/plan_body.php`: the one builder form for the four kinds (`$pk`). The
  JS follows social_body.php: a debounced POST to plan_render.php into the
  preview iframe, the drag + zoom framer, the photo grid with groups and the
  upload box. New: the Layout switch (Full photo / Photo + text block /
  Centered) and which logo places and how many lines each shows; the story
  set's play order on the tiles (`.sp-ord`), the frame pills under the
  preview (`frame` is posted), the team ticks (six at most), the hook and
  season fills.
- ONE render path per picture, as everywhere: never draw a post in
  JavaScript.
- `tests/render_plan_sample.php` renders the split layout in every size and
  look, a story set's three kinds of frame and a branding story, with no
  database. The builder itself was exercised locally with a stub router
  (no database) on 2026-11-02: tile order, pills, ZIP button, hooks, team
  ticks, seasons.

## Rotations (social_plan.php)

`plan_week()` counts weeks from the Monday of ISO week 1 of 2026; every
rotation is that index modulo its list, so a week always suggests the same
picks and the next week moves on by one. Markets step through
`market_card_defs()` (the Market Snapshot's 21 places), brokers through the
approved agents with a hosted headshot by name, locations through the offices
then the areas (the angle cycles once the list wraps), hooks through the
plan's six, and the branding season follows the calendar (`plan_season_for()`:
Holidays from November 20 through December). The listing suggestions are
live data: the newest new listing, the highest-priced live listing, the
latest closing (or a rental when there is none).

## Nikki's first review (2026-11-02, afternoon)

- The framed layout ("Photo + text block") was redrawn: the photo had sat
  at the very top, which put a headshot's face under the badge and under
  Instagram's story controls, and left a hole under the words. Now a band at
  the top holds the badge and the logo, the photo window sits below it and
  fades into the ground at its foot, and the words follow at once
  (`plan_split_geometry()` in inc/plan_render.php has the numbers).
- New admin-only controls on every plan builder: Logo colour (Auto / White
  / Black, so a white logo on the light look), Logo size, Nudge across and
  down, Text shadow and Logo shadow sliders, a Reset link. They travel as
  `logo_color`, `logo_scale` (percent), `logo_dx`, `logo_dy`, `text_shadow`,
  `logo_shadow`, checked by `social_logo_options()`; social_render() and
  plan_render_split() both honour them. The agents' tools post none, so
  their renders are unchanged.
- Badge: a third place, "Above headline" (`badge=body`), draws the pill
  inside the text block so it never covers a face.
- The light look's veil behind centred words went from .50 to .68 (the
  centred text was unreadable). The Broker Feature offers Dark only until
  the light look is redesigned (the likely fix: a light ground behind the
  headshot rather than a fade).
- The admin builders: smaller pills, lighter step numbers, room between a
  dropdown and the row under it (`plan_admin_css()`).

## The set, second pass (2026-11-02, evening)

Nikki: "Love this make a set option." Changes: the set is offered at the
post and square sizes too (a carousel); the frames after the cover are the
photo alone, with the white or black logo lower right on every other one
(`plan_render_photo_frame()`; none when the cover has no logo); the cover
plus up to eight more photos (`PLAN_MAX_FRAMES` = 9); the closing card is
optional and off by default.

## Sizes and the look

Post 1080 x 1440, Square 1080 x 1080, Story 1080 x 1920 with the 190 / 170 px
safe areas, as the Instagram tools. The framed layout: band, then the photo
window (860 / 780 / 520 px on story / post / square), then the words; with a
team row one or two lines fit, without one three or four;
`plan_render_split()` trims to what fits. Palette: Slate, deeper buttons (the website's scheme), through
`social_palette()`.

## Not done / ideas

- The seasonal taglines are drafts in `plan_seasons()`; Nikki's branding
  strategy should replace them (edit the arrays).
- Milestone openers are drafts too (`plan_milestones()`).
- A "posted" checklist per week (which of the eight went out) is not kept;
  the plan page suggests, it does not record.
- Office photos are the admin's uploads; a shared office library (like the
  stock library) could follow once there are photos to put in it.
