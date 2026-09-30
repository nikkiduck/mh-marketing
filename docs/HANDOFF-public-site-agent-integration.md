# HANDOFF — Absorb the public site's agent admin; feed agent data back to monthaus.com

Written 2026-09-21 from the site.monthaus.com session. Goal per Nikki: **all agent
management (bios, headshots, contact, FUB participation, approval) lives in this
marketing portal**; the public site (site.monthaus.com → monthaus.com) becomes a
read-only consumer. One place to edit, one direction of flow.

## Why this shape
- Both projects currently run their own MLS roster crons and their own agent tables →
  double maintenance, drift risk. Marketing wins as the manager because SSO'd agents
  will eventually self-serve here.
- The two databases are on DIFFERENT MySQL instances → integration is an HTTP feed,
  not cross-database SQL. Keep it that way (decoupled: marketing downtime must not
  break the public site).

## Phase 1 — Port the public site's agent admin INTO this portal
Source files to port from `/Users/nikkiduck/Mont Haus Master/site.monthaus.com/webroot/admin/`:
- `agent.php` (343 lines) — the meat. Per-agent editor:
  - Status workflow: pending / approved / inactive (+ approved_at)
  - Curated fields: display_name, title, bio (script-tag stripped), email, phone,
    sort_order, socials (ig/tt/fb/li/website), **in_fub** flag (FUB routing opt-in)
  - Headshot pipeline (KEEP THIS — Nikki explicitly wants it, replacing Dropbox links):
    - `headshot` upload → GD resize, max 1000px long edge → `{slug}.jpg`
    - `headshot_face` → square 400×400 top-biased auto-crop, PLUS an interactive
      crop UI that posts base64 JPEG (`headshot_face_data`) → `{slug}-face.jpg`
    - cache-busted URLs (`?v=time()`), remove buttons, chown warning on write failure
  - Office assignment
- `index.php` — roster list ordered pending→approved→inactive, showing all board
  identities per agent (GROUP_CONCAT over agent_mls_ids)
- `_admin.php` / `login.php` — replace with this portal's Microsoft SSO + role check
  (admin-only at first; agent self-serve later needs per-agent row-level auth).

Schema to import (adapt into this DB; source: `site.monthaus.com/agents_schema.sql`):
- `agents`: slug (DNS-safe, drives headshot filenames), status, mls_* mirror fields,
  display_name, title, bio, headshot_url, headshot_thumb_url, socials, sort_order,
  in_fub, approved_at
- `agent_mls_ids`: (agent_id, market, mls_agent_id, agent_key, is_alias, member_status,
  last_seen_at), **UNIQUE (market, mls_agent_id, agent_id)** — NOT unique per identity:
  a team ID (e.g. Weber Boxer Group) maps to MULTIPLE agents (Jonathan + Scott). The
  public site renders all of them. See site's `roster_team_alias.sql`.
- Decide: merge into existing `office_roster`/`mh_brokers` or import `agents` alongside
  and link by email/agent_key. (office_roster has mls_id_aspen/mls_id_vail columns;
  agents/agent_mls_ids is per-board rows and scales to CREN/REcolorado — prefer it.)
- REMEMBER this project's rule: no `IF NOT EXISTS` on ALTER TABLE (MySQL 8).

## Phase 2 — Spark → Anyprop for the roster cron (this project)
Spark is retired company-wide. Replace `cron/sync_roster.php` + `cron/sync_mh_brokers.php`
Spark calls with Anyprop:
- Token: POST https://api.anyprop.com/v1/token (form-urlencoded username/password →
  30-day Bearer). Creds: ANYPROP_USERNAME/PASSWORD (same trial account as public site
  for now — production licensing per board is still pending with the rep).
- Members: `{base}/Member?$filter=OfficeMlsId eq '…'` — reference implementation in
  `site.monthaus.com/probe_anyprop_extras.php` (members step; also discovers MH office
  ids per board via Office resource, handles case-variant `contains(OfficeName,'Mont')`).
- Boards on trial: agsmls (→ 'aspen'), cren. Quota 8K records/day.
- General API reference: `site.monthaus.com/sync_anyprop.php` (pagination via
  @odata.nextLink, $expand, census groupby, --board flag).

## Phase 3 — Feed endpoint for the public site
`api/roster.php` (or under webroot equivalent), token-protected
(`?token=` or Authorization header; constant in db.php-style secrets file). Returns JSON:

```json
{ "generated_at": "2026-09-21T18:00:00Z",
  "agents": [ {
    "key": "jonathan-boxer",              // slug — stable, DNS-safe
    "status": "approved",                  // pending|approved|inactive
    "display_name": "Jonathan Boxer",
    "mls_full_name": "Jonathan Boxer",
    "title": "Broker Associate",
    "bio": "…",
    "email": "jonathan.boxer@monthaus.com",
    "phone": "+19709484802",
    "sort_order": 10,
    "in_fub": true,
    "socials": { "instagram": "…", "facebook": "…", "linkedin": "…", "tiktok": "…", "website": "…" },
    "headshot_url": "https://marketing.monthaus.com/assets/agents/jonathan-boxer.jpg?v=123",
    "headshot_thumb_url": "https://marketing.monthaus.com/assets/agents/jonathan-boxer-face.jpg?v=123",
    "offices": ["aspen"],
    "identities": [ { "market": "aspen", "mls_agent_id": "142000371", "is_alias": false },
                    { "market": "aspen", "mls_agent_id": "WebBoxGrp-ID", "is_alias": true } ]
  } ] }
```
Notes: include inactive agents (site needs to deactivate them); headshot URLs must be
publicly fetchable (or the endpoint can serve them by token); bump `?v=` on any image
change so the site's sync detects it.

## Phase 4 — back on site.monthaus.com (other project, after 1–3 land)
- New cron `sync_agents_from_marketing.php`: pull feed → upsert agents/agent_mls_ids,
  download changed headshots to `webroot/assets/img/agents/`, regenerate thumb if the
  feed only ships one image. Agents table becomes a cache; site admin becomes read-only
  or is removed.
- Retire the site's `sync_roster.php` (Spark) and its cron entry.
- `_roster.php` rendering already handles everything (multi-agent team credits included) —
  no page changes needed.

## Open questions parked for Nikki
- Production Anyprop credentials / per-board licensing (rep conversation pending).
- Agent self-serve permissions model in this portal (SSO exists; needs per-agent roles).
- Whether office_roster's marketing-specific columns (advertising, billing links)
  reference the imported agents table by FK after the merge.
