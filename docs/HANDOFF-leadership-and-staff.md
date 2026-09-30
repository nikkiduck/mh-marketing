# HANDOFF — Leadership page + non-agent staff in the roster feed

Written 2026-09-29 from the site.monthaus.com session (Claude Code), after
reading this project's `api/roster.php`, `inc/agent_roster.php`,
`sql/agent_roster_v2_teams.sql`, and the site's `sync_agents_from_marketing.php`.
Build the marketing side here; the site side is done in the site.monthaus.com
project afterwards (Phase 3).

## Goal

The public site's **Leadership** page (`site.monthaus.com/leadership.php`) is a
hard-coded array today. Nikki wants it managed here, alongside everyone else:

1. Pick which people appear on Leadership, and in what order.
2. Add **non-agents** (Nikki Boxer, Kellee Anderson, future staff) with name,
   title, phone, email and headshot, using the same headshot pipeline as agents.
3. Staff must **never** show up as agents: not on Our Agents, not on listing
   pages, not in FUB lead rotation, not in billing / hot sheets / MLS roster
   matching.

**Titles:** one title per person, the full title (`agent_title`, already
multi-line). The site shows it on Leadership and broker profile pages; listing
pages always say "Broker Associate" (already done on the site). No separate
"leadership title" field.

## Phase 1 — Data model (this project)

Run-once migration, e.g. `sql/leadership_v1.sql` (MySQL 8: no IF NOT EXISTS on ALTER):

```sql
ALTER TABLE marketing_intakes
    MODIFY COLUMN entity_type ENUM('agent','team','staff') NOT NULL DEFAULT 'agent',
    ADD COLUMN leadership_show TINYINT(1) NOT NULL DEFAULT 0 AFTER sort_order,
    ADD COLUMN leadership_sort INT NOT NULL DEFAULT 0 AFTER leadership_show;
```

- `staff` follows the precedent `team` set: a row in `marketing_intakes` that
  is not an agent. Reuses agent_name, agent_title, mh_email, cell_phone,
  headshot_url / headshot_face_url, is_active, slug.
- `leadership_sort` is separate from `sort_order` (Our Agents order ≠
  Leadership order).

### Audit: keep staff out of agent-only processes (the important part)

Every place that excludes teams today must also exclude staff. Start with:

- `mk_team_sql()` in `inc/agent_roster.php`. Either widen it to
  `entity_type = 'agent'` (and rename it, e.g. `mk_agents_only_sql()`), or add a
  sibling. Every caller must be re-checked, not just the function.
- `grep -rn "entity_type\|mk_team_sql\|FROM marketing_intakes" --include=*.php .`
  and check each hit, especially: billing / order_split / receipt, hot sheets
  (`cron/`), the Anyprop roster/member-matching cron, QR codes, team
  membership (`team_members` must not accept a staff row), `agent_lifecycle.php`
  onboarding/offboarding, `index.php` roster lists and counts.
- Staff have no MLS identities: `agent_mls_ids` rows for a staff intake should
  be impossible (guard in the UI; the site ignores them anyway).

## Phase 2 — UI (this project)

- **Add staff member:** a small form on the roster (`index.php` / `roster.php`)
  creating `entity_type = 'staff'` with name, title, email, phone, headshot.
  Slug is generated the same way agents' are. No intake/billing/MLS tabs for
  staff; `agent.php` shows only the profile + headshot crop for them.
- **Leadership controls** on the profile tab for agents and staff: a
  "Show on Leadership page" checkbox and a Leadership order number.
- Optional nicety: a "Leadership" view listing `leadership_show = 1` people in
  order, with quick reordering.

## Phase 3 — Feed (`api/roster.php`, this project)

Add a new **top-level** `leadership` array. Keep `agents` exactly as it is,
with staff excluded from it. The site's current sync ignores unknown top-level
keys, so this is backward compatible and can ship before the site reads it.

```json
{
  "generated_at": "…",
  "count": 21,
  "agents": [ …unchanged, agents only… ],
  "leadership": [
    {
      "key": "nikki-boxer",
      "type": "staff",
      "agent_key": null,
      "name": "Nikki Boxer",
      "title": "Director of IT and Marketing",
      "email": "nikki.boxer@monthaus.com",
      "phone": "+19709484300",
      "headshot_url": "https://marketing.monthaus.com/photo.php?…&v=…",
      "headshot_thumb_url": "https://marketing.monthaus.com/photo.php?…&v=…",
      "sort": 40
    },
    {
      "key": "jonathan-boxer",
      "type": "agent",
      "agent_key": "jonathan-boxer",
      "name": "Jonathan Boxer",
      "title": "CEO\nEmploying Broker",
      "…": "…",
      "sort": 10
    }
  ]
}
```

Rules:
- Include rows with `leadership_show = 1 AND is_active = 1`. Agents must also
  have `web_status = 'approved'`. Never include teams.
- Order by `leadership_sort`, then name.
- Reuse the existing helpers: `mk_e164()` for phone, `feed_photo()` for
  headshots (only portal-hosted images, `?v=` versioned).
- `agent_key` = the agent's slug (the same `key` as in `agents`) so the site
  can link to their broker page; `null` for staff.
- An empty `leadership` array is valid ("nobody selected"). A missing key means
  "old feed", and the site keeps what it has.

Test: `curl -s -H "Authorization: Bearer $TOKEN" https://marketing.monthaus.com/api/roster.php | jq '.leadership, [.agents[].key]'`.
Confirm no staff key appears in `.agents`.

## Seed data (current hard-coded list on the site, 2026-09-29)

Hard-coded order. Titles here are the site's old strings; **marketing's
`agent_title` wins** for agents (e.g. Steve's is "Managing Director, New
Development Sales" there). Nikki and Kellee need staff rows created.

| sort | person | on roster as | phone | email |
|---|---|---|---|---|
| 10 | Jonathan Boxer | agent `jonathan-boxer` | +1 (970) 948-4802 | jonathan.boxer@monthaus.com |
| 20 | Scott Weber | agent `scott-weber` | +1 (970) 948-2766 | scott.weber@monthaus.com |
| 30 | Jean-Michel Drai | agent `jean-michel-drai` | +1 (970) 393-6070 | jm.drai@monthaus.com |
| 40 | Nikki Boxer | **staff**: "Director of IT and Marketing" | +1 (970) 948-4300 | nikki.boxer@monthaus.com |
| 50 | Steve Harriage | agent `steve-harriage` | +1 (970) 355-4646 | steve.harriage@monthaus.com |
| 60 | Sara Perkowski | agent `sara-perkowski` | +1 (262) 510-6622 | sara.perkowski@monthaus.com |
| 70 | Kellee Anderson | **staff**: "Accounting Manager" | +1 (970) 948-1481 | kellee.anderson@monthaus.com |

## Phase 4 — site.monthaus.com (other project, after 1–3 are live)

For reference, so the feed shape stays honest:
- New `leaders` table on the site; `sync_agents_from_marketing.php` replaces it
  wholesale from `leadership` (only when the key is present), and downloads
  staff headshots to `webroot/assets/img/leaders/` with the same `?v=` logic.
- Staff are **not** written to the site's `agents` table, so they cannot leak
  into Our Agents, listing sidebars, FUB rotation or listing matching.
- `leadership.php` reads `leaders`: agent rows link to `broker.php?s={agent_key}`
  and use the agent headshot; staff get their own headshot. It keeps the
  hard-coded array as a fallback until the first feed with `leadership` lands.
- `broker.php` already shows the full `agent_title`; `listing.php` already
  forces "Broker Associate".
