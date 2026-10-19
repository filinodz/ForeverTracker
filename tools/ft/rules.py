"""Availability rules: one list of weighted checks per resource. Labels come from the check code
(translated by the website); details are given in English and French."""
import math
import re

from .server import log


def L(en, fr):
    return {'en': en, 'fr': fr}


def check(ok, code, detail=None, weight=1):
    return {'ok': bool(ok), 'code': code, 'detail': detail or L('', ''), 'w': weight}


def join(parts):
    """[L(...), ...] -> L(', '.join(en), ', '.join(fr))"""
    return L(', '.join(p['en'] for p in parts), ', '.join(p['fr'] for p in parts))


SOURCES = {
    'loot': L('Creature loot', 'Butin de PNJ'), 'object': L('Chest / world object', 'Coffre / objet du monde'),
    'pickpocket': L('Pickpocketing', 'Vol à la tire'), 'skinning': L('Skinning / gathering', 'Dépeçage / récolte'),
    'fishing': L('Fishing', 'Pêche'), 'mail': L('Mail', 'Courrier'), 'disenchant': L('Disenchanting', 'Désenchantement'),
    'prospecting': L('Prospecting', 'Prospection'), 'milling': L('Milling', 'Broyage'), 'questdrop': L('Quest loot', 'Butin de quête'),
    'vendor': L('Vendor', 'Marchand'), 'shop': L('Shop', 'Boutique'), 'container': L('Container content', 'Contenu d’un conteneur'),
    'quest': L('Quest reward', 'Récompense de quête'), 'craft': L('Crafting', 'Artisanat'),
}


