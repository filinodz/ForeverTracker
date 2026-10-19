# The Forge

The Forge creates what your server lacks from the official client data. It is reserved to administrators.

![The Forge](screenshots/forge.png)

## Fixes

| Tab | When it is offered | What it writes | Takes effect |
|---|---|---|---|
| Core data | An item, spell, recipe, talent, mount, pet, toy or achievement exists in the client but not in the server data | The official records into the `hotfixes` database (`item`, `item_sparse`, `spell_name`, `spell_effect`, `mount`, `achievement`, `criteria_tree`…), plus the records they depend on that the server lacks (item effects and their spells, appearances, durations, ranges, radiuses, categories, triggered spells). French texts go to the `_locale` tables. | Restart of the worldserver |
| Trainers | A class or profession spell is known to the core but nobody teaches it, and trainers of the same skill line are spawned | `trainer_spell` rows for those trainers: level (or skill rank) of the spell, previous rank as requirement, price from the spells around it | Restart of the worldserver |
| Vendors | An item has no source (no loot, vendor, quest, craft or shop) | `npc_vendor` rows for the vendor you choose (unlimited stock, no extended cost) | `.reload npc_vendor` or restart |
| Instances | A dungeon or raid has no `instance_template` row | The row, with its parent map and no script | Restart |

Spells that Blizzard teaches with an item (recipes, books, runes) are not offered at trainers: sell or drop the
item instead (*Vendors*). Hunter pet abilities are learned by taming and pet training, never at trainers.

The *Push to clients* option of the *Core data* tab also adds `hotfix_data` rows, which send the records to
connected game clients. It is not needed when players use the official client of the same build.

## Applying

1. Select fixes (filters: type, *New in Forever*, *Internal / test*, name or ID).
2. **Preview SQL** shows the exact statements and their rollback; **.sql** downloads them, to apply by hand.
3. **Apply** asks for a confirmation, then runs every statement of every database in one transaction: if one
   statement fails, nothing is kept.
4. The operation, its SQL and its rollback are saved in the history (`ft_forge_log`).

A resource page lists its own fixes too, with *SQL* and *Apply* buttons.

## Rolling back

*History* lists every operation with its SQL. **Roll back** runs the saved rollback in a transaction. Rollbacks
delete exactly the rows the operation added (by primary key, and by `VerifiedBuild` for hotfix rows).

## Checking every fix

```bash
python tools/check_fixes.py [--kind hotfix|trainer|instance]
```

applies each generated fix and its rollback inside a transaction that is always rolled back, and reports the fixes
that fail. Nothing is kept. Every table the Forge writes must be InnoDB.

## After applying

Restart the worldserver, then run the analysis again (*Run again* in the Forge) to refresh the statuses. Fixes
applied since the last analysis are greyed out in the lists.
