#!/usr/bin/env python3
"""
build_bootstrap.py — carve the marketing site's tables out of a full hub dump.

The hub database holds 33 tables; this app touches 13 of them. Everything else
(mls_listings, hot sheets, the email broadcaster, pipeline events) belongs to
b2b and hot-sheets and has no business on marketing.monthaus.com.

Also normalises collation. The hub is a mix of utf8mb4_unicode_ci and
utf8mb4_0900_ai_ci — that mix is exactly why sync_roster.php has to write
explicit COLLATE casts into its JOINs to avoid "Illegal mix of collations".
The new database starts clean on one collation.

Usage:  python3 build_bootstrap.py <hub-dump.sql> <out.sql>
"""
import re
import sys

# The 13 tables, in dependency order. Verified FK-closed against the dump:
# every foreign key in this set points at another table in this set.
TABLES = [
    # ── identity / access ────────────────────────────────────────────────
    'users',                        # accounts + roles. Entra links land here.
    'password_resets',              # break-glass password reset tokens
    # ── MLS-derived caches, rebuilt from the Spark API by cron ───────────
    'office_roster',                # sync_roster.php
    'mh_brokers',                   # sync_mh_brokers.php
    'listings',                     # agent.php joins these two to match an
    'listing_brokers',              # agent's listings by name
    # ── the marketing app's own data ─────────────────────────────────────
    'marketing_intakes',            # parent of everything below
    'marketing_campaigns',
    'marketing_campaign_assets',    # -> marketing_campaigns
    'marketing_tasks',
    'marketing_collateral_orders',
    'marketing_notes',
    'marketing_agent_mls_ids',
]

TARGET_COLLATION = 'utf8mb4_0900_ai_ci'
TARGET_CHARSET   = 'utf8mb4'

# Rows per INSERT statement.
#
# Not cosmetic. phpMyAdmin emits one INSERT per table, and marketing_tasks came
# out as a single 32KB / 265-row statement. Imported through the MariaDB client
# against Lightsail's managed MySQL 8.4, that statement silently landed only
# 144 of its 265 rows — no error, no warning, just a short table. Every other
# table in the dump was under 12KB and imported exactly right.
#
# Batching fixed it, and it means a batch that does fail reports an error
# instead of disappearing into a partial result.
INSERT_BATCH_ROWS = 25


def statements(sql: str):
    """Yield top-level statements, ignoring semicolons inside quotes."""
    buf, quote, esc = [], None, False
    for ch in sql:
        buf.append(ch)
        if esc:
            esc = False
            continue
        if ch == '\\' and quote:
            esc = True
            continue
        if quote:
            if ch == quote:
                quote = None
        elif ch in "'\"`":
            quote = ch
        elif ch == ';':
            yield ''.join(buf)
            buf = []
    tail = ''.join(buf).strip()
    if tail:
        yield tail


def strip_leading_comments(stmt: str) -> str:
    """
    phpMyAdmin prefixes each statement with a `-- Table structure for ...`
    banner. Those lines ride along in the split, so the statement does not
    start with its own keyword — drop them before classifying.
    """
    lines = []
    started = False
    for line in stmt.splitlines():
        s = line.strip()
        if not started:
            if not s or s.startswith('--') or s.startswith('/*'):
                continue
            started = True
        lines.append(line)
    return '\n'.join(lines).strip()


def table_of(stmt: str):
    m = re.search(r'(?:CREATE TABLE|INSERT INTO|ALTER TABLE|DROP TABLE(?: IF EXISTS)?)\s+`([a-z_0-9]+)`',
                  stmt, re.I)
    return m.group(1) if m else None


def split_values(block: str) -> list:
    """
    Split the VALUES section of an INSERT into individual row tuples,
    honouring quoted strings and backslash escapes.
    """
    rows, cur = [], ''
    depth, quote, esc = 0, None, False
    for ch in block:
        if esc:
            cur += ch
            esc = False
            continue
        if ch == '\\' and quote:
            cur += ch
            esc = True
            continue
        if quote:
            cur += ch
            if ch == quote:
                quote = None
            continue
        if ch == "'":
            quote = ch
            cur += ch
            continue
        if ch == '(':
            depth += 1
            if depth == 1:
                cur = ''
                continue
        if ch == ')':
            depth -= 1
            if depth == 0:
                rows.append(cur)
                cur = ''
                continue
        if depth:
            cur += ch
    return rows


def rebatch_insert(stmt: str) -> str:
    """Rewrite one big INSERT as several smaller ones. See INSERT_BATCH_ROWS."""
    m = re.match(r"\s*INSERT INTO (`[a-z_0-9]+`) \(([^)]*)\) VALUES\s*", stmt, re.S)
    if not m:
        return stmt
    table, cols = m.group(1), m.group(2)
    rows = split_values(stmt[m.end():])
    if len(rows) <= INSERT_BATCH_ROWS:
        return stmt

    out = []
    for i in range(0, len(rows), INSERT_BATCH_ROWS):
        chunk = rows[i:i + INSERT_BATCH_ROWS]
        vals = ",\n".join(f"({r})" for r in chunk)
        out.append(f"INSERT INTO {table} ({cols}) VALUES\n{vals};")
    return "\n".join(out)


