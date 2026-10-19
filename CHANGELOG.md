# Changelog

## 1.0.1 — 2026-10-19

- The analysis stops with a clear message when `[server] root` / `dbc` do not lead to the server's `.db2` files,
  instead of reporting nearly everything as unknown to the core. When the configured folder has no DB2 file but a
  locale folder next to it does (`data/dbc/enUS` instead of `data/dbc/frFR`), that folder is used with a warning.
- When neither `[server] build` nor `build_file` is set, the server build is taken from `auth.realmlist`.
- Warnings when `[server] maps` or `vmaps` do not exist.
- Overview: clear messages when the server build is unknown or when most resources look unknown to the core,
  instead of "build 0".

## 1.0.0 — 2026-10-19

First public release.

- Official content of the WoW Forever client, read from its DB2 files with the layouts of your core: 13 resource
  types (dungeons and raids, bosses, zones, quests, items, spells, talents, recipes, mounts, pets, toys,
  achievements, flight points).
- Changelog of every stored client build, field by field, with Blizzard's patch notes.
- Availability checks against a TrinityCore-based server: core data, spawns, loot (with reference loot),
  vendors, trainers, quest givers and enders, objectives, instances, bosses and scripts, graveyards, flight masters.
- In-game tooltips for every resource, computed with the core's item formulas and a spell description renderer.
- The Forge: core data (hotfixes), trainers, vendors and instances, with SQL preview and download, transactions,
  history and rollback; `tools/check_fixes.py` dry-runs every fix.
- Work list by priority with CSV export, global search (`Ctrl K`), catalogues with filters.
- English and French interface and data, dark and light themes, phone layout.
- Administrators: game masters (Battle.net SRP6 login) or local accounts; can run inside another website.
