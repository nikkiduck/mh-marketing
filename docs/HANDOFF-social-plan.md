# The Social Media Plan templates (admin side, 2026-11-02)

**Where it lives since 2026-11-03:** its own area, social_media.php, from
the header's Social Media link (inc/_nav.php, super_admin only; Nikki:
"just for me the super admin"), and every plan page and endpoint requires
super_admin (`plan_gate()`). The roster's Social Plan button and the
Templates page's "Social Media Plan (admin only)" group are gone.

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

## The Broker Feature, from a clean sheet (2026-11-02, evening)

Nikki: "go 100% back to the drawing board on the Featured Agent design ...
keep 1) the agent's face from shoulders to top of head, completely
unobscured, nothing covering; and 2) our current color scheme." The broker
tool now has a Style switch (inc/plan_agent_render.php, `plan_render_agent()`)
instead of the layouts. In every style the headshot sits in its own window on
the Slate ground, positioned from the top so the head is whole, and the
badge, logo and words live around it, never on it:

- Editorial: a band with the badge left and the logo right; the photo in an
  inset window with a hairline frame; the words below, left-aligned beside
  a vertical accent bar.
- Arch: the label as a small-caps eyebrow; the photo in a tall outlined
  arch; the words centred under it; the logo centred at the foot.
- Card: the photo edge to edge in the upper part; a card in the other tone
  (light on dark, dark on light) rises over the chest line with the badge,
  the hook and the name; the logo on the ground below.
- Headline first: the hook large at the top, the photo under it, a solid
  strip at the foot with the firm line and the logo.

Both looks are offered again. The logo colour, size, nudge and shadow
sliders apply; the watermark sits on the ground behind everything.

Late that night Nikki asked for a cutout design ("something more creative",
the headshot in colour). The fifth style, **Cutout**: the broker cut out of
the photo in front of Slate geometry, two triangles after the mark lower
right, a slanted slate band behind the shoulders with the name and firm
line, the hook above the figure, badge and logo in the clear band at the
top. The cutout itself is a transparent PNG at agent-photos/<slug>-cutout.png,
made from the headshot with Adobe's background removal (the Adobe tools in a
Claude session: upload the headshot as an asset, image_remove_background,
download the PNG, deploy it); `plan_agents()` reports `cutout_url` when the
file exists, the picker offers it first, the style uses it in place of a
picked headshot, and the form defaults to Cutout for brokers who have one.
All 26 approved brokers' cutouts were made and deployed on 2026-11-02 (the
originals are untouched; Lynn Emmert's keeps her dog, Jean-Michel's is
cropped at the top of the head as the original is). A new broker or a new
headshot needs a new cutout the same way.

**Cutout alone (Nikki, later that night: "get rid of the other options,
cutout is the only one working right now"):** `plan_agent_styles()` lists
Cutout only, the builder hides the Style switch while there is one style
(a hidden `style` field carries it), and Editorial, Arch, Card and
Headline first are gone from the renderer (in git before that commit, if
ever wanted back). The hook's second line, the call ("Call Allison
Byford."), has a face of its own: **Second line** pills, Serif (as the
first line), Italic (the Cormorant italic) or Sans (Jost, a size smaller);
`hook2` in the post, `plan_hook_styles()`; the builder starts on Italic.
The sentences after the first all take it. Sans is caps, tracked .5em,
about half the serif's size (Nikki: "all caps and .5 spacing").

