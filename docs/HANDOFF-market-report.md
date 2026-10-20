# Market Report and Market Snapshot posts (2026-10-18)

The Monthly Market Report and the Market Snapshot social cards, built into
the marketing portal from Nikki's Claude artifacts (JB owns the report and
is the person to tell when a month is refreshed; the artifacts were Nikki's). The artifact project
and its data live at `/Users/nikkiduck/Mont Haus Master/mont-haus-market-report`
(its CLAUDE.md is the full record: the people, the sources and their exact
credit lines, the hard rules and the refresh procedure). This file is what
the portal needs.

## What is where

| | Agent portal | Admin |
|---|---|---|
| The report | My Tools > Market Report, `portal/market.php` | `market_report.php` (a button on the Agent Roster header) |
| Snapshot posts | My Tools > Templates > Instagram Templates > Market Snapshot, `portal/market_post.php` | `market_snapshot.php` (a button on the report) |
| Preview and PNG | `portal/market_render.php`, `portal/market_export.php` (both admit an admin without a linked account: `market_gate()`) | the same endpoints |

- `inc/market_data.php`: loading a month, the words for months and numbers,
  the card model (`market_card_model()`). No database, no portal.
- `inc/market_card_render.php`: the card as HTML, one render path for the
  preview and the PNG (`market_card_render()`). Standalone.
- `inc/market.php`: the parts that need the portal (`market_gate()`, the
  checked builder options, the stock backdrop, `market_card_html()`).
- `inc/market_report_body.php` + `assets/css/market.css` +
  `assets/js/market-report.js`: the report (the artifact's page, the
  charts redrawn in plain SVG, no d3, no CDN).
- `inc/market_post_body.php`: the builder (the Instagram tool's layout and
  social.css classes).
- `tests/render_market_sample.php`: every card in both looks, the story
  size, the report and the builder, rendered from the data files into
  `tests/market-preview/` (git-ignored). Serve the project folder and open
  `/tests/market-preview/` to look at them; the figures it prints are the
  ones to check against the artifact's CLAUDE.md, section 10.

## The data: one folder per month

`inc/market_data/<YYYY-MM>/` holds:

| file | rows | what |
|---|---|---|
| `meta.json` | | `month`, `current_as_of` (CAR's "current as of" date, shown under the report), `sources` (the dataset descriptions), notes |
| `boards.json` | 8 | `{market, order, name, mls, mls_full, areas, credit, county_credit, source_url}`; the credit lines carry the month and the "current as of" date. **Routt County is its own row, market `routt`** (Nikki, 2026-10-18: Summit and Steamboat apart, as the site's Communities have them); it is still Altitude MLS data, and the Routt rows in county.json and county_trend.json carry `market: "routt"`. The artifact's files have one `altitude` row for both: split it when copying a new month. The names follow the site's Communities (Roaring Fork Valley, Vail Valley, Summit County, Routt County). |
| `county.json` | 24 | 12 counties x 2 property types; key `"<County>|SF"` or `"|TC"`; `X, X_ly, ytd_X, ytd_X_ly` for new_listings, sold, median, average, pct_of_list, days_on_market; `inventory, inventory_ly, months_supply, months_supply_ly` |
| `county_trend.json` | 576 | 12 counties x 2 types x 24 months, `{county, market, ptype, month, sold, new_listings, median, inventory}`, as CAR FIRST published each month |
| `towns.json` | 14 | the Aspen Glenwood MLS town reports, county.json's fields plus `town` |
| `front_range.json` | 2 | REcolorado's Market Watch and PPAR's headline, whole-percent changes as they publish them |

The newest folder is the report; `?m=YYYY-MM` opens an older one and the
report shows a Month picker when there is more than one. **Keep the field
names exactly as they are:** market-report.js and the card model read them
by name. The artifact's files were `county_sep.json`, `towns_sep.json` and
`front_range_sep.json`; here the `_sep` is dropped, nothing else changed.

## Monthly refresh (month M = the last full month, around the 12th)

The procedure is the artifact's CLAUDE.md, section 7, step by step. For
the portal the result is:

1. Make `inc/market_data/<YYYY-MM>/` with the six files (copy the previous
   month's folder and replace the figures; or copy the artifact's `data/`
   files, renaming the three `_sep` files, and write meta.json).
2. **Check before you publish:** each county's sold, new listings, median
   and inventory, and their changes, must match CAR's sortable table
   exactly (changes recomputed from this year and last year). Run
   `php tests/render_market_sample.php` and read the printed card figures.
3. Deploy the folder (the six files) to the server; nothing else changes.
   The report, the cards and the Month picker follow by themselves.
4. Tell JB: the month refreshed, 3 to 5 notable moves, anything missing or
   late.

If CAR has not posted month M yet, stop and tell JB. Never fill in numbers
you do not have.

## Rules every output follows

From the artifact's CLAUDE.md, section 3, and built into the code:

- No em dashes in copy. An empty value shows an en dash (–); the minus in a
  change is U+2212 (−).
- Every figure is credited to its board: the report's Sources blocks and
  every card's credit line (`market_card_model()` writes it per layout).
- Thin markets (fewer than 10 closed sales in the month) show year-to-date
  figures on the card: the hero card switches to YTD throughout (Telluride),
  a list row shows "N sales · year to date $X median on N sales" (Ouray).
- Percent changes are recomputed as (this − last) / last, one decimal, a
  trailing .0 dropped (+30%, −4.3%); "Even" for no change. "% of list"
  changes are points. REcolorado's whole-percent changes are shown as they
  publish them.
- Brand: the reversed white logo on the dark look, the brown stack on the
  light look; the triangle or M mark only as a ghosted watermark.

## The cards

One place per card (Nikki, 2026-10-18, after a first version with seven
market cards: "one city or county at a time", Summit and Steamboat apart,
the words too small to read on a phone): every county in county.json,
every Aspen Glenwood MLS town in towns.json, and Denver Metro from
front_range.json, 21 in all (`market_card_defs()`, keyed by a slug such as
`pitkin`, `town-glenwood`, `denver-metro`; `market_card_key()` turns the
report's market ids into a market's first card). Each card: a short
eyebrow, the county (or town) as the title, ONE location name only (Nikki,
2026-10-19: the town names that sat beneath it, "Aspen, Snowmass Village",
are gone; the defs keep `place` for the record, nothing draws it), the
month and property type on one line (50 px since 2026-10-19, Nikki:
larger; Denver Metro says "All residential"), the median sale price large with its change, then three rows: closed
sales, days on market, homes for sale (Denver Metro: closed sales, median
days in MLS, active listings). Months of supply is in the report, not on
the card (Nikki, 2026-10-18: a weird stat for Instagram, keep it simple). Nothing else: no second-type line,
no source block (Nikki: removed entirely; the board's credit stays in the
report and in the model's `credit` field, which is not drawn). A thin
place (under 10 sales in the month) shows year to date throughout.

Choices on the builder: the location (a dropdown grouped by board), the month,
Leads with (single-family or townhomes & condos), **the three figures
under the median** (Nikki, 2026-10-18 evening: `market_stat_catalog()`:
closed sales, avg days on market, median price per sq ft, homes for sale,
months of supply, % of list price received, average sale price, new
listings; each location offers what its source publishes, `pool_keys` on
the model, data-sf / data-tc on its option, the rest greyed out; defaults
closed sales, avg days on market, homes for sale or price per sq ft;
`market_pick_stats()` fills a pick the source lacks), Post 4:5 (1080 x
1350) or Story 9:16 (1080 x 1920), Dark or Light, the watermark (faint,
.08 on dark, .05 on light), and a background photo chosen from thumbnails
with the location under each
(`market_stock_location()` from the file name; `social_stock()` for the
list; the location's suggested photo is preselected when it is on the
server; a None tile is the plain ground). The controls are the small,
quiet kind (Nikki: the dropdowns were huge). The PNG is made by the
Instagram tool's Chromium call (`social_export_png()`) with fonts, marks
and the photo read from disk; an agent's download is kept in
CREATIVES_DIR/social/<intake id>/ like their posts.

**Type (Nikki, 2026-10-18 evening): nothing under 30 px.** No logo on the
card at all (Nikki: the space is worth more; the ghosted triangle or M
mark carries the brand). The place name is 108 px stepping down by length,
the month line 50 px (2026-10-19; was 40; steps down past 38 and 46
characters, never under 30; a long one wraps, balanced), the
median's label 36 px and the median itself 160 px stepping down, the rows'
numbers 88 px with their label (32 px) and comparison (34 px) centred on
them. The harness's measuring loop also flags any text under 30 px.

**Fit:** the type sizes step down by text length (`market_card_fit()`:
the title to one line across the full width, the median, the sub line),
and the post's height budget is documented at the top of
inc/market_card_render.php. tests/render_market_sample.php writes every
card; a loop in the browser over its output measured all 84 within the
budget on 2026-10-18 and again on 2026-10-19 after the month line grew
(the head is 260 to 272 px; the three month lines all fit on one line
with room to spare). Re-measure after any size change. **Two scales since
2026-10-20:** the story keeps the InDesign sizes of 2026-10-19 (eyebrow 41,
hero 162, change 46, rows 100 / 38 / 46, row padding 30); the post, 570 px
shorter, runs one step smaller (eyebrow 34, hero 150, change 42, rows
92 / 36 / 42, row padding 22) so about 60 px of air stays above and below
the big figure (Nikki, 2026-10-20: the same type on the post was "crammed
to the upper text").

**Price per square foot on the CAR cards (Nikki, 2026-10-19: "if we
have the data, that's a valuable option to offer"):** CAR's county reports
and the Aspen Glenwood town reports do not publish it, so it comes from
our own feed. The site's market_stats API publishes county rows since
2026-10-19 (one per board and county, city '' with `county` set);
`market_feed_counties()` (inc/market_feed.php) keeps the board with the
most closed sales in the county this year, as for the towns, on
`$data['counties_feed']` (market_attach_feed() and the harness);
`market_feed_place_for_card()` finds a county card's county there and a
town card's town in `towns_feed` by label, and `market_feed_ppsf_stat()`
puts the one row in the CAR pool (`ppsf`: the month's median and a year
ago, the year-to-date pair when the card is thin). The card's `credit`
gains "Price per sq ft from the MLS feeds Mont Haus receives (IDX data)"
and the builder's Figures hint says where the figure comes from. The
other CAR figures stay CAR's. September 2026: every one of the 21 cards
offers it except Woody Creek condos (no feed row). The `feed-` towns had
it already (default third row until an inventory snapshot exists).
Measured: 209 cards inside the budget on 2026-10-19.

**Stock photos (2026-10-19):** 17 more from Nikki's Mtn Photos folder
(renamed there by location from their Adobe Stock metadata, see the
folder's _rename-log.txt), captions in assets/stock/stock.json, the 2048
and 400 px copies on the server: Arapahoe Basin, downtown Boulder,
Carbondale and Mount Sopris, Buffalo Pass above Steamboat, Denver City
Park, two more Ridgway, Wilson Peak from Last Dollar Road, Aspen
Highlands, two Colorado Springs, Independence Pass, Ouray, the Roaring
Fork Valley, the Durango train, two Dillon and Silverthorne. The Glenwood
Springs photo in that folder is licensed Editorial Use Only and was left
out. The suggested backdrops now cover El Paso and Teller, and Garfield,
Carbondale, Ouray, La Plata and several feed towns (Keystone, Frisco,
Dillon, Silverthorne, Leadville, Twin Lakes, Ouray, Durango, Boulder,
Colorado Springs) got a closer picture.

**The big number is the AVERAGE sale price (2026-10-20, Jon Boxer's
decision, via Nikki).** Every card type leads with the average (CAR's
`average` / `ytd_average`, the feed's `average`), with its change against
the same month a year ago; the median is a pickable row instead (catalog
key `median`, "Median sale price"; the old `average` key is gone). Denver
Metro leads with the average when the month's front_range.json carries
`average` + `average_change_pct` (REcolorado's Market Watch publishes an
average closed price; add it at the monthly refresh) and with the median
until then, labelled as such. The report page is unchanged (medians in
its tables, the average on the county tiles).

## Town figures from our own feed (2026-10-18, late)

The site computes town statistics from the MLS feeds it receives (site
`docs/HANDOFF-market-stats.md`: `ap_sales`, `sync_sales.php`,
`api/market_stats.php`, closings back to January 2025, inventory snapshots
from 2026-11-01). This portal reads them:

- `inc/market.php` `market_feed($month)` fetches
  `<LISTINGS_FEED_URL dir>/market_stats.php?month=&min=0` with the listings
  feed token and caches the JSON in `CREATIVES_DIR/market/towns_feed_<month>.json`
  (six hours for the current report month, a week for older ones, the stale
  copy when the site does not answer). `market_attach_feed($data)` puts the
  resolved towns on `$data['towns_feed']`; every page that loads a month
  calls it. No cron, no new table.
- `inc/market_feed.php` (standalone) resolves the site's Communities towns
  (registry `areas`, `mk_areas()`) against the feed: every town of every
  area, **no minimum** (Nikki: Ridgway with a couple of sales is still
  worth seeing); a town with no sale all year is left out. The board a
  town's figure comes from is the one with the most closed sales for that
  town this year (`market_feed_towns()`), so there is no map to keep;
  a few spellings are aliased (`market_town_city_keys()`: Old Snowmass is
  "Snowmass" in the feed, Steamboat Springs also "Steamboat", Greater
  Denver is "Denver").
- The report: each market page ends with "Town by town, from the MLS feed"
  (`drawFeedTowns()`), the board's towns for the chosen class, sorted by
  year-to-date sales: closed, median, $ per sq ft, days on market, the
  year-to-date columns, For sale (a dash until a snapshot exists). The
  note under it carries the site's caveat (IDX data) and the board's
  days-on-market basis.
- The cards: the feed towns join the Location list under their board
  (`market_feed_card_defs()`, keys `feed-<town key>`), except the Roaring
  Fork towns, which keep their CAR town-report cards. The third row is
  price per square foot until the town has a month-end snapshot, then
  homes for sale (`market_feed_card_body()`). Thin months (under 10 sales)
  show year to date, as everywhere.
- September 2026 resolved 39 towns on 2026-10-18; 208 card variants
  measured inside the height budget.
- Locally: `MARKET_FEED=<saved response> MARKET_AREAS=<boards.json cache>
  php tests/render_market_sample.php` draws the towns too; without the two
  files the samples show the CAR cards and tables only.

## Known quirk, kept on purpose

On a market page the headline names the county with the MOST single-family
sales that month (Garfield for Aspen, not Pitkin), as the artifact did. Its
CLAUDE.md flags this as something JB may want changed; confirm with him
before changing `drawMarket()`'s choice of `sf`.

## Ideas not built

From the artifact's CLAUDE.md, section 9: automate the fetch and parse as
a script (CAR's tables and PDFs, REcolorado's post, PPAR's line) with the
checks; generate the PNGs for all seven cards at once; a Stories-first
variant (the story size exists; JB's CEO wants Stories to be the focus).
