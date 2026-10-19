"""Official content of one client build, read from its DB2 snapshot, and build-to-build diffs."""
import hashlib
import json
import os

from . import db2
from .config import BUILDS

# Tables compared build after build (the changelog).
DIFF_TABLES = ['ItemSparse', 'Item', 'ItemEffect', 'ItemXItemEffect', 'SpellName', 'Spell', 'SpellMisc', 'SpellLevels', 'SpellEffect',
               'SkillLine', 'SkillLineAbility', 'Talent', 'TalentTab', 'ChrClasses', 'QuestV2', 'Map', 'MapDifficulty',
               'DungeonEncounter', 'AreaTable', 'Mount', 'Toy', 'BattlePetSpecies', 'Achievement', 'TaxiNodes', 'Creature']
# Extra tables of the latest build used by the tooltips.
TOOLTIP_TABLES = ['ItemSubClass', 'ItemSet', 'ItemSetSpell', 'ItemNameDescription', 'RandPropPoints', 'ItemArmorQuality', 'ItemArmorTotal',
                  'ArmorLocation', 'ItemArmorShield', 'ItemDamageAmmo', 'ItemDamageOneHand', 'ItemDamageOneHandCaster', 'ItemDamageTwoHand',
                  'ItemDamageTwoHandCaster', 'SpellDuration', 'SpellRange', 'SpellCastTimes', 'SpellRadius', 'SpellCooldowns', 'SpellPower',
                  'SpellAuraOptions', 'SpellReagents', 'SpellTotems', 'SpellTargetRestrictions', 'ChrRaces', 'Faction']
# Extra tables of the latest build copied by the Forge into the hotfixes database.
FORGE_TABLES = ['ItemSearchName', 'ItemModifiedAppearance', 'ItemAppearance', 'SpellAuraRestrictions', 'SpellCastingRequirements',
                'SpellCategories', 'SpellClassOptions', 'SpellEquippedItems', 'SpellEmpower', 'SpellInterrupts', 'SpellLabel', 'SpellLearnSpell',
                'SpellReagentsCurrency', 'SpellScaling', 'SpellShapeshift', 'SpellXSpellVisual', 'SpellCategory', 'MountXDisplay',
                'CriteriaTree', 'Criteria']
TABLES = DIFF_TABLES + TOOLTIP_TABLES + FORGE_TABLES

PROFESSION_CATEGORIES = {9, 11}       # SkillLine.CategoryID: secondary, primary professions
CLASS_CATEGORY, RACIAL_CATEGORY = 7, 12
SCRIPT_EFFECTS, SCRIPT_AURAS = {3, 77}, {4, 226}
EFFECT_CREATE_ITEM, EFFECT_LEARN_SPELL = 24, 36


def builds():
    found = []
    for name in os.listdir(BUILDS) if os.path.isdir(BUILDS) else []:
        if name.isdigit() and any(os.path.isdir(os.path.join(BUILDS, name, locale)) for locale in ('frFR', 'enUS')):
            meta = os.path.join(BUILDS, name, 'build.json')
            info = json.load(open(meta, encoding='utf-8-sig')) if os.path.isfile(meta) else {}
            found.append({'build': int(name), 'version': info.get('version', f'1.60.1.{name}'), 'date': info.get('date'),
                          'note': info.get('note', ''), 'source': info.get('source', ''), 'dir': os.path.join(BUILDS, name)})
    return sorted(found, key=lambda b: b['build'])


_cache = {}


def table(folder, name, locale='frFR'):
    """Rows of a snapshot table in a locale, or in the other one when the build only has one; [] when absent."""
    key = (folder, name, locale)
    if key not in _cache:
        path = os.path.join(folder, locale, name + '.db2')
        if not os.path.isfile(path):
            path = os.path.join(folder, 'enUS' if locale == 'frFR' else 'frFR', name + '.db2')
        _cache[key] = list(db2.Table(path, name)) if os.path.isfile(path) else []
    return _cache[key]


