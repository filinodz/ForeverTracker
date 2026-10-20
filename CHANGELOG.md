# Changelog

## 1.3.1 — 2026-10-20

- **ForeverScribe 1.1.0 collects only on the official realms.** A client connected to a private server (your own one
  included) uses another `portal` than `us`, `eu`, `kr`, `tw` or `cn`: nothing is noted there. `/scribe on` and
  `/scribe off` force it, `/scribe auto` goes back to that rule; the state shows at login and with `/scribe`.
- **No drop added twice**: an item a creature or chest already drops — directly or through `reference_loot_template`,
  at any depth — is left out of a ForeverScribe import (counted as *drops the server already has*).

## 1.3.0 — 2026-10-20

- **ForeverScribe** (`addon/ForeverScribe`): an addon for the game that notes, through the normal addon API, what
  vendors sell (stock limits, costs), what creatures and chests drop (counted per corpse, skinning and pick pocketing
  apart), who gives and takes quests, and where creatures stand. No character, realm or account name is kept.
- **The Forge › Imports** imports ForeverScribe files like SQL files: vendors, loot (chance = drops ÷ corpses looted),
  quest givers and enders, previewed and rolled back like any import. *Install into the game*, *Download the addon*,
  *Import new notes*.
- **Automatic import**: `ForeverTracker.exe` imports the game's new ForeverScribe notes every minute
  (`tools/collector-sync.php`); `[collector] auto_apply = 1` also applies them.
- **Locator**: creatures seen in game by ForeverScribe use those positions before Wowhead's.
- **Vendors**: the vendor search finds every creature, not only those flagged as vendors, and says when a creature
  does not exist on the server; selling items at a creature (by hand or from an import) adds the vendor flag it lacks,
  with its rollback.
- **Spawning on the server**: a point without a nearby spawn to borrow a height from takes the highest floor up to 40
  yards above the terrain, instead of the terrain itself (which lies under cities such as Stormwind).
- The launcher's French texts are compiled as UTF-8.

## 1.2.2 — 2026-10-20

- **Locator** (`tools/tracker.py locate`, `tools/ft/locator.py`): reads the positions of unspawned creatures and
  objects from the maps of Wowhead's public Forever tooltips.
- **The Forge › Imports** (`tools/wpp-import.php`, `data/imports/`): imports SQL files in WowPacketParser's format.
- Guide `docs/SPAWNS.md`, with a note on heights in places with several levels (Stormwind).

## 1.2.1 — 2026-10-20

- README (English and French) rewritten as a presentation of the whole solution: three-step installation from the
  release package, every feature, and how it works (analysis, Forge engine, positions, spawning, imports).
- Release package `ForeverTracker-<version>.zip`: unzip and double-click `ForeverTracker.exe`.

## 1.2.0 — 2026-10-20

- **Imports** (The Forge › Imports, `tools/wpp-import.php`): imports SQL files in WowPacketParser's format: spawns
  with their positions, new creatures and objects, loot, vendors, quests,
  quest givers and enders, gossip. Only `INSERT` rows are read; rows the server has are left out; spawns get new
  guids and a spawn already standing within 3 yards is left out with its addon; a creature whose `LootID` is 0 gets its
  loot table; unknown columns and tables are dropped; one transaction, exact rollback in the history.
- **Items tab**, first in the Forge: every item nobody can get, with the reason and its imported sources (drop, chest,
  vendor, quest reward), the filter *Found in an import*, and **Apply imported sources** for the selection.
- The Forge engine gains *fill* operations (set a column only where it is still 0, with its rollback), and tables without
  a primary key are matched on their first index.

## 1.1.1 — 2026-10-20

- **`ForeverTracker.exe`**: the launcher is now a real Windows program (with its icon), built from
  `tools/launcher/ForeverTracker.cs` by `build-exe.ps1` with the C# compiler Windows ships. It replaces the
  PowerShell launcher; `ForeverTracker.bat` starts it. `--check` prints what it finds.
- README: install in two minutes, what is new in 1.1, new screenshots (launcher, setup wizard, spawns, SQL preview).

## 1.1.0 — 2026-10-20

### Installation
- **Launcher** (`ForeverTracker.bat`, Windows): finds a PHP 8 with `mysqli` (writing an ini for portable PHP builds)
  and Python, starts and stops the website, opens the setup wizard on the first run, updates the data.
- **Setup wizard** (`/setup`): finds the server by its `worldserver.conf` (databases, data folder, remote console,
  `mysql` client), tests the databases and creates ForeverTracker's, finds the game client (`.build.info`), extracts
  its DB2 files with the core's `mapextractor` or uses the server's, creates the administrator, writes `config.ini`
  and runs the snapshot, the analysis and the locator with live progress. Local requests only before the first
  configuration, administrators afterwards.

### The Forge
- **No more "duplicate entry" errors.** Fixes are stored as operations (table, columns, rows, key columns). Applying
  merges the whole selection, inserts every row once, leaves out the rows that already exist on the server at that
  moment, and saves a rollback that deletes exactly the inserted rows. Applying twice, overlapping fixes (a spell that
  is both a class spell and a recipe, shared durations or triggered spells) and rows added by hand are all safe.
  Preview shows new, existing and shared rows.
- `tools/forge-check.php` (replaces `check_fixes.py`) dry-runs every fix with the website's own engine, all together
  and one by one.

### Locator and spawns
- **Locator** (`tools/tracker.py locate`, or *Run* in the Forge): finds the creatures and world objects the server
  knows but never spawned although quests (givers, enders, objectives), vendors, trainers, loot or dungeons need
  them, and their official positions from Wowhead's Forever tooltips (cached, polite), converted to world
  coordinates with the client's `UiMap` tables (a few yards outdoors).
- **The Forge › Spawns**: spawn them on the running server through the remote console (exact height, live,
  undoable), in the database (worldserver stopped), with the ForeverForge in-game addon, or as GM commands.
- **Core module** `core/forge_commands.cpp`: `.forge spawn npc|go`, `.forge delete`, `.forge height`,
  `.forge version`, usable from the console, the remote console and in game. The server computes the ground height
  from its maps and vmaps, buildings included.
- **ForeverForge addon** (`addon/ForeverForge`): `/forge` spawns the exported queue through TrinityCore's addon
  command channel, in place with the core module or by travelling with `.go xyz` without it; *Spawn here* for
  bosses and dungeon creatures.

### Other
- Background jobs run Python in UTF-8 mode, and JSON answers survive console-encoded logs.
- New `config.ini` sections: `[console]`, `[client]`, `[wowhead]`. Snapshots now store `UiMap`,
  `UiMapAssignment` and `UiMapGroupMember`.

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