def evaluate_all(content, ctx):
    log('evaluating availability')
    known = ctx['known']
    npc_spawned, go_spawned = ctx['spawned_npc'], ctx['spawned_go']

    trainer_npcs = {}
    for npc, trainer in ctx['trainers']:
        if npc in npc_spawned:
            trainer_npcs.setdefault(trainer, set()).add(npc)
    ctx['trainer_npcs'] = trainer_npcs
    trained = {spell for trainer, spell in ctx['trainer_spells'] if trainer in trainer_npcs}
    trained_any = {spell for _, spell in ctx['trainer_spells']}

    # ---- item sources as a fixed point (loot references, containers, quest rewards, crafts)
    references = {}
    for entry, kind, item, ref in ctx['loot']['reference']:
        references.setdefault(entry, []).append((kind, item, ref))

    def expand(rows):
        out, stack, seen = set(), list(rows), set()
        while stack:
            kind, item, ref = stack.pop()
            if ref and ref not in seen:
                seen.add(ref)
                stack.extend(references.get(ref, []))
            elif kind == 0 and item:
                out.add(item)
        return out

    def table_items(name, entries=None):
        grouped = {}
        for entry, kind, item, ref in ctx['loot'][name]:
            if entries is None or entry in entries:
                grouped.setdefault(entry, []).append((kind, item, ref))
        return {entry: expand(rows) for entry, rows in grouped.items()}

    npc_loot_ids, pick_ids, skin_ids = set(), set(), set()
    for npc, loot_id, pick, skin in ctx['npc_loot']:
        if npc in npc_spawned:
            loot_id and npc_loot_ids.add(loot_id)
            pick and pick_ids.add(pick)
            skin and skin_ids.add(skin)
    go_loot_ids = {data for entry, _type, data in ctx['go_loot'] if entry in go_spawned and data}
    sources = {}

    def add(items, code):
        for item in items:
            sources.setdefault(item, set()).add(code)

    for items in table_items('creature', npc_loot_ids).values():
        add(items, 'loot')
    for items in table_items('gameobject', go_loot_ids).values():
        add(items, 'object')
    for items in table_items('pickpocketing', pick_ids).values():
        add(items, 'pickpocket')
    for items in table_items('skinning', skin_ids).values():
        add(items, 'skinning')
    for name in ('fishing', 'mail', 'disenchant', 'prospecting', 'milling'):
        for items in table_items(name).values():
            add(items, name)
    add({item for npc, item in ctx['quest_items_npc'] if npc in npc_spawned} | {item for go, item in ctx['quest_items_go'] if go in go_spawned}, 'questdrop')
    add({item for npc, item in ctx['vendors'] if npc in npc_spawned}, 'vendor')
    add(ctx['shop_items'], 'shop')
    loot_unplaced = set()
    for items in table_items('creature').values():
        loot_unplaced |= items
    vendor_unplaced = {item for _, item in ctx['vendors']}

    container_items = table_items('item')
    spell_loot = table_items('spell')
    quests = content['quest']
    starters, enders = {}, {}
    for npc, quest in ctx['q_start_npc']:
        starters.setdefault(quest, []).append(('npc', npc, npc in npc_spawned))
    for go, quest in ctx['q_start_go']:
        starters.setdefault(quest, []).append(('go', go, go in go_spawned))
    for npc, quest in ctx['q_end_npc']:
        enders.setdefault(quest, []).append(('npc', npc, npc in npc_spawned))
    for go, quest in ctx['q_end_go']:
        enders.setdefault(quest, []).append(('go', go, go in go_spawned))
    objectives = {}
    for quest, kind, obj, amount in ctx['objectives']:
        objectives.setdefault(quest, []).append((kind, obj, amount))
    disabled_quests = ctx['disabled'].get(1, set())

    available_quests = set()
    previous_state = None
    for _round in range(40):  # long quest chains (reward -> item -> next quest) need several passes
        state = (len(available_quests), len(sources), sum(len(v) for v in sources.values()))
        if state == previous_state:
            break
        previous_state = state
        obtainable = set(sources)
        for container, items in container_items.items():
            if container in obtainable:
                add(items, 'container')
        for quest in available_quests:
            row = ctx['quests'].get(quest, {})
            add({int(row.get(f'RewardItem{i}') or 0) for i in range(1, 5)} - {0}, 'quest')
            add({int(row.get(f'RewardChoiceItemID{i}') or 0) for i in range(1, 7)} - {0}, 'quest')
        learnable = trained | ctx['auto_learn'] | {spell for spell, items in ctx['teaches'].items() if items & set(sources)}
        for item, spells in ctx['creates'].items():
            if spells & learnable:
                add([item], 'craft')
        for spell, items in spell_loot.items():
            if spell in learnable:
                add(items, 'craft')
        obtainable = set(sources)
        for quest in quests:
            if quest in ctx['quests'] and quest not in disabled_quests:
                start_ok = any(s[2] for s in starters.get(quest, [])) or ctx['start_quest_item'].get(quest) in obtainable
                end_ok = any(e[2] for e in enders.get(quest, []))
                if start_ok and end_ok:
                    available_quests.add(quest)
    obtainable = set(sources)
    quest_spells = set()
    for quest in available_quests:
        row = ctx['quests'].get(quest, {})
        quest_spells |= {int(row.get(k) or 0) for k in ('RewardSpell', 'RewardDisplaySpell1', 'RewardDisplaySpell2', 'RewardDisplaySpell3')} - {0}
    learnable = trained | ctx['auto_learn'] | quest_spells | ctx['create_spells'] | {spell for spell, items in ctx['teaches'].items() if items & obtainable}
    for source, target in ctx['learn_chain']:
        if source in learnable:
            learnable.add(target)
    ctx['obtainable'], ctx['learnable'], ctx['item_sources'] = obtainable, learnable, sources
    results = {}

    def item_result(item_id, entity):
        checks = [check(item_id in known['item'], 'core', L('In the server DB2 or in hotfixes.item_sparse.', 'Présent dans les DB2 du serveur ou dans hotfixes.item_sparse.'), 2)]
        found = sorted(sources.get(item_id, []))
        if found:
            checks.append(check(True, 'source', join([SOURCES[s] for s in found]), 3))
        else:
            hint = []
            if item_id in loot_unplaced:
                hint.append(L('loot of a creature that is not spawned', 'butin d’un PNJ non placé'))
            if item_id in vendor_unplaced:
                hint.append(L('sold by a creature that is not spawned', 'vendu par un PNJ non placé'))
            if item_id in ctx['creates']:
                hint.append(L('recipe that cannot be learned', 'recette non apprenable'))
            detail = L('Inactive source: ' + join(hint)['en'], 'Source existante mais inactive : ' + join(hint)['fr']) if hint else \
                L('No source: loot, vendor, quest, craft or shop.', 'Aucune source : butin, marchand, quête, artisanat ou boutique.')
            checks.append(check(False, 'source', detail, 3))
        return checks

    def spell_result(spell_id, entity):
        checks = [check(spell_id in known['spell'], 'core', L('In the server SpellName.db2 or hotfixes.spell_name.', 'Présent dans SpellName.db2 du serveur ou hotfixes.spell_name.'), 2)]
        category = entity['sub']
        if category in ('class', 'profession', 'racial', 'talent', 'mount', 'rune', 'pet'):
            how = []
            if spell_id in trained:
                how.append(L('trainer', 'entraîneur'))
            if spell_id in ctx['auto_learn'] or spell_id in ctx['create_spells']:
                how.append(L('learned automatically', 'appris automatiquement'))
            if spell_id in quest_spells:
                how.append(L('quest reward', 'récompense de quête'))
            if spell_id in ctx['pet_training']:
                how.append(L('pet (training or creature spell)', 'familier (entraînement ou sort de PNJ)'))
            if spell_id in learnable and not how:
                how.append(L('learned through another spell', 'appris via un autre sort'))
            if ctx['teaches'].get(spell_id, set()) & obtainable:
                how.append(L('obtainable item', 'objet obtenable'))
            if category == 'talent':
                how.append(L('talent', 'talent'))
            detail = join(how) if how else (L('A trainer has it but is not spawned.', 'Entraîneur défini mais non placé.') if spell_id in trained_any
                                            else L('No trainer, automatic learning or item.', 'Ni entraîneur, ni apprentissage automatique, ni objet.'))
            checks.append(check(bool(how), 'learn', detail, 2))
        if entity.get('scriptable'):
            checks.append(check(spell_id in ctx['scripted_spells'], 'script', L('To check: a dummy/script effect often needs a SpellScript (spell_script_names).',
                                                                                 'À vérifier : effet « dummy »/« script », un SpellScript est souvent nécessaire (spell_script_names).'), 0))
        return checks

    def talent_result(talent_id, entity):
        checks = [check(talent_id in known['talent'], 'core', L('In the server Talent.db2.', 'Présent dans Talent.db2 du serveur.'), 2)]
        missing = [s for s in entity['ranks'] if s in content['spell'] and s not in known['spell'] and s not in ctx['spell_ids']]
        checks.append(check(not missing, 'ranks', L(f"Missing spells: {', '.join(map(str, missing))}", f"Sorts manquants : {', '.join(map(str, missing))}") if missing
                            else L(f"{len(entity['ranks'])} rank(s)", f"{len(entity['ranks'])} rang(s)"), 2))
        unscripted = [s for s in entity['ranks'] if content['spell'].get(s, {}).get('scriptable') and s not in ctx['scripted_spells']]
        if any(content['spell'].get(s, {}).get('scriptable') for s in entity['ranks']):
            checks.append(check(not unscripted, 'talent_script', L('To check, dummy effects without SpellScript: ' + ', '.join(map(str, unscripted)),
                                                                   'À vérifier, effets « dummy » sans SpellScript : ' + ', '.join(map(str, unscripted))) if unscripted
                                else L('SpellScript present', 'SpellScript présent'), 0))
        return checks

    def quest_result(quest_id, entity):
        row = ctx['quests'].get(quest_id)
        checks = [check(quest_id in known['quest'], 'core', L('Server QuestV2.db2 or hotfixes.quest_v2.', 'QuestV2.db2 du serveur ou hotfixes.quest_v2.'), 1),
                  check(row is not None, 'quest_row', L('quest_template row (title, rewards, objectives).', 'Ligne quest_template (titre, récompenses, objectifs).'), 3)]
        if row is None:
            return checks
        start = starters.get(quest_id, [])
        item_start = ctx['start_quest_item'].get(quest_id)
        start_ok = any(s[2] for s in start) or item_start in obtainable
        def listing(rows, en_prefix, fr_prefix):
            en = ', '.join(f"{r[1]}{'' if r[2] else ' (not spawned)'}" for r in rows)
            fr = ', '.join(f"{r[1]}{'' if r[2] else ' (non placé)'}" for r in rows)
            return (en_prefix + en if en else ''), (fr_prefix + fr if fr else '')
        is_npc = bool(start) and start[0][0] == 'npc'
        en, fr = listing(start, 'NPC ' if is_npc else 'Object ', 'PNJ ' if is_npc else 'Objet ')
        if item_start:
            en = (en + ' · ' if en else '') + f"item {item_start}{'' if item_start in obtainable else ' (not obtainable)'}"
            fr = (fr + ' · ' if fr else '') + f"objet {item_start}{'' if item_start in obtainable else ' (non obtenable)'}"
        checks.append(check(start_ok, 'starter', L(en or 'No quest giver (creature/gameobject_queststarter).', fr or 'Aucun donneur (creature/gameobject_queststarter).'), 2))
        end = enders.get(quest_id, [])
        en, fr = listing(end, '', '')
        checks.append(check(any(e[2] for e in end), 'ender', L(en or 'No quest ender.', fr or 'Aucun valideur.'), 2))
        problems = []
        for kind, obj, amount in objectives.get(quest_id, []):
            if kind == 0 and obj not in npc_spawned:
                problems.append(L(f'creature {obj} not spawned', f'PNJ {obj} non placé'))
            elif kind == 1 and obj not in obtainable and obj != int(row.get('StartItem') or 0):
                problems.append(L(f'item {obj} not obtainable', f'objet {obj} non obtenable'))
            elif kind == 2 and obj not in go_spawned:
                problems.append(L(f'object {obj} not spawned', f'objet du monde {obj} non placé'))
        if objectives.get(quest_id):
            count = len(objectives[quest_id])
            detail = L('; '.join(p['en'] for p in problems[:4]), '; '.join(p['fr'] for p in problems[:4])) if problems else L(f'{count} objective(s)', f'{count} objectif(s)')
            checks.append(check(not problems, 'objectives', detail, 2))
        if quest_id in disabled_quests:
            checks.append(check(False, 'enabled', L('Disabled in the disables table.', 'Désactivée dans la table disables.'), 3))
        return checks

    boxes = {}
    for ui in ctx['ui_maps']:
        for a in ui.get('assignments', []):
            if a.get('area'):
                boxes.setdefault(a['area'], []).append((a['map'], a['region']))
    zone_spawns = {}
    for zone_id, regions in boxes.items():
        count = 0
        for map_id, (min_x, min_y, max_x, max_y) in regions:
            count += sum(1 for m, x, y, _ in ctx['spawns'] if m == map_id and min_x <= x <= max_x and min_y <= y <= max_y)
        zone_spawns[zone_id] = count

    official_quests_by_zone = {}
    for quest_id in quests:
        row = ctx['quests'].get(quest_id)
        if row and int(row['QuestSortID'] or 0) > 0:
            official_quests_by_zone.setdefault(int(row['QuestSortID']), []).append(quest_id)

    def normal(name):
        return re.sub(r'[^a-z0-9]', '', (name or '').lower())

    def boss_match(boss, map_id):
        targets = {normal(boss.get('name_en')), normal(boss.get('name'))} - {''}
        for npc in ctx['npc_maps'].get(map_id, []):
            if normal(npc['name']) in targets or any(t and t in normal(npc['name']) for t in targets if len(t) > 5):
                return npc
        return None

    def dungeon_result(map_id, entity):
        kind = entity['kind']
        tiles = ctx['map_tiles'].get(map_id, 0)
        vmap = map_id in ctx['vmaps']
        checks = [check(map_id in known['dungeon'], 'core', L('Server Map.db2.', 'Map.db2 du serveur.'), 1),
                  check(tiles > 0 or vmap, 'terrain', L(f"{tiles} .map tile(s), vmaps {'yes' if vmap else 'no'}", f"{tiles} tuile(s) .map, vmaps {'oui' if vmap else 'non'}"), 2),
                  check(map_id in ctx['mmaps'], 'mmaps', L('Advised: without mmaps creatures move in straight lines.', 'Conseillé : sans mmaps les PNJ se déplacent en ligne droite.'), 0)]
        if kind in (1, 2):
            script = ctx['instance_template'].get(map_id)
            checks.append(check(script is not None, 'instance_template', L(script or 'Row present', script or 'Ligne présente') if script is not None else L('No row: the instance cannot be created.', 'Aucune ligne : l’instance ne peut pas être créée.'), 2))
            checks.append(check(map_id in ctx['entrances'], 'entrance', L('areatrigger_teleport to this map.', 'areatrigger_teleport vers cette carte.') if map_id in ctx['entrances']
                                else L('No entrance teleporter.', 'Aucun téléporteur d’entrée.'), 2))
        else:
            checks.append(check(map_id in ctx['battlegrounds'] or kind == 4, 'battleground', L('battleground_template', 'battleground_template'), 2))
        npcs = ctx['npc_maps'].get(map_id, [])
        spawned = sum(n['n'] for n in npcs)
        objects = ctx['go_counts'].get(map_id, 0)
        checks.append(check(spawned >= 10, 'spawns', L(f'{spawned} creatures, {objects} world objects', f'{spawned} PNJ, {objects} objets du monde'), 3))
        encounters = [b for b in content['boss'].values() if b['map'] == map_id]
        if encounters:
            found = sum(1 for b in encounters if boss_match(b, map_id))
            checks.append(check(found == len(encounters), 'bosses', L(f'{found} / {len(encounters)} bosses found among the spawned creatures', f'{found} / {len(encounters)} boss trouvés parmi les PNJ placés'), 3))
        if map_id in ctx['disabled'].get(2, set()):
            checks.append(check(False, 'enabled', L('Map disabled in disables.', 'Carte désactivée dans disables.'), 3))
        return checks

    def boss_result(boss_id, entity):
        npc = boss_match(entity, entity['map'])
        checks = [check(npc is not None, 'boss_spawn', L(f"Creature {npc['id']} “{npc['name']}”", f"PNJ {npc['id']} « {npc['name']} »") if npc
                        else L('No spawned creature of that name in the instance.', 'Aucun PNJ placé de ce nom dans l’instance.'), 3)]
        if npc:
            checks.append(check(npc['scripted'], 'boss_script', L('ScriptName or SmartAI', 'ScriptName ou SmartAI') if npc['scripted']
                                else L('No script: the boss has no mechanics.', 'Pas de script : le boss n’a pas ses mécaniques.'), 2))
        return checks

    def zone_result(zone_id, entity):
        map_id = entity['map']
        entity['weak'] = zone_id not in boxes and not official_quests_by_zone.get(zone_id)
        checks = [check(ctx['map_tiles'].get(map_id, 0) > 0, 'terrain', L(f'map {map_id}', f'carte {map_id}'), 1)]
        if zone_id in boxes:
            n = zone_spawns.get(zone_id, 0)
            checks.append(check(n >= 20, 'spawns', L(f'≈{n} creatures inside the zone', f'≈{n} PNJ dans l’emprise de la zone'), 3))
        zone_quests = official_quests_by_zone.get(zone_id, [])
        if zone_quests:
            ready = sum(1 for qid in zone_quests if qid in available_quests)
            checks.append(check(ready >= len(zone_quests) * 0.8, 'zone_quests', L(f'{ready} / {len(zone_quests)} quests can be done', f'{ready} / {len(zone_quests)} quêtes faisables'), 3))
        checks.append(check(zone_id in ctx['graveyards'], 'graveyard', L('graveyard_zone', 'graveyard_zone'), 1))
        return checks

    def recipe_result(spell_id, entity):
        how = []
        if spell_id in trained:
            how.append(L('trainer', 'entraîneur'))
        if entity.get('auto') or spell_id in ctx['auto_learn']:
            how.append(L('learned automatically', 'appris automatiquement'))
        teach = ctx['teaches'].get(spell_id, set())
        if teach & obtainable:
            how.append(L('obtainable recipe', 'recette obtenable'))
        if how:
            detail = join(how)
        elif teach:
            ids = ', '.join(map(str, sorted(teach)[:3]))
            detail = L('Recipe exists but cannot be obtained: ' + ids, 'Recette existante mais non obtenable : ' + ids)
        else:
            detail = L('No trainer and no recipe.', 'Ni entraîneur ni recette.')
        return [check(spell_id in known['spell'], 'core', L('', ''), 1), check(bool(how), 'learn', detail, 3)]

    def collection_spell(spell_id):
        how = []
        if spell_id in trained:
            how.append(L('trainer', 'entraîneur'))
        items = ctx['teaches'].get(spell_id, set())
        if items & obtainable:
            ids = ', '.join(map(str, sorted(items & obtainable)[:3]))
            how.append(L('obtainable item ' + ids, 'objet obtenable ' + ids))
        return how, items

    def mount_result(mount_id, entity):
        how, items = collection_spell(entity['spell'])
        if how:
            detail = join(how)
        elif items:
            ids = ', '.join(map(str, sorted(items)[:3]))
            detail = L('Item exists but cannot be obtained: ' + ids, 'Objet existant mais non obtenable : ' + ids)
        else:
            detail = L('No item and no trainer.', 'Aucun objet ni entraîneur.')
        return [check(mount_id in known['mount'], 'core', L('Server Mount.db2.', 'Mount.db2 du serveur.'), 1), check(bool(how), 'obtainable', detail, 3)]

    def pet_result(pet_id, entity):
        how, items = collection_spell(entity['spell'])
        return [check(pet_id in known['pet'], 'core', L('', ''), 1),
                check(bool(how), 'obtainable', join(how) if how else L('No obtainable item teaches this pet.', 'Aucun objet obtenable n’apprend cette mascotte.'), 3)]

    def toy_result(toy_id, entity):
        item = entity['item']
        found = sorted(sources.get(item, []))
        return [check(toy_id in known['toy'], 'core', L('', ''), 1),
                check(item in obtainable, 'obtainable', join([SOURCES[s] for s in found]) if found else L(f'Item {item} has no source.', f'L’objet {item} n’a aucune source.'), 3)]

    def achievement_result(ach_id, entity):
        checks = [check(ach_id in known['achievement'], 'core', L('Server Achievement.db2 or hotfixes.', 'Achievement.db2 du serveur ou hotfixes.'), 2)]
        if entity.get('reward') or entity.get('reward_item'):
            checks.append(check(ach_id in ctx['achievement_rewards'], 'reward', L('achievement_reward (title / item by mail).', 'achievement_reward (titre / objet par courrier).'), 2))
        return checks

    def taxi_result(node_id, entity):
        x, y = entity['pos'][0], entity['pos'][1]
        near = min((math.hypot(fx - x, fy - y) for m, fx, fy in ctx['flight_spawns'] if m == entity['map']), default=None)
        detail = L(f'{near:.0f} yd away', f'à {near:.0f} m') if near is not None and near < 5000 else L('No flight master nearby.', 'Aucun maître de vol à proximité.')
        return [check(node_id in known['taxi'], 'core', L('Server TaxiNodes.db2.', 'TaxiNodes.db2 du serveur.'), 1),
                check(near is not None and near < 60, 'flightmaster', detail, 3)]

    rules = {'item': item_result, 'spell': spell_result, 'talent': talent_result, 'quest': quest_result, 'dungeon': dungeon_result,
             'boss': boss_result, 'zone': zone_result, 'recipe': recipe_result, 'mount': mount_result, 'pet': pet_result, 'toy': toy_result,
             'achievement': achievement_result, 'taxi': taxi_result}
    for kind, rule in rules.items():
        for entity_id, entity in content.get(kind, {}).items():
            checks = rule(entity_id, entity)
            total = sum(c['w'] for c in checks)
            score = round(100 * sum(c['w'] for c in checks if c['ok']) / total) if total else 0
            status = 'ok' if score == 100 else ('missing' if score < 35 else 'partial')
            results[(kind, entity_id)] = (status, score, checks)
    ctx['available_quests'] = available_quests
    return results