def db2_path(folder, name):
    for locale in ('frFR', 'enUS'):
        path = os.path.join(folder, locale, name + '.db2')
        if os.path.isfile(path):
            return path
    return os.path.join(folder, 'frFR', name + '.db2')


def table_hash(folder, name):
    path = db2_path(folder, name)
    return db2.Table(path, name).table_hash if os.path.isfile(path) else 0


def official(folder):
    """{type: {id: entity}} with a stable 'facts' dict used for diffs."""
    t = lambda name: table(folder, name)
    en = lambda name: {r['ID']: r for r in table(folder, name, 'enUS')}
    out = {}

    icons_spell = {r['SpellID']: r['SpellIconFileDataID'] for r in t('SpellMisc') if r['DifficultyID'] == 0}
    spell_names = {r['ID']: r['Name'] for r in t('SpellName')}
    spell_names_en = {i: r['Name'] for i, r in en('SpellName').items()}
    spell_text = {r['ID']: r for r in t('Spell')}
    spell_levels = {r['SpellID']: r for r in t('SpellLevels') if r['DifficultyID'] == 0}
    effects = {}
    for r in t('SpellEffect'):
        if r['DifficultyID'] == 0:
            effects.setdefault(r['SpellID'], []).append(r)

    # ---- items
    items_base = {r['ID']: r for r in t('Item')}
    names_en = {i: r['Display'] for i, r in en('ItemSparse').items()}
    items = {}
    for r in t('ItemSparse'):
        base = items_base.get(r['ID'], {})
        stats = [(r[f'StatModifierBonusStat{i}'], r[f'StatPercentEditor{i}']) for i in range(1, 11) if r[f'StatModifierBonusStat{i}'] not in (-1, 0) or r[f'StatPercentEditor{i}']]
        items[r['ID']] = {'name': r['Display'], 'name_en': names_en.get(r['ID'], ''), 'icon': base.get('IconFileDataID', 0),
                          'quality': r['OverallQualityID'], 'level': r['ItemLevel'], 'req': r['RequiredLevel'],
                          'sub': f"{base.get('ClassID', -1)}:{base.get('SubclassID', -1)}", 'slot': r['InventoryType'],
                          'facts': {'name': r['Display'], 'quality': r['OverallQualityID'], 'ilvl': r['ItemLevel'], 'req': r['RequiredLevel'],
                                    'slot': r['InventoryType'], 'bonding': r['Bonding'], 'set': r['ItemSet'], 'stats': stats,
                                    'sell': r['SellPrice'], 'buy': r['BuyPrice'], 'classes': r['AllowableClass'], 'desc': r['Description'],
                                    'startquest': r['StartQuestID'], 'skill': [r['RequiredSkill'], r['RequiredSkillRank']]}}
    out['item'] = items

    # ---- skill lines & abilities (class spells, professions, recipes)
    skill_lines = {r['ID']: r for r in t('SkillLine')}
    skill_lines_en = en('SkillLine')
    abilities = t('SkillLineAbility')
    class_names = {r['ID']: r['Name'] for r in t('ChrClasses')}
    spell_category = {}
    recipes = {}
    creates = {}
    for spell_id, rows in effects.items():
        for e in rows:
            if e['Effect'] == EFFECT_CREATE_ITEM and e['EffectItemType']:
                creates[spell_id] = e['EffectItemType']
    for a in abilities:
        line = skill_lines.get(a['SkillLine'], {})
        category = line.get('CategoryID')
        line_en = skill_lines_en.get(a['SkillLine'], {}).get('DisplayName', '')
        if line_en == 'Beast Training' or line_en.startswith('Pet -'):
            spell_category.setdefault(a['Spell'], 'petability')  # hunter pet abilities: learned by taming and pet training
        elif category == CLASS_CATEGORY:
            spell_category.setdefault(a['Spell'], 'class')
        elif category == RACIAL_CATEGORY:
            spell_category.setdefault(a['Spell'], 'racial')
        elif category in PROFESSION_CATEGORIES:
            spell_category.setdefault(a['Spell'], 'profession')
            recipes[a['Spell']] = {'name': spell_names.get(a['Spell'], f"#{a['Spell']}"), 'name_en': spell_names_en.get(a['Spell'], ''),
                                   'icon': icons_spell.get(a['Spell'], 0), 'sub': line.get('DisplayName', ''), 'level': a['MinSkillLineRank'],
                                   'auto': a['AcquireMethod'] in (1, 2), 'spell': a['Spell'], 'creates': creates.get(a['Spell'], 0),
                                   'facts': {'skill': a['SkillLine'], 'rank': a['MinSkillLineRank'], 'acquire': a['AcquireMethod'],
                                             'trivial': [a['TrivialSkillLineRankLow'], a['TrivialSkillLineRankHigh']]}}
    out['recipe'] = recipes

    # ---- talents
    tabs = {r['ID']: r for r in t('TalentTab')}
    talents = {}
    for r in t('Talent'):
        ranks = [r[f'SpellRank{i}'] for i in range(1, 10) if r[f'SpellRank{i}']]
        for spell in ranks:
            spell_category[spell] = 'talent'
        first = ranks[0] if ranks else r['SpellID']
        tab = tabs.get(r['TabID'], {})
        class_id = next((c for c in class_names if tab.get('ClassMask', 0) & (1 << (c - 1))), r['ClassID'])
        talents[r['ID']] = {'name': spell_names.get(first, f"#{r['ID']}"), 'name_en': spell_names_en.get(first, ''), 'icon': icons_spell.get(first, 0),
                            'sub': f"{class_names.get(class_id, '?')} · {tab.get('Name', '?')}", 'level': r['TierID'] * 5 + 10, 'ranks': ranks,
                            'facts': {'tab': r['TabID'], 'tier': r['TierID'], 'column': r['ColumnIndex'], 'ranks': ranks,
                                      'prereq': [r['PrereqTalent1'], r['PrereqRank1']],
                                      'desc': [spell_text.get(s, {}).get('Description', '') for s in ranks[:1]]}}
    out['talent'] = talents

    # ---- mounts, toys, pets (collections)
    item_effects = {r['ID']: r for r in t('ItemEffect')}
    item_spells = {}
    teaches = {}  # spell -> items that teach it (official client view)
    learn = {}
    for spell_id, rows in effects.items():
        for e in rows:
            if e['Effect'] == EFFECT_LEARN_SPELL and e['EffectTriggerSpell']:
                learn.setdefault(spell_id, set()).add(e['EffectTriggerSpell'])
    for link in t('ItemXItemEffect'):
        effect = item_effects.get(link['ItemEffectID'])
        if effect:
            item_spells.setdefault(link['ItemID'], set()).add(effect['SpellID'])
            spell_category.setdefault(effect['SpellID'], 'item')
            for target in {effect['SpellID']} | learn.get(effect['SpellID'], set()):
                teaches.setdefault(target, set()).add(link['ItemID'])
    mounts = {}
    mounts_en = en('Mount')
    for r in t('Mount'):
        spell_category[r['SourceSpellID']] = 'mount'
        mounts[r['ID']] = {'name': r['Name'], 'name_en': mounts_en.get(r['ID'], {}).get('Name', ''), 'icon': icons_spell.get(r['SourceSpellID'], 0),
                           'sub': 'Monture', 'spell': r['SourceSpellID'], 'teach': sorted(teaches.get(r['SourceSpellID'], set())),
                           'source': r['SourceText'], 'source_en': mounts_en.get(r['ID'], {}).get('SourceText', ''),
                           'facts': {'name': r['Name'], 'spell': r['SourceSpellID'], 'source': r['SourceText']}}
    toys = {}
    toys_en = en('Toy')
    for r in t('Toy'):
        item = items.get(r['ItemID'], {})
        toys[r['ID']] = {'name': item.get('name', f"#{r['ItemID']}"), 'name_en': item.get('name_en', ''), 'icon': item.get('icon', 0), 'sub': 'Jouet',
                         'item': r['ItemID'], 'source': r['SourceText'], 'source_en': toys_en.get(r['ID'], {}).get('SourceText', ''),
                         'facts': {'item': r['ItemID'], 'source': r['SourceText']}}
    pets = {}
    pets_en = en('BattlePetSpecies')
    creatures = {r['ID']: r for r in t('Creature')}
    for r in t('BattlePetSpecies'):
        spell_category[r['SummonSpellID']] = 'pet'  # companion skill lines sit in the class category
        name = creatures.get(r['CreatureID'], {}).get('Name') or spell_names.get(r['SummonSpellID'], f"#{r['ID']}")
        pets[r['ID']] = {'name': name, 'name_en': spell_names_en.get(r['SummonSpellID'], ''), 'icon': r['IconFileDataID'], 'sub': 'Mascotte',
                         'spell': r['SummonSpellID'], 'creature': r['CreatureID'], 'teach': sorted(teaches.get(r['SummonSpellID'], set())),
                         'source': r['SourceText'], 'source_en': pets_en.get(r['ID'], {}).get('SourceText', ''),
                         'facts': {'creature': r['CreatureID'], 'spell': r['SummonSpellID'], 'source': r['SourceText']}}
    out['mount'], out['toy'], out['pet'] = mounts, toys, pets
    for recipe_id, recipe in recipes.items():
        if teaches.get(recipe_id):
            recipe['teach'] = sorted(teaches[recipe_id])

    # ---- spells (all; player-relevant ones are flagged by category)
    spells = {}
    for spell_id, name in spell_names.items():
        text = spell_text.get(spell_id, {})
        level = spell_levels.get(spell_id, {})
        effect_facts = sorted([(e['EffectIndex'], e['Effect'], e['EffectAura'], e['EffectBasePoints'], e['EffectTriggerSpell']) for e in effects.get(spell_id, [])])
        category = spell_category.get(spell_id, 'other')
        if category == 'class' and spell_names_en.get(spell_id, '').startswith('Engrave'):
            category = 'rune'  # rune engravings sit in class skill lines but are learned from runes
        spells[spell_id] = {'name': name, 'name_en': spell_names_en.get(spell_id, ''), 'icon': icons_spell.get(spell_id, 0),
                            'sub': category, 'level': level.get('BaseLevel', 0) or level.get('SpellLevel', 0),
                            'scriptable': any(e['Effect'] in SCRIPT_EFFECTS or e['EffectAura'] in SCRIPT_AURAS for e in effects.get(spell_id, [])),
                            'teach': sorted(teaches.get(spell_id, ())) if category in ('class', 'profession', 'rune') else [],
                            'facts': {'name': name, 'rank': text.get('NameSubtext', ''), 'desc': text.get('Description', ''),
                                      'aura': text.get('AuraDescription', ''), 'effects': effect_facts, 'level': level.get('BaseLevel', 0)}}
    out['spell'] = spells

    # ---- quests (ids only in the client; names come from the server or Wowhead)
    out['quest'] = {r['ID']: {'facts': {}} for r in t('QuestV2')}

    # ---- instances, bosses, zones
    maps = {r['ID']: r for r in t('Map')}
    maps_en = en('Map')
    difficulty_players = {}
    for r in t('MapDifficulty'):
        difficulty_players[r['MapID']] = max(difficulty_players.get(r['MapID'], 0), r['MaxPlayers'])
    kinds = {1: 'Donjon', 2: 'Raid', 3: 'Champ de bataille', 4: 'Arène'}
    dungeons = {}
    for map_id, r in maps.items():
        if r['InstanceType'] in kinds:
            dungeons[map_id] = {'name': r['MapName'], 'name_en': maps_en.get(map_id, {}).get('MapName', ''), 'sub': kinds[r['InstanceType']],
                                'level': difficulty_players.get(map_id, r['MaxPlayers']), 'kind': r['InstanceType'], 'directory': r['Directory'],
                                'parent': r['CorpseMapID'] if r['CorpseMapID'] >= 0 else max(r['ParentMapID'], 0),
                                'facts': {'name': r['MapName'], 'type': r['InstanceType'], 'players': difficulty_players.get(map_id, r['MaxPlayers']),
                                          'area': r['AreaTableID'], 'parent': r['ParentMapID']}}
    out['dungeon'] = dungeons
    encounters_en = en('DungeonEncounter')
    bosses = {}
    for r in t('DungeonEncounter'):
        bosses[r['ID']] = {'name': r['Name'], 'name_en': encounters_en.get(r['ID'], {}).get('Name', ''), 'map': r['MapID'],
                           'sub': maps.get(r['MapID'], {}).get('MapName', f"#{r['MapID']}"), 'level': r['OrderIndex'],
                           'facts': {'name': r['Name'], 'map': r['MapID'], 'order': r['OrderIndex'], 'difficulty': r['DifficultyID']}}
    out['boss'] = bosses
    areas_en = en('AreaTable')
    zones = {}
    for r in t('AreaTable'):
        continent = maps.get(r['ContinentID'], {})
        if r['ParentAreaID'] == 0 and continent and continent.get('InstanceType') == 0 and r['AreaName']:
            zones[r['ID']] = {'name': r['AreaName'], 'name_en': areas_en.get(r['ID'], {}).get('AreaName', ''), 'map': r['ContinentID'],
                              'sub': continent.get('MapName', ''), 'level': r['ExplorationLevel'],
                              'facts': {'name': r['AreaName'], 'map': r['ContinentID'], 'flags': r['Flags1'], 'faction': r['FactionGroupMask']}}
    out['zone'] = zones

    # ---- achievements, flight points
    achievements_en = en('Achievement')
    out['achievement'] = {r['ID']: {'name': r['Title'], 'name_en': achievements_en.get(r['ID'], {}).get('Title', ''), 'icon': r['IconFileID'],
                                    'sub': f"{r['Points']} pts", 'level': r['Points'], 'reward': r['Reward'], 'reward_item': r['RewardItemID'],
                                    'facts': {'title': r['Title'], 'desc': r['Description'], 'points': r['Points'], 'reward': r['Reward'],
                                              'category': r['Category'], 'item': r['RewardItemID']}} for r in t('Achievement')}
    taxi_en = en('TaxiNodes')
    out['taxi'] = {r['ID']: {'name': r['Name'], 'name_en': taxi_en.get(r['ID'], {}).get('Name', ''), 'map': r['ContinentID'],
                             'sub': maps.get(r['ContinentID'], {}).get('MapName', ''), 'pos': [round(r['PosX'], 1), round(r['PosY'], 1), round(r['PosZ'], 1)],
                             'facts': {'name': r['Name'], 'map': r['ContinentID'], 'pos': [round(r['PosX']), round(r['PosY'])], 'flags': r['Flags']}}
                   for r in t('TaxiNodes') if r['Name']}

    out['_meta'] = {'item_spells': item_spells, 'hidden': {}}
    for name in ('ItemSparse', 'SpellName', 'QuestV2', 'Map', 'Achievement', 'Mount'):
        path = db2_path(folder, name)
        if os.path.isfile(path):
            tb = db2.Table(path, name)
            list(tb)
            out['_meta']['hidden'][name] = tb.hidden_records
    return out


def fingerprint(facts):
    return hashlib.sha1(json.dumps(facts, sort_keys=True, ensure_ascii=False, default=str).encode()).hexdigest()[:16]


def diff_builds(previous, current):
    """[(type, id, kind, changed_fields)]"""
    changes = []
    for kind in current:
        if kind.startswith('_'):
            continue
        old, new = previous.get(kind, {}), current[kind]
        for entity_id in new.keys() - old.keys():
            changes.append((kind, entity_id, 'added', {}))
        for entity_id in old.keys() - new.keys():
            changes.append((kind, entity_id, 'removed', {}))
        for entity_id in new.keys() & old.keys():
            before, after = old[entity_id]['facts'], new[entity_id]['facts']
            if fingerprint(before) != fingerprint(after):
                fields = {key: [before.get(key), after.get(key)] for key in set(before) | set(after) if before.get(key) != after.get(key)}
                changes.append((kind, entity_id, 'modified', fields))
    return changes
