# The Forge

The Forge creates what your server lacks from the official client data. It is reserved to administrators.

![The Forge](screenshots/forge.png)

It has four parts:

- **Items**: every item players cannot get on your server, with the reason, and its sources when an imported file
  has them (drop, chest, vendor, quest reward). Apply the imported sources, or sell the item at a vendor you choose.
- **Fixes**: rows to add to the `hotfixes` and `world` databases (core data, trainers, instances). Below.
- **Spawns**: creatures and world objects the server knows but never spawned, placed at their official positions.
- **Imports**: world data files in WowPacketParser's SQL format, merged safely.

Spawns and imports are described in [SPAWNS.md](SPAWNS.md).

### Why most items have no source

Drops, vendor lists and quest rewards are server data: the game client never contains them, so the analysis can only
say that an item has no source on your server. Their sources come from imported world data files (WowPacketParser's
SQL format), or from your own choice (a vendor). An item whose source exists but is inactive (a creature or a vendor
that is not spawned, a recipe nobody teaches) is fixed from *Spawns* or *Trainers*.

## Fixes

| Tab | When it is offered | What it writes | Takes effect |
|---|---|---|---|
| Core data | An item, spell, recipe, talent, mount, pet, toy or achievement exists in the client but not in the server data | The official records into the `hotfixes` database (`item`, `item_sparse`, `spell_name`, `spell_effect`, `mount`, `achievement`, `criteria_tree`…), plus the records they depend on that the server lacks (item effects and their spells, appearances, durations, ranges, radiuses, categories, triggered spells). French texts go to the `_locale` tables. | Restart of the worldserver |
| Trainers | A class or profession spell is known to the core but nobody teaches it, and trainers of the same skill line are spawned | `trainer_spell` rows for those trainers: level (or skill rank) of the spell, previous rank as requirement, price from the spells around it | Restart of the worldserver |
| Items › Sell at the vendor | An item has no source (no loot, vendor, quest, craft or shop) | `npc_vendor` rows for the vendor you choose (unlimited stock, no extended cost) | `.reload npc_vendor` or restart |
| Items › Apply imported sources | An imported file has the item's loot, vendor or quest | Those rows, merged like an import | Restart |
| Instances | A dungeon or raid has no `instance_template` row | The row, with its parent map and no script | Restart |

Spells that Blizzard teaches with an item (recipes, books, runes) are not offered at trainers: sell or drop the
item instead (*Vendors*). Hunter pet abilities are learned by taming and pet training, never at trainers.

The *Push to clients* option of the *Core data* tab also adds `hotfix_data` rows, which send the records to
connected game clients. It is not needed when players use the official client of the same build.

## How a fix is applied (no "duplicate entry" errors)

The analysis does not store ready-made SQL. Each fix holds **operations**: a table, its columns, the rows to add, and
the columns that identify a row (`ID` for hotfix tables, `TrainerId` + `SpellId` for `trainer_spell`, `map` for
`instance_template`, `entry` + `item` + `ExtendedCost` + `type` for `npc_vendor`). When you preview or apply a
selection, the website:

1. **merges** the operations of every selected fix. Two fixes often need the same rows: a spell that is both a
   class spell and a recipe, two spells that share a duration, a range or a triggered spell. Each row is kept once;
2. **asks the server** which of those rows already exist, at that moment, and leaves them out. A row added by hand,
   by an earlier Forge operation or by a core update is never inserted twice. Hotfix records are matched by `ID`,
   whatever their `VerifiedBuild`;
3. inserts the remaining rows in **one transaction per database**: if a statement fails, nothing is kept;
4. saves a **rollback that deletes exactly the inserted rows**, by primary key.

So applying the same selection twice, selecting overlapping fixes, or applying after a change on the server is
safe: the second time the Forge answers that everything is already there. The preview says how many rows are new,
already present or shared by several fixes.

## Applying

1. Select fixes (filters: type, *New in Forever*, *Internal / test*, name or ID).
2. **Preview SQL** shows the exact statements and their rollback, computed against the live databases;
   **.sql** downloads them, to apply by hand.
3. **Apply** asks for a confirmation, then runs them as described above.
4. The operation, its SQL and its rollback are saved in the history (`ft_forge_log`).

A resource page lists its own fixes too, with *SQL* and *Apply* buttons.

## Rolling back

*History* lists every operation with its SQL. **Roll back** runs the saved rollback in a transaction: it deletes the
rows the operation inserted, and nothing else. Spawns made through the remote console are rolled back with
`.forge delete` (see [SPAWNS.md](SPAWNS.md)).

## Checking every fix

```bash
php tools/forge-check.php [hotfix|trainer|instance] [--each]
```

plans every fix with the website's own engine against the databases as they are now, applies them all as one
selection (what *select all* does), runs the rollback, checks that it removed exactly the inserted rows, and rolls
everything back. Nothing is kept. `--each` also checks every fix alone, so a failure names its fix. Use the same
PHP as the website (for example `php -c php.ini tools/forge-check.php`). Every table the Forge writes must be InnoDB.

## After applying

Restart the worldserver, then run the analysis again (*Run again* in the Forge) to refresh the statuses. Fixes
applied since the last analysis are greyed out in the lists.

## Troubleshooting

| Message | Meaning |
|---|---|
| *N fix(es) come from an older analysis* | The fixes were computed by ForeverTracker 1.0. Run the analysis again. |
| *Everything selected is already on the server* | Nothing to do: the rows exist (applied before, or added by hand). |
| *Table x.y does not exist on this server* | Your core has no such hotfix table (older or newer core). Those rows are left out; the rest is applied. |
| A MySQL error during *Apply* | Nothing was kept (transaction). The error and the SQL are in the history; `forge-check.php --each` finds the fix. |
