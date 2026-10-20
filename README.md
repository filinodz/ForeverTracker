# Forever Tracker

Developed by **[FilinoDZ](https://github.com/filinodz)**.

**See what your WoW Forever server is missing — and fix it.**

Forever Tracker reads the official game client build after build, lists every resource Blizzard ships, and checks
each one against your TrinityCore-based server: core data, spawns, loot, vendors, trainers, quest givers and
scripts. Every gap comes with the reason and the fix, and the **Forge** can apply the common fixes for you.

[Français](README.fr.md) · [Installation](docs/INSTALL.md) · [Configuration](docs/CONFIGURATION.md) · [The Forge](docs/FORGE.md) · [Locator and spawns](docs/SPAWNS.md) · [How it works](docs/ARCHITECTURE.md)

![Overview](docs/screenshots/overview.png)

## How the tracker works

1. **Extract and store a client build.** Use your core's `mapextractor` to extract the official client's DB2
   files, then run `snapshot` to keep the required tables in `data/builds/<build>/<locale>/`.
2. **Compare the client with your server.** Run `analyse` to read those snapshots alongside your server's DB2
   files, `world`, `hotfixes` and `auth` databases, and available map data. Checks determine whether each
   resource is available, incomplete or missing, with a score and detailed reasons.
3. **Browse the results.** The analysis writes its results into the tracker's own `ft_*` tables. The PHP website
   displays coverage, resource details, tooltips, a work list and changes between stored client builds.
4. **Apply optional fixes.** An administrator can preview the Forge's SQL, download it or apply supported fixes,
   then run the analysis again to refresh the results. Store another snapshot when the client build changes.

The tracker needs access to your server's data; it does not analyse a remote server from its address alone.
See [the architecture guide](docs/ARCHITECTURE.md) for implementation details.

## Features

**Coverage of 13 resource types** — dungeons and raids, bosses, zones, quests, items, spells, talents, recipes,
mounts, pets, toys, achievements and flight points. Each resource gets a status (*available*, *incomplete*,
*missing*), a score, and the list of checks it passes or fails.

**Official changelog** — every build of the client is compared with the previous one, field by field (added,
changed, removed). Administrators can attach Blizzard's own patch notes to a build.

**In-game tooltips** — hover any resource for its tooltip, computed with the core's own formulas: item level,
binding, armor, weapon damage and DPS, stats, *Use / Equip / Chance on hit* effects, item sets and their
bonuses, sell price; spell cost, range, cast time, cooldown, reagents and description, with `$s1`, `$d`, `$o1`…
filled in. Available in English and French.

**The Forge** — administrators create what is missing from the official data, in bulk, with an SQL preview, a
single transaction per operation and a saved rollback. Rows that already exist are left out, so applying twice or
selecting overlapping fixes never fails.

**Locator and spawns** — finds the quest givers, objectives, vendors, trainers, rare drops and bosses your server
knows but never spawned, and where they stand in the official game (Wowhead's Forever data turned into world
coordinates). The Forge spawns them on the running server, in the database, or in game with the ForeverForge addon.

**Launcher and setup wizard** — double-click `ForeverTracker.bat`: it finds PHP and Python, then the wizard finds
your server and game client, writes the configuration and runs the first analysis.

| | |
|---|---|
| ![Item tooltip](docs/screenshots/items-tooltip.png) | ![Item page](docs/screenshots/item.png) |
| ![Changelog](docs/screenshots/changelog.png) | ![Work list](docs/screenshots/todo.png) |
| ![The Forge](docs/screenshots/forge.png) | ![SQL preview](docs/screenshots/forge-sql.png) |
| ![Dungeon checks](docs/screenshots/dungeon-forge.png) | ![Search](docs/screenshots/search.png) |
| ![Spawns](docs/screenshots/spawns.png) | ![Spawn dialog](docs/screenshots/spawns-dialog.png) |
| ![Setup wizard](docs/screenshots/setup.png) | ![Setup: databases](docs/screenshots/setup-database.png) |

Light theme, French interface and phone layout:

| | | |
|---|---|---|
| ![Light theme in French](docs/screenshots/item-light-fr.png) | ![Phone](docs/screenshots/mobile-overview.png) | ![Phone list](docs/screenshots/mobile-quests.png) |

## What is checked

| Resource | Checks |
|---|---|
| Item | known to the core; obtainable from loot of a spawned creature or object, a spawned vendor, a doable quest, a learnable craft, a container or the shop |
| Quest | `quest_template` row; spawned giver and ender; objectives achievable; not disabled |
| Dungeon / raid | terrain extracted; `instance_template`; entrance teleporter; creatures spawned; every boss spawned; navigation mesh (advice) |
| Boss | a creature of that name spawned in the instance; scripted (ScriptName or SmartAI) |
| Zone | creatures spawned in its area; most of its quests doable; graveyard |
| Spell, talent, recipe | known to the core; learnable (trainer, quest reward, automatic, item); script advice for dummy effects |
| Mount, pet, toy | the item or spell that teaches it can be obtained |
| Achievement | known to the core; `achievement_reward` when it has a reward |
| Flight point | a flight master spawned next to the node |

## The Forge

| Fix | What it writes |
|---|---|
| Core data | Copies the official records the core does not know (items, spells, mounts, pets, toys, achievements, talents, and every record they depend on) into the `hotfixes` database |
| Trainers | Teaches class and profession spells nobody teaches, at the spawned trainers of the same skill line, with a level and a price taken from their neighbours |
| Vendors | Sells items that have no source at the vendor you choose |
| Instances | Creates the `instance_template` row of dungeons and raids |
| Spawns | Spawns the creatures and world objects the content needs at their official positions ([locator](docs/SPAWNS.md)) |

Every fix can be previewed and downloaded as SQL before it is applied. `tools/forge-check.php` dry-runs all of them
with the website's own engine, inside transactions that are always rolled back. See [docs/FORGE.md](docs/FORGE.md).

## Quick start

**Windows:** download the code, double-click `ForeverTracker.bat` and follow the setup wizard.

![Launcher](docs/screenshots/launcher.png)

**By hand** (any system). Requirements: PHP 8.0+ with `mysqli` (and `gmp` for game-account login), Python 3.9+ (standard library only), the
`mysql` command-line client, your server's `world`, `hotfixes` and `auth` databases, and the official client.

```bash
cp config.sample.ini config.ini            # then edit it
mapextractor -i "<client>" -o extract -e 2 -l enUS
python tools/tracker.py snapshot --dbc extract/dbc --build 70450
python tools/tracker.py analyse
python tools/tracker.py locate               # optional: unspawned creatures and their positions
./start.sh                                  # Windows: start.bat
```

Open `http://127.0.0.1:8090/`. The full guide is in [docs/INSTALL.md](docs/INSTALL.md).

## Safety

- The analysis only reads the game databases.
- The Forge writes only when an administrator confirms, in one transaction per operation, and keeps the exact SQL
  and its rollback in the history. Back up your databases anyway.

## License

MIT — see [LICENSE](LICENSE). Forever Tracker is an unofficial tool, not affiliated with Blizzard Entertainment.
World of Warcraft content and icons © Blizzard Entertainment; icons are loaded from Wowhead's public image CDN.
