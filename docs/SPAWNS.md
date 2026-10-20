# The locator, spawns and imports

Many quests, vendors and drops do not work on a private server only because a creature or a world object was never
spawned: the template exists (`creature_template`, `gameobject_template`), the quest points to it, but nobody stands
in the world. The locator finds them and where they stand on Wowhead's public maps; the Forge spawns them.

```
analysis ──> what the content needs and nobody spawned ──> locator: Wowhead map positions ──> Forge: spawn
```

## 1. Run the locator

After an analysis, from **The Forge › Spawns › Run**, or:

```bash
python tools/tracker.py locate              # asks Wowhead only for what it does not know yet
python tools/tracker.py locate --limit 200  # at most 200 new questions this time
python tools/tracker.py locate --offline    # only the answers already cached
python tools/tracker.py locate --refresh    # ask again for everything
```

The first run takes a few minutes (one question every 0.4 s, `[wowhead] pause`); later runs take seconds. Answers
are cached in `data/wowhead/npc/<id>.json` and `data/wowhead/object/<id>.json`.

### What it looks for

Only resources the tracker shows (not internal or test content), and only templates that exist and have no spawn:

| Needed as | Found from |
|---|---|
| Quest giver / ender | `creature_queststarter`, `creature_questender`, `gameobject_queststarter`, `gameobject_questender` of the quests whose giver or ender check fails |
| Quest objective | `quest_objectives` (creatures to kill, objects to use) of the quests whose objectives check fails |
| Vendor | `npc_vendor` entries selling an item that has no other source |
| Drops a missing item | `creature_loot_template` (through `creature_template_difficulty`) of items that have no source |
| Trainer | `creature_trainer` / `trainer_spell` of class and profession spells nobody can learn |
| Boss | a `creature_template` with the name of a dungeon encounter that has no spawned creature of that name |

### Where the positions come from

For each candidate the locator reads Wowhead's public Forever tooltip (`nether.wowhead.com/forever/tooltip/npc/<id>`),
the same data its tooltips show: the zone (an AreaTable id) and the points on that zone's map, in percent, per
floor. These percentages are turned into world coordinates with the client's own map tables — `UiMap`,
`UiMapAssignment` (the world rectangle drawn on each part of a map) and `UiMapGroupMember` (floors) — the inverse
of what the game does to draw a creature on your map. Measured against creatures that are spawned on a server, the
error is a few yards (1 to 12) outdoors.

Then:

- **Packs and single creatures.** Quest objectives come in packs: each distinct point is a spawn (points closer
  than 6 yards are merged). Anything else (givers, vendors, trainers, rares, bosses) is one creature per place:
  Wowhead's several points around it are its wandering, so points within 60 yards are merged into one spawn.
- **Height.** Wowhead has no height. The locator notes the height of the nearest existing spawn (within 20 yards)
  as a hint; the server computes the real ground when it spawns (see the modes below).
- **Facing.** Towards the existing spawns around, as townsfolk face their town; otherwise north.
- **Wandering.** Quest objectives and drops wander 5 yards around their spawn point; others stand still.

### States

| State | Meaning | What to do |
|---|---|---|
| Ready | World positions are known | Spawn them (any mode) |
| Place in game | Inside a dungeon or raid: Wowhead gives no position there | ForeverForge addon, *Spawn here* |
| No position | Wowhead's Forever branch has no position: often content Blizzard does not use in Forever, a retail duplicate of a name, or a creature that only appears through a script or an event | Usually nothing |
| Not looked up | `--limit`, `--offline`, or Wowhead could not be reached | Run the locator again |

## 2. Spawn

Select rows in **The Forge › Spawns** (or *All N* for the whole filter), then **Spawn…**. Entries that got a
spawn since the locator ran are left out automatically. Four ways:

### On the running server (recommended)

The website connects to the worldserver's **remote console** (RA) and runs, for each point:

```
.forge spawn npc <entry> <map> <x> <y> [zHint] [orientation] [wander]
.forge spawn go  <entry> <map> <x> <y> [zHint] [orientation]
```

The server computes the height from its maps and vmaps (the floor right under the hint, buildings included; the
highest ground when there is no hint), saves the spawn in the world database exactly like `.npc add`, and shows it at
once where players are. The operation is saved in the history; **Roll back** runs `.forge delete npc|go <guid>` for
each spawn.

Requirements:

1. **The ForeverTracker core module.** `core/forge_commands.cpp` adds the `.forge` commands. Copy it to
   `src/server/scripts/Custom/` of your TrinityCore source, declare and call it in
   `src/server/scripts/Custom/custom_script_loader.cpp`:

   ```cpp
   void AddSC_forge_commandscript();

   void AddCustomScripts()
   {
       // ... existing calls
       AddSC_forge_commandscript();
   }
   ```

   then rebuild the worldserver (CMake picks the new file up when it configures again). It needs no database change.
   `.forge version` answers `FORGE OK version 1`.
2. **The remote console** in `worldserver.conf`: `Ra.Enable = 1`, `Ra.IP = "127.0.0.1"`, `Ra.Port = 3443`.
3. **A game account for it** in `config.ini`:

   ```ini
   [console]
   host = "127.0.0.1"
   port = 3443
   user = "1#1"          ; a GAME account (auth.account), not the Battle.net e-mail
   password = "..."
   ```

   The account needs GM level 3 on all realms: `account_access.SecurityLevel >= 3` with `RealmID = -1`. The
   dialog says whether the console answers and whether the core has the module.

### In game, with the ForeverForge addon

For any TrinityCore server, with or without the module, and for bosses in dungeons.

1. **Install into the game** (when `[client] path` is set) or **Download the addon** and unzip it into
   `<game>/_classic_beta_/Interface/AddOns/` (or the folder of your client). The download contains the queue of
   the selection (`Queue.lua`).
2. In game, as a GM (`.gm on`), type `/forge` and press **Start**.
   - With the core module, the addon spawns every point in place with `.forge spawn`: no travelling.
   - Without it, it goes to each point with `.go xyz` and spawns where it lands with `.npc add` / `.gobject add`.
   Commands go through TrinityCore's addon command channel, so each step waits for the server's answer. **Pause**,
   **Skip** and **Go to next** are there for hand work. Progress is saved (`/forge reset` forgets it).
3. **Bosses and dungeon creatures**: enter the instance; the window lists what belongs there. Stand where each one
   belongs, face the right way and press **Spawn here**.

After a new export, `/reload` in game.

### In the database

Writes `creature` / `gameobject` rows in one transaction, with an exact rollback, like the other fixes. Only the
points that have an existing spawn within 15 yards are written: their height is borrowed from it. The
**worldserver must be stopped** (it hands out spawn ids itself and would overwrite rows added behind its back); the
Forge refuses while the remote console answers. Start the worldserver afterwards.

### GM commands

The list of `.go xyz` and `.npc add` / `.gobject add` commands, to paste in game by hand or in macros.

## 3. ForeverScribe: vendors, loot and quest givers while you play

Wowhead gives positions, but not what a creature drops or what a vendor sells: loot tables, vendor lists and quest
givers are server data, they are never in the game's data files. The game shows them while you play, and the
**ForeverScribe** addon (`addon/ForeverScribe`) notes them through the normal addon API — nothing else runs next to
the game.

1. **The Forge › Imports › Install into the game** (when `[client] path` is set), or **Download the addon** and unzip
   it into `<game>/_classic_beta_/Interface/AddOns/` (or the folder of your client).
2. Play with it enabled. It notes:

   | When | What |
   |---|---|
   | A vendor window opens | Every item, its slot, stock limit and cost in items or currencies |
   | A loot window opens | The creature or chest it comes from (each corpse counted once), each item and its count; skinning and pick pocketing are kept apart |
   | A quest dialog opens / a quest is handed in | The quest giver, the quest ender, the reward items |
   | You talk to, buy from, or loot a creature or object | Where you stand: world coordinates (`UnitPosition`) outside, the zone map otherwise; the instance only, inside dungeons |

   It collects **only on the official realms**: a client connected to a private server (your own one included)
   uses another `portal` than `us`, `eu`, `kr`, `tw` or `cn`, and nothing is noted there. `/scribe off` and `/scribe on` force it,
   `/scribe auto` goes back to that rule; the state shows at login and with `/scribe`.

   `/scribe` shows what was noted, `/scribe reset` clears it. No character, realm or account name is kept. The notes
   are written to `WTF/Account/<account>/SavedVariables/ForeverScribe.lua` when you log out or `/reload`.
3. **Import.** While `ForeverTracker.exe` runs, it imports the notes that changed every minute
   (`php tools/collector-sync.php`); **Import new notes** on the Imports page does the same, and the file can also be
   sent there by hand. Each import becomes a card like any other: tick the tables, **Preview**, **Apply what is
   missing**. With `[collector] auto_apply = 1`, new notes are applied at once (each import in the history, with its
   rollback).

How the notes become rows:

