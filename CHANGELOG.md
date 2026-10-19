# Changelog

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