def normalise(stmt: str) -> str:
    """
    Rewrite every collation to the target, preserving the separator.

    The two forms are NOT interchangeable and mixing them is a syntax error:
      table option      ... ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_x
      column definition ... `name` varchar(100) COLLATE utf8mb4_x NOT NULL
    Capturing the separator and putting it back keeps each in its own form.
    """
    stmt = re.sub(r'COLLATE(\s*=\s*|\s+)utf8mb4_\w+',
                  lambda m: 'COLLATE' + m.group(1) + TARGET_COLLATION,
                  stmt, flags=re.I)
    stmt = re.sub(r'DEFAULT CHARSET(\s*=\s*|\s+)\w+',
                  lambda m: 'DEFAULT CHARSET' + m.group(1) + TARGET_CHARSET,
                  stmt, flags=re.I)
    stmt = re.sub(r'CHARACTER SET\s+\w+', f'CHARACTER SET {TARGET_CHARSET}', stmt, flags=re.I)
    return stmt


def main() -> int:
    src, out = sys.argv[1], sys.argv[2]
    sql = open(src, encoding='utf8', errors='replace').read()

    wanted = set(TABLES)
    order = {t: i for i, t in enumerate(TABLES)}

    # phpMyAdmin emits CREATE, then INSERT, then ALTER (indexes), then
    # ALTER ... ADD CONSTRAINT. Keeping that grouping keeps FK order valid.
    buckets = {'create': [], 'insert': [], 'alter': [], 'constraint': []}
    seen, skipped = set(), set()

    for stmt in statements(sql):
        t = table_of(stmt)
        if not t:
            continue
        if t not in wanted:
            skipped.add(t)
            continue
        seen.add(t)
        s = normalise(strip_leading_comments(stmt))
        if not s:
            continue
        up = s.upper()
        if up.startswith('CREATE TABLE'):
            buckets['create'].append((order[t], s))
        elif up.startswith('INSERT INTO'):
            buckets['insert'].append((order[t], rebatch_insert(s)))
        elif 'ADD CONSTRAINT' in up and 'FOREIGN KEY' in up:
            buckets['constraint'].append((order[t], s))
        elif up.startswith('ALTER TABLE'):
            buckets['alter'].append((order[t], s))

    missing = wanted - seen
    parts = []
    add = parts.append

    add(f"""-- ============================================================================
--  marketing.monthaus.com — database bootstrap
--
--  Generated from the hub export by build_bootstrap.py.
--  {len(seen)} tables, carved out of the hub's 33. Collation normalised
--  to {TARGET_COLLATION} throughout.
--
--  INSERTs are emitted in small batches, not one statement per table --
--  see INSERT_BATCH_ROWS in build_bootstrap.py for why that matters.
--
--  Run this ONCE against a fresh, empty database:
--      mysql -u root -p marketing_monthaus < bootstrap.sql
--
--  Then run, in this order:
--      sql/assets_tab_v2.sql     creates marketing_asset_links
--      sql/sso_schema.sql        adds the Entra columns to users
--
--  Requires MySQL 8.0 or newer. NOT MariaDB — {TARGET_COLLATION}
--  does not exist there and every CREATE TABLE below would fail.
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET NAMES utf8mb4;

-- Data is inserted before the foreign keys are added, so ordering within
-- each section does not matter.
SET FOREIGN_KEY_CHECKS = 0;
""")

    for title, key in [
        ('Schema', 'create'),
        ('Data', 'insert'),
        ('Indexes and auto-increment', 'alter'),
        ('Foreign keys', 'constraint'),
    ]:
        rows = sorted(buckets[key], key=lambda r: r[0])
        if not rows:
            continue
        add(f"\n\n-- ── {title} " + "─" * (66 - len(title)) + "\n")
        last = None
        for idx, stmt in rows:
            t = TABLES[idx]
            if t != last:
                add(f"\n--\n-- {t}\n--\n")
                last = t
            add(stmt.rstrip() + "\n")

    add("\nSET FOREIGN_KEY_CHECKS = 1;\n")

    add(f"""
-- ── Verification ────────────────────────────────────────────────────────────
--
-- Every table on one collation? This should return exactly one row.
--   SELECT DISTINCT TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES
--    WHERE TABLE_SCHEMA = DATABASE();
--
-- Row counts, to compare against the hub:
--   SELECT TABLE_NAME, TABLE_ROWS FROM INFORMATION_SCHEMA.TABLES
--    WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME;
--
-- At least one super_admin must keep a password — that is the break-glass
-- account if the Entra client secret expires:
--   SELECT email FROM users
--    WHERE role='super_admin' AND password IS NOT NULL AND password <> '';
""")

    open(out, 'w', encoding='utf8').write(''.join(parts))

    print(f"wrote {out}")
    print(f"  {len(seen)} tables, "
          f"{len(buckets['create'])} CREATE, {len(buckets['insert'])} INSERT, "
          f"{len(buckets['alter'])} ALTER, {len(buckets['constraint'])} FK")
    if missing:
        print(f"  NOT FOUND in the dump: {', '.join(sorted(missing))}")
    print(f"  left behind ({len(skipped)} hub-only tables): {', '.join(sorted(skipped))}")
    return 0


if __name__ == '__main__':
    sys.exit(main())