| Notes | Rows |
|---|---|
| Vendor list | `npc_vendor` (stock limit → `maxcount`, one hour restock). The vendor flag (`npcflag` 128) is added to a creature that lacks it. An item paid with items or currencies takes the `ExtendedCost` of another vendor selling it; without one it is left out and counted |
| Creature loot | `creature_loot_template` in the creature's own loot table (`LootID`, filled when it is 0); chance = drops ÷ corpses looted, quest objective items marked `QuestRequired`. An item the table already gives — directly or through `reference_loot_template`, at any depth — is left out |
| Chest loot | `gameobject_loot_template` of the chest's loot table (`Data1`) |
| Quest dialogs | `creature_queststarter`, `creature_questender` (and the `gameobject_` ones) for quests the server knows |
| Positions | `ft_collected`: the locator uses them before Wowhead, so a creature you met in game becomes *Ready* to spawn |

Chances settle as more corpses are looted: a drop seen once on two corpses reads 50 %. Check rare drops before
applying, or apply only the tables you trust.

## 4. Imports: WowPacketParser SQL files

ForeverTracker can also import world data written in
[WowPacketParser](https://github.com/TrinityCore/WowPacketParser)'s SQL format — the format TrinityCore's own world
data is shared in — or any file of `INSERT` statements for your world tables.

1. **The Forge › Imports**: send the file (up to PHP's upload limit), or copy big files into `data/imports/` and
   press *Import*. From a shell: `php tools/wpp-import.php file.sql [--name "…"] [--check]`.
2. Each import lists what it holds per table (spawns, new creatures and objects, loot, vendors, quests, givers and
   enders, gossip…). Tick the tables, **Preview**, then **Apply what is missing**.

What the merge does:

- only `INSERT` / `REPLACE` rows are read; the file's `DELETE` and `UPDATE` statements are **never run**;
- rows your server already has (same primary key, or same `Entry, ItemType, Item` for loot) are left out;
- spawns (`@CGUID+n`, `@OGUID+n` or plain guids) get **new guids** after the server's highest; a spawn whose creature
  or object already stands within 3 yards on the same map is left out, with its `creature_addon` and other rows
  that depend on its guid. The worldserver must be stopped to add spawns (it hands out guids itself);
- loot works at once: a creature whose `creature_template_difficulty.LootID` is 0 gets its own loot table
  (filled only where it is 0; the rollback empties it again);
- columns your core does not have are dropped, tables it does not have are listed and left out;
- one transaction per database, and the history keeps the exact SQL and its rollback.

**The Forge › Items** then shows, for every item nobody can get, the sources found in each import — *Drop*,
*Chest*, *Sold by*, *Quest reward* — with the filter *Found in an import*. Select items and **Apply imported
sources**: only their loot rows, vendor rows and rewarding quests are added.

## Limits

- **Dungeons and raids** have no position on Wowhead: place them in game with the addon.
- **Phases and events.** Spawns are created in the default phase. A creature that belongs to a phase, a game event or
  a script (summoned by a boss, escorting a quest) may need its `PhaseId`, `game_event_creature` row or script set by
  hand afterwards.
- **Positions** are those of the official game, a few yards apart. Check important ones (quest givers in buildings)
  in game; `.npc move` fixes one.
- **Height in places with several levels** (cities with canals, cellars and towers, like Stormwind): the database
  mode borrows the height of the nearest existing spawn, which can stand one level lower or higher. Prefer the
  server mode there, and check in game. A wrong batch is undone with **Roll back** in the history.
- **Creatures without a template** cannot be spawned: the template (model, faction, level) is not on Wowhead.
- Wowhead's data belongs to Wowhead: the locator asks politely (one request at a time, cached forever) and only for
  what your server lacks. Use it for your own server.

## Commands of the core module

| Command | Answer |
|---|---|
| `.forge version` | `FORGE OK version 1` |
| `.forge height <map> <x> <y> [zHint]` | `FORGE OK height <z>` |
| `.forge spawn npc <entry> <map> <x> <y> [zHint] [o] [wander]` | `FORGE OK npc <guid> <entry> <map> <x> <y> <z>` |
| `.forge spawn go <entry> <map> <x> <y> [zHint] [o]` | `FORGE OK go <guid> <entry> <map> <x> <y> <z>` |
| `.forge delete npc <guid>` / `.forge delete go <guid>` | `FORGE OK deleted npc <guid>` |

Errors start with `FORGE ERR`. Permissions: those of `.npc add`, `.npc delete`, `.gobject add`, `.gobject delete`.
They work from the console, the remote console, SOAP and in game.
