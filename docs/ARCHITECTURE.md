# How it works

```
official client ──mapextractor──► data/builds/<build>/<locale>/*.db2      (tools/tracker.py snapshot)
                                              │
server DB2, world, hotfixes, auth, maps ──────┤
                                              ▼
                               tools/tracker.py analyse
                   content.py · server.py · rules.py · tooltips.py · forge.py
                                              │
                              ft_* tables of the tracker database
                                              │
                         public/index.php ──► app/ (pages, API, Forge)
```

## Analysis (`tools/`)

| File | |
|---|---|
| `tracker.py` | Command line: `snapshot`, `analyse` |
| `ft/db2.py` | WDC5 reader: dense and sparse records, bit-packed, pallet and common data, copy tables, relationship columns, encrypted sections (counted, skipped) |
| `schema/db2-schema.json` | Column names and layouts of every DB2 table, generated from the core's `DB2Metadata.h` / `DB2LoadInfo.h` by `make_db2_schema.py` |
| `ft/content.py` | Official content of one build (13 resource types) and build-to-build diffs |
| `ft/server.py` | What the server provides: its DB2 files, hotfix rows, spawns, loot, vendors, trainers, quests, instances, map files |
| `ft/rules.py` | Availability checks of every resource, with English and French details |
| `ft/tooltips.py`, `ft/spelltext.py` | In-game tooltips, with the core's item formulas and a renderer for spell description templates |
| `ft/forge.py` | Fixes: SQL to apply and to roll back |
| `ft/output.py` | Writes everything into the tracker database, swapping the tables atomically |
| `ft/sqlio.py` | Database access through the `mysql` command-line client (no Python driver) |

Item values follow the core: stats come from `RandPropPoints` and `StatPercentEditor`, armor from
`ItemArmorQuality`, `ItemArmorTotal` and `ArmorLocation`, weapon damage from the `ItemDamage*` tables. On the
reference server, 1,709 of 1,714 compared items match the core's own item export exactly (the other
five differ by one or two points on a stat, most likely server-side overrides).

An item is *obtainable* when one of its sources is active: loot of a spawned creature or object (through reference
loot), pickpocketing, skinning, fishing, a spawned vendor, the shop, a container that is obtainable, the reward of
a quest that can be done, or a craft that can be learned. Quests, items and crafts depend on each other, so the
analysis repeats until nothing changes.

## Tables

| Table | |
|---|---|
| `ft_builds` | Tracked client builds |
| `ft_entities` | One row per resource: names, status, score, checks (JSON), data (JSON) |
| `ft_changes` | Changelog rows |
| `ft_tooltips` | Tooltip data per resource (JSON) |
| `ft_fixes` | Forge fixes with their SQL |
| `ft_meta` | Analysis date, builds |
| `ft_notes` | Blizzard's patch notes (kept across analyses) |
| `ft_forge_log` | Forge history (kept across analyses) |

## Website (`app/`, `public/`)

Plain PHP, no framework and no build step. `public/index.php` routes every address; pages live in `app/pages`,
JSON endpoints in `app/api` (`tooltip`, `search`, `forge`). `public/assets/js/tooltip.js` renders the tooltips from
their JSON data; `app.js` handles the command palette (`Ctrl K`), the filters and the Forge. Every text exists in
English and French (`t('English', 'Français')`).

## Locator and spawns

| Part | Role |
|---|---|
| `tools/ft/locator.py` | Candidates (unspawned templates the content needs), Wowhead positions, clustering, height hints from the nearest spawns; writes `ft_spawns` (swapped atomically) |
| `tools/ft/wowhead.py` | Wowhead's Forever tooltip service: one request at a time, a pause, every answer cached in `data/wowhead/` |
| `tools/ft/geo.py` | Zone percentages (AreaTable zone, floor, x %, y %) to world positions with `UiMap`, `UiMapAssignment` and `UiMapGroupMember` |
| `app/spawns.php` | Spawn plans; the remote console client (RA); the four modes (server, database, addon, GM commands); the addon export |
| `core/forge_commands.cpp` | Worldserver module: `.forge spawn / delete / height / version` |
| `addon/ForeverForge` | In-game addon: runs the exported queue through TrinityCore's addon command channel; *Spawn here* in dungeons |

## The Forge engine

Fixes are stored in `ft_fixes.detail` as operations `{db, table, columns, match, rows[, hash]}`. `forge_build()`
(`app/forge.php`) merges the operations of a selection (rows unique on their `match` columns), `forge_plan()` asks the
databases which of those rows exist now and keeps the others, then writes the INSERT statements and a rollback that
deletes the inserted rows by primary key. `forge_commit()` runs them in one transaction per database and records
the operation in `ft_forge_log`. The database spawn mode builds the same operations for `creature` / `gameobject`.

## Installation

| Part | Role |
|---|---|
| `ForeverTracker.exe` (`tools/launcher/ForeverTracker.cs`, built by `build-exe.ps1`) | Windows launcher: finds PHP (writes `data/php-launcher.ini` for portable builds) and Python, starts and stops PHP's built-in server, opens the wizard; `--check` prints what it finds |
| `app/setup.php`, `app/pages/setup.php`, `public/assets/js/setup.js` | Setup wizard (`/setup`): detection of servers (`worldserver.conf`) and clients (`.build.info`), database test, `config.ini` writer, background pipeline (extract, snapshot, analyse, locate) logged in `data/setup.log` |