**Cutouts on upload (2026-11-05):** inc/cutout.php makes the cutout through
Leonardo.Ai (Canva; remove.bg's API closes 2026-12-01) whenever a profile photo is uploaded or re-cropped in the admin
(agent.php), on import (cron/import_profiles.php), or from the Make cutout
button on the Headshots card; cron/make_cutouts.php backfills by hand. Named
after the headshot file (<name>-cutout.png); the slug-named ones from
2026-11-02 keep working until a new photo replaces them. Needs
LEONARDO_API_KEY in inc/db.php (about 10 cents a picture, pay as you go);
without it the feature uses the headshot. Live since 2026-11-05: the API
wants `public` as a boolean and wraps a success in `generateSync`, which the
guide does not say; all 27 brokers have a cutout.

**2026-11-05:** the slate band is gone; the name and the service area sit
right-aligned under the hook (name small caps 34px, area 46px serif), the
title is off the default lines, and the backdrop photo is half desaturated
so the sky does not tint the dark ground (the overlay was already #1F242C).

**Later that day (Nikki: "name and service area lower right ... bigger
... maybe a slider"):** the name and the service area sit at the lower
right by default (**Name and area** pills, Lower right | Under the hook;
`who_place`), drawn above the figure with the text shadow so a shoulder
never hides them, and their sizes are the **Name size** and **Area size**
sliders (`who_name` 24 to 80, default 44; `who_area` 32 to 120, default
64; px at the story and post sizes). The Overlay default is 70 (was 82).
The Broker tool's Size pills put Story first. On every plan builder the
Logo size slider now starts at 130 % and runs to 200 (the renderers'
clamp is 2.0); Reset returns to 130.

**Same afternoon (Nikki: "removing the name and location from lower
right, put logo lower right instead; less shadow on the badge; condense
spacing between badge, hook and tagline; make headshot larger"):** the
Name and area pills gained Off, the default; the Logo place pill Lower
right (the `bottom` value) is the Broker tool's default at every size and
draws the logo at the lower right inside the safe zone; the badge shadow
is light; the hook sits 44px under the band on a story (36 post, 24
square) with the call line 10px under it; the figure is a tenth larger.

**The right stack (Nikki's mock-up, same afternoon):** Arrange pills Right
stack | Left (`arrange`, stack default). The stack is one right-aligned
column on the right: badge, hook (0.92 of its size), call line, logo under
the words ("With the words"; Lower right and None still apply); the figure
is larger (story 1400) on the left. Left is the page before. First line
pills Serif | Italic (`hook1`, Italic default); Second line starts on Sans,
now mixed case at 0.9 of the hook, with the caps-and-tracking version as
Sans caps. Badge tone pills White | Slate | Glass (`badge_tone`, White
default); the Badge place row only shows for Left.

**Same afternoon:** the photo groups fold on every plan builder (a header
button with an arrow; the chosen photo's group starts open, the count
beside the name). The Broker tool has a second framer for the backdrop
(`bd_zoom`, `bd_fx`, `bd_fy`; the renderer frames `.bd img` as
social_render() frames a photo), and the broker's own framer is now
WYSIWYG: the whole picture as the box, the figure drawn where the renderer
puts it, so dragging moves it the way the hand goes (it went the other way
before, and dropped the figure). Figure zoom 0.6 to 2 (the server clamp
followed that evening; until then zooming out did nothing).

**The builder's sections (same evening):** no step numbers and no hint
text; five sections on every builder: the subject (with the broker's
framer), Size, Text, Elements (logo, watermark, label, badge), Photo or
Backdrop (tiles, upload, framer, and the Overlay on the broker tool). The
page header moves into the form column at load so the preview is sticky
from the top (82px, under the fixed portal header).

**Same evening:** the name and service area lines are gone from the Broker
Feature (no Text lines on that tool), and the Text section has Hook size,
Call line size and Badge size sliders (`hook_size`, `call_size`,
`badge_size`, px; defaults 70 / 62 / 50 by size, 0.9 of the hook, 22; a
slider keeps its value once moved). The length-based hook sizing is gone.

**The photo pick is the backdrop (Nikki, same night, after a stock photo
replaced Bryan's cutout with a chairlift: "if I choose an alternate photo
it should change the background, not the headshot"):** the Broker tool's
photo step is now titled Backdrop and offers None, the person's uploads
and the stock library (the broker's region first and suggested);
plan_photos.php returns those for `kind=broker`, with a `none` tile
(`plan_tile('none', …) + ['none' => true]`, drawn as the striped None
square). plan_post_from_request() takes the posted `photo` as the backdrop
and always draws the broker's cutout (the headshot when none exists) as
the figure. The separate Backdrop step is gone; its Overlay slider sits
under the framer, and the framer shows the figure (`data-figure` on the
broker option), so dragging and zoom still move and scale the broker.

## Weekly Plans: saved weeks (2026-11-03)

Nikki: "go into each day/item and create the post or set, change the
suggestion if desired, save and return to the weekly plan with the updated
day/item in place; once a week has been fully created/updated, save this
week and plan next week; go back into a created post or set I've saved and
have my saved options load; four weeks in advance."

- **Store:** inc/plan_store.php, one JSON per ISO week at
  CREATIVES_DIR/social/plans/<YYYY-Www>.json: status (open | planned) and
  items by slot (0 to 7, plan_slots(): Monday property, Tuesday market +
  branding, Wednesday property, Thursday location, Friday property +
  branding, Saturday broker). A builder item keeps the builder's whole POST
  as `form` (plan_form_from_post()), a `caption` (for when the post goes
  up; not drawn), a `summary` line and `saved_at`; the market slot keeps a
  `done` tick only (the Market Snapshot is its own tool). No table: one
  person, eight pieces a week, a few weeks ahead; the functions are the one
  path in and out, so moving to MySQL later is small.
- **The week page** (social_plan.php, "Weekly Plans"): a strip of this
  week and the next four with "n of 8 ready" or "Planned ✓"; the table's
  rows show the suggestion, or what is saved (with the suggestion under it)
  and Saved + time; buttons: Post / Story (into the builder with
  ?week=&slot= and the suggestion preselected; the story size opens with
  the set ticked, so the button says Story since 2026-11-09), or for a saved row Edit,
  Download (plan_export.php with plan_week + plan_slot: the saved form is
  the request; a property's listing photos are primed into the session
  first so the saved photo ids pass plan_photo()) and Reset (back to the
  suggestion); the market row has its Story link and a Done tick. The
  footer: n of 8 ready, Mark this week planned (a confirm when pieces are
  missing), then Planned ✓ · Reopen · Plan next week.
- **The builder** opened for a slot (plan_item_context()): the hint names
  the week, day and piece and whether a saved piece loaded; Back to the
  week and Weekly Plans buttons; hidden plan_week / plan_slot /
  plan_action; a Caption textarea in the Text step; Save to the week
  (formaction plan_save.php) beside Download. A saved piece presets the
  subject dropdowns server-side (plan_preset_from_form()) and the script's
  restoreSaved() sets every other control from SAVED, then loads the photos
  with the saved pick, frames and per-frame logos, then zoom and focus. The
  sliders count as touched (the look does not reset the text shadow) and
  prevSize is set so the size does not swap the saved logo place.
- **plan_save.php:** item (the form must render, the same check the
  download makes), done, clear, status; redirects to the week with
  ?saved= or ?cleared= for the flash line.
- **Each frame of a set is a tab (2026-11-03):** the pills under the
  preview are tabs. On a tab, clicking a photo tile puts that photo on the
  frame (a photo already on another frame swaps places), drag and zoom are
  the frame's own (`frame_zoom[]`, `frame_fx[]`, `frame_fy[]` beside
  `frames[]` and `frame_logo[]`; the cover's own framing is posted as
  `cover_zoom` / `cover_fx` / `cover_fy` since the visible zoom and focus
  controls hold whichever tab is open), and "Logo on this frame" is its
  logo. + Add photo appends the next unused photo; Remove this frame drops
  the open one. The request reads them into plan_render_photo_frame().
- The slot's kind is the sample week's unless the week says otherwise
  (below); the subject, size and everything else are free. A saved piece
  re-renders from the live feed when downloaded later: the saved words
  stay, but a listing's photos are the feed's today, and a photo that left
  the feed drops out of the set.

## Content per slot (2026-11-09)

Nikki: "I want to be able to change up the weekly plan. Maybe the Content
spot can be a dropdown or a text input?" The week table's Content column
is a dropdown of the plan's kinds (`plan_slot_options()`: Property · New
Listing / Featured Listing / Just Sold or Rental, Market Stats, Branding,
Location Feature, Broker Feature, Other), the sample week's own kind
marked with an asterisk (Nikki, 2026-11-09: not "(usual)"). Picking a kind posts `plan_action=kind` to plan_save.php
at once (`plan_slot_set_kind()`); Other opens a text box for the words (a
reel, a repost; 80 characters) with its own Save. The choice is stored per
week in the JSON's `slots` (type, variant, words); `plan_week_slots()`
applies it, and the page, plan_save.php and the builders'
`plan_item_context()` all read the week through it, so a builder opened
for the slot checks against the week's kind. Changing a slot's kind drops
a piece or Done tick saved under the old kind (a confirm on the page
first); choosing the usual kind again removes the record. The suggestions
follow the choice: the nth slot of a kind in the week steps that kind's
rotation on by n, so two Broker Feature slots suggest this week's broker
and the next, two Branding slots the season's first and second tagline,
two New Listing slots the two newest. An Other slot has no tool and no
suggestion, only a Done tick, and counts toward "n of 8 ready" when
ticked. Still eight slots a week on the sample week's days; adding or
removing a day is not built.

## A post and a story per slot (2026-11-09)

Nikki: "for the Featured Listings when I have both a story and post
scheduled, one save cannot overwrite the other. I created my Post set for
Wrights Rd then created my Story set ... the Story changes overwrote my
Post changes." A builder slot's item now holds two pieces, `post` (the
post or square size) and `story`, each { form, caption, summary, frames,
saved_at }; Save to the week files the form under the piece of its size
(plan_piece_bucket(), plan_piece_save(); the other piece stays), and an
item saved before, with its form at the top level, reads as the piece of
its size (plan_week_load()). The week row lists each saved piece ("Post:
…", "Story: …") and gives each its own line of buttons: Edit post / Edit
story (the builder with ?piece=, plan_item_context() returns that piece;
the suggestion buttons still carry ?size=, which picks the piece too),
PNG or ZIP (plan_export.php with plan_piece) and Reset (plan_save.php
clear with plan_piece; the item goes when nothing is left). An unsaved
piece keeps its suggestion button (Post › or Story ›), so a row can show
a saved post above a Story › button. The slot is ready with either piece
saved. In the builder, switching the size before saving files the piece
under the new size: a post opened and saved as a story becomes the story
piece and leaves the post as it was.

## Property cover and set defaults (2026-11-09)

Nikki: "Default = no logo on cover; center badge and text on cover; M mark
starts lower right slide 3; mark in sub-slides needs to be a little
smaller." The Property tool opens on the Centered layout with the badge
above the headline (`badge` body, drawn centred) and the logo None
(inc/plan_body.php: `$offered_layouts` property = center then photo,
`$default_logo` / `$default_badge` and the script's LOGO_DEFAULT /
BADGE_DEFAULT for property). The set's Auto rule is no longer the
every-other pattern in the cover's logo kind: the slide after the cover
is the photo alone and every slide from the third carries the M mark
lower right, whatever the cover shows (`$eff` in plan_post_from_request()
and effLogo() in the script, which must stay in step; the photo tiles'
order badges read "3 M", "4 M"). A per-frame choice (None / Logo / M
mark) still overrides it. The mark on a photo frame is 96 px tall (was
118; plan_render_photo_frame()); the stack stays 152.

## Branding days are the week's own (2026-11-09)

Nikki: "Instead of assigning the Branding story days automatically, let me
choose which days. Maybe a tool at the top saying Branding Story and I
check which days I'm planning it, then it can add to the week structure
with suggestions." A Branding Story bar sits above the week table with a
pill per day, Monday to Sunday; the ticked days each get a Branding row
(with the season's taglines in turn as the suggestions), the others have
none. Tuesday and Friday are ticked until a week says otherwise
(`branding_days` in the week's JSON; the key absent = the default;
plan_branding_days()). Unticking a day with a saved Branding story asks
first, then drops it (plan_week_set_branding_days()). So the slots now
have STABLE ids rather than positions: the six regular pieces keep their
sample-week index (0, 1, 3, 4, 5, 7) and a Branding story is
PLAN_BRANDING_BASE (100) + the day's index (Monday 100 … Sunday 106);
plan_week_load() reads the old ids 2 and 6 as 101 and 104, so the weeks
saved before (W45's Tuesday story, W46's content choices) carry over.
plan_week_slots() builds the week in day order, a day's regular piece
before its Branding story, and everything (the page, plan_save.php, the
builders' plan_item_context(), plan_export.php) reads the week through
it; `plan_slot_base()` gives the "usual" kind of any id, a Branding day
included. "n of N ready" counts the week's own slots, so a week with one
Branding day is out of 7, with four out of 10. Adding other kinds to
extra days is still through the Content dropdown of an existing slot;
a free day with no slot cannot take a non-Branding piece (not asked for).

## Market Stats as a list of slides (2026-11-09)

Nikki: "market stats is a little unique because each week is a couple of
submarkets each time. Today I'm going to do 4 story slides, 2 for Aspen
(single family and townhomes) and 2 for Snowmass Village. Next week Basalt
and Carbondale." The Market Stats row is a list of slides, one per place
and property type ("Aspen · SF", "Aspen · T+C"; plan_market_ptype_short(),
Nikki: "to save space"), each with its own Story
link into the Market Snapshot (market_snapshot.php?market=&ptype=, which
now preselects the property type too; inc/market_post_body.php reads
`$ptype`, so the portal's own Templates page is unchanged) and its own
Done tick, an x to remove it, and under the list "+ Add slide" with a
place dropdown (market_card_defs()) and the two property types. Stored in
the market item as `slides` (place, ptype, done; plan_market_slides_save());
the first change to a day still on its suggestion keeps the suggestion as
the list. The status reads "k of n done" (amber) until every slide is
ticked, then Done, and only then does the slot count toward "n of 8
ready" (plan_market_item_ready()). Reset drops the list for the
suggestion. The suggestion is two places a week, both property types each
(plan_market_pairs(), from PLAN_MARKET_PAIR_ANCHOR = 2026-W46: Aspen +
Snowmass Village, Basalt + Carbondale, Glenwood Springs + Missouri Heights,
Woody Creek + Old Snowmass, Eagle + San Miguel, Summit + Routt, Montrose +
Ouray, La Plata + Denver Metro, then round again); a second Market Stats
slot in a week takes the next pair. Pitkin and Garfield counties are not
in the rotation (their towns are); add them to a week by hand. An older
market item with only `done: true` reads as the suggestion, all ticked.
Not built: a Download all for the slides (the Snapshot does not render
from a saved form yet).

## Line breaks in the Headline and Text 1 (2026-11-11)

Nikki: "can you make both Text 1 and Headline multiline? As in give me the
ability to add line breaks."

- The Headline was already a textarea; Enter was honoured on the Centered and
  Framed layouts and folded into a space on Full photo. It is now a new line
  on every layout.
- Text 1 is a textarea (`line_text[]`, the first of the four): Enter is a new
  line, up to four rows and `PLAN_TEXT1_MAX` (160) characters. Text 2 to 4
  stay one line of 90.
- `plan_post_from_request()` keeps the rows (blank rows dropped, each
  trimmed). The centred and full-photo pictures come from the shared
  `social_render()`, which breaks lines only when the post carries
  `'multiline' => true`; the plan tools alone set it, so the agents' Listing
  and General Social pictures are byte for byte what they were (both sample
  test folders compared before and after). `social_line_html()` turns a
  line's rows into `<br>`s; `plan_render_split()` uses it too and counts each
  row of Text 1 against the block's room (the first line is always drawn).
- The Framed block has a fixed height: a three-row headline with a three-row
  Text 1 runs out of room and the last rows are cut. The preview shows it.
- The Broker Feature draws no lines and its hook kept its breaks already.

## The builder form, tidied (2026-11-12)

Nikki, after building a Wrights Road set: ten small things, all in
`inc/plan_body.php` unless said.

- **Post type pills removed** (Property). She changes the badge label
  herself, and a click on a pill ran `fill()`, which put the listing's own
  words back over everything she had typed. `variant` is now a hidden field
  holding the slot's kind; it picks the badge the page opens with and
  nothing else. `plan_item_summary()` (inc/plan_store.php) leads with the
  badge label, the variant only when there is no badge.
- **Elements in three groups**, a rule between them: the badge ("Badge
  label", "Badge position", and on the Broker Feature "Badge color"), the
  logo ("Logo" with None as its third pill, "Logo position", "Logo color",
  the sliders with Logo shadow and Nudge down swapped), the watermark.
  "Label on the photo" did not say it was the badge.
- **None is a Logo pill.** The form posts `logo_kind=none`;
  `plan_post_from_request()` (inc/plan.php) turns that into `logo = none`,
  so the renderers are unchanged. A piece saved before holds `logo: none`
  with a real `logo_kind`: it draws the same, and `restoreSaved()` ticks
  None for it. The position row hides while the logo is None.
- **Lower left is lower left.** On the Centered layout the Lower left logo
  sat in the middle above the words. The plan tools now send
  `'logo_corner' => true` and `social_render()` (inc/social_render.php)
  puts the logo in the picture's lower left corner (`.logo.ll`). The
  agents' General Social sends no such flag and draws as before (the 38
  social and 51 plan sample pictures are byte-identical before and after).
- **Wording:** Layout "Full photo" is "Lower Left" (`plan_layouts()`), the
  Square size is "Square Post", "colour" is "color".
- **The Caption is the last section**, under the photo: the Suggested
  Caption on the Property builder, a plain Caption box on the other three
  when the page was opened from a week.
- **The caption in the ZIP:** `plan_export_zip($r, $caption)` adds
  `caption.txt` when there is a caption: plain text (it pastes into
  Instagram with nothing attached), UTF-8 with a byte order mark so
  TextEdit and Notepad read dashes and accents right. From the builder it
  is the Caption box (not sent on a story); from the week page the saved
  piece's caption. A single picture is still a PNG, with no caption file.

## The set, second pass (2026-11-02, evening)

Nikki: "Love this make a set option." Changes: the set is offered at the
post and square sizes too (a carousel); the frames after the cover are the
photo alone, with the white or black logo lower right on every other one
(`plan_render_photo_frame()`; none when the cover has no logo); the cover
plus up to eight more photos (`PLAN_MAX_FRAMES` = 9); the closing card is
optional and off by default. Then, per frame: select an internal frame's
pill under the preview and choose Auto / None / Logo / M mark for it
(`frame_logo[]`, aligned with `frames[]`); the tile's number badge shows an
L or an M for what the frame will carry.

## Sizes and the look

Post 1080 x 1440, Square 1080 x 1080, Story 1080 x 1920 with the 190 / 170 px
safe areas, as the Instagram tools. The framed layout: band, then the photo
window (860 / 780 / 520 px on story / post / square), then the words; with a
team row one or two lines fit, without one three or four;
`plan_render_split()` trims to what fits. Palette: Slate, deeper buttons (the website's scheme), through
`social_palette()`.

## Link in Bio (2026-11-12)

Nikki: "when I create a post, it should ask me if I want to add to the
linkinbio and where that cover links to (ie, agent page or listing page on
Monthaus.com)". A saved POST piece's row on the week page has Link in bio ›
(In bio ✓ once added), which opens social_linkinbio.php with the week and
slot: the destination is prefilled from the piece (`bio_plan_dest()`), the
cover is the piece's first picture rendered as plan_export.php renders it,
and the list goes to the website in the roster feed (`linkinbio`), shown at
monthaus.com/linkinbio. A saved Story piece shows its sticker link (the same
destination) with a Copy button; stories are not covers. The store, the
feed and the rules: inc/linkinbio.php and CLAUDE.md > Link in Bio.

## Not done / ideas

- The seasonal taglines are drafts in `plan_seasons()`; Nikki's branding
  strategy should replace them (edit the arrays).
- Milestone openers are drafts too (`plan_milestones()`).
- A "posted" checklist per week (which of the eight went out) is not kept;
  the plan page suggests, it does not record.
- Office photos are the admin's uploads; a shared office library (like the
  stock library) could follow once there are photos to put in it.
