"""What the server provides: its DB2 files, the hotfixes and world databases and the map files."""
import json
import os

from . import db2, sqlio
from .config import CONFIG, DATA
from .content import EFFECT_CREATE_ITEM, EFFECT_LEARN_SPELL

NPCFLAG_FLIGHTMASTER = 8192


def log(message):
    import time
    print(time.strftime('%H:%M:%S'), message, flush=True)


DBC = None  # the server's DB2 folder, checked by server_dbc()


def server_dbc():
    """The server's DB2 folder; stops the analysis with a clear message when config.ini does not lead to it."""
    global DBC
    if DBC:
        return DBC
    folder, configured = CONFIG.dbc_folder()
    if not folder:
        raise SystemExit(
            f"No DB2 file of your server was found in [server] dbc = {configured or '(empty)'}.\n"
            "Set [server] root and dbc in config.ini to the folder that contains the server's .db2 files "
            "(SpellName.db2, ItemSparse.db2...), for example root = \"C:/WoW/Server\" and dbc = \"data/dbc/enUS\".\n"
            "Without them every resource would look unknown to the core.")
    if os.path.normcase(folder) != os.path.normcase(configured or ''):
        log(f'warning: no DB2 file in [server] dbc = {configured}; using {folder} (fix config.ini)')
    DBC = folder
    return DBC


def server_db2(name):
    path = os.path.join(server_dbc(), name + '.db2')
    return list(db2.Table(path, name)) if os.path.isfile(path) else []


def server_state():
    log(f'server: DB2 of the running server ({server_dbc()})')
    for key in ('maps', 'vmaps'):
        if not (CONFIG.server_path(key) and os.path.isdir(CONFIG.server_path(key))):
            log(f'warning: [server] {key} = {CONFIG.server_path(key) or "(empty)"} does not exist: instances and zones will look unextracted')
    ctx = {}
    srv = server_db2
    ids = lambda name: {r['ID'] for r in srv(name)}
    hot = lambda name: {int(x) for x in sqlio.column(f'SELECT ID FROM {name}', 'hotfixes')} if sqlio.has_table(name, 'hotfixes') else set()
    ctx['known'] = {
        'item': ids('ItemSparse') | hot('item_sparse'), 'spell': ids('SpellName') | hot('spell_name'), 'talent': ids('Talent') | hot('talent'),
        'quest': ids('QuestV2') | hot('quest_v2'), 'dungeon': ids('Map') | hot('map'), 'boss': ids('DungeonEncounter') | hot('dungeon_encounter'),
        'zone': ids('AreaTable') | hot('area_table'), 'achievement': ids('Achievement') | hot('achievement'), 'taxi': ids('TaxiNodes') | hot('taxi_nodes'),
        'mount': ids('Mount') | hot('mount'), 'toy': ids('Toy') | hot('toy'), 'pet': ids('BattlePetSpecies') | hot('battle_pet_species'),
        'recipe': {r['Spell'] for r in srv('SkillLineAbility')} | {int(x) for x in sqlio.column('SELECT Spell FROM skill_line_ability', 'hotfixes')},
    }
    learn, creates = {}, {}
    for e in srv('SpellEffect'):
        if e['Effect'] == EFFECT_LEARN_SPELL and e['EffectTriggerSpell']:
            learn.setdefault(e['SpellID'], set()).add(e['EffectTriggerSpell'])
        if e['Effect'] == EFFECT_CREATE_ITEM and e['EffectItemType']:
            creates.setdefault(e['EffectItemType'], set()).add(e['SpellID'])
    effects = {r['ID']: r['SpellID'] for r in srv('ItemEffect')}
    for row in sqlio.query('SELECT ID, SpellID FROM item_effect', 'hotfixes'):
        effects[int(row['ID'])] = int(row['SpellID'])
    item_spells = {}
    links = srv('ItemXItemEffect') + [{'ItemID': int(r['ItemID']), 'ItemEffectID': int(r['ItemEffectID'])} for r in sqlio.query('SELECT ItemID, ItemEffectID FROM item_x_item_effect', 'hotfixes')]
    for link in links:
        if link['ItemEffectID'] in effects:
            item_spells.setdefault(link['ItemID'], set()).add(effects[link['ItemEffectID']])
    teaches = {}
    for item_id, spells in item_spells.items():
        for spell in spells:
            for target in {spell} | learn.get(spell, set()):
                teaches.setdefault(target, set()).add(item_id)
    ctx['creates'], ctx['teaches'], ctx['item_spells'] = creates, teaches, item_spells
    ctx['auto_learn'] = {r['Spell'] for r in srv('SkillLineAbility') if r['AcquireMethod'] in (1, 2)}
    ctx['spell_ids'] = {r['ID'] for r in srv('SpellName')} | {r['SpellID'] for r in srv('SpellMisc')}
    ctx['start_quest_item'] = {r['StartQuestID']: r['ID'] for r in srv('ItemSparse') if r['StartQuestID']}

    log('server: world database')
    q = sqlio.query
    ints = lambda sql, db='world': {int(x) for x in sqlio.column(sql, db) if x is not None}
    ctx['spawned_npc'] = ints('SELECT DISTINCT id FROM creature')
    ctx['spawned_go'] = ints('SELECT DISTINCT id FROM gameobject')
    npc_maps = {}
    for row in q('SELECT c.map, c.id, COUNT(*) n, ct.name, ct.ScriptName, ct.AIName FROM creature c JOIN creature_template ct ON ct.entry = c.id GROUP BY c.map, c.id'):
        npc_maps.setdefault(int(row['map']), []).append({'id': int(row['id']), 'n': int(row['n']), 'name': row['name'] or '',
                                                          'scripted': bool(row['ScriptName']) or row['AIName'] == 'SmartAI'})
    ctx['npc_maps'] = npc_maps
    ctx['go_counts'] = {int(r['map']): int(r['n']) for r in q('SELECT map, COUNT(*) n FROM gameobject GROUP BY map')}
    ctx['spawns'] = [(int(r['map']), float(r['x']), float(r['y']), int(r['id'])) for r in q('SELECT map, position_x x, position_y y, id FROM creature WHERE map IN (0,1) OR map >= 2000')]
    ctx['flight_spawns'] = [(int(r['map']), float(r['x']), float(r['y'])) for r in q(f'SELECT c.map, c.position_x x, c.position_y y FROM creature c JOIN creature_template ct ON ct.entry=c.id WHERE ct.npcflag & {NPCFLAG_FLIGHTMASTER}')]

    quests = {}
    for row in q('SELECT ID, LogTitle, QuestSortID, QuestInfoID, Flags, StartItem, RewardSpell, RewardDisplaySpell1, RewardDisplaySpell2, RewardDisplaySpell3, ' + ', '.join([f'RewardItem{i}' for i in range(1, 5)] + [f'RewardChoiceItemID{i}' for i in range(1, 7)]) + ' FROM quest_template'):
        quests[int(row['ID'])] = row
    ctx['quest_fr'] = {int(r['ID']): r['LogTitle'] for r in q("SELECT ID, LogTitle FROM quest_template_locale WHERE locale='frFR'") if r['LogTitle']}
    ctx['quest_level'] = {int(r['ID']): (int(r['QuestLevel'] or 0), int(r['MinLevel'] or 0)) for r in q('SELECT ID, QuestLevel, MinLevel FROM quest_classic_level')} if sqlio.has_table('quest_classic_level') else {}
    ctx['quests'] = quests
    pairs = lambda sql: [(int(a), int(b)) for a, b in (tuple(r.values()) for r in q(sql))]
    ctx['q_start_npc'] = pairs('SELECT id, quest FROM creature_queststarter')
    ctx['q_end_npc'] = pairs('SELECT id, quest FROM creature_questender')
    ctx['q_start_go'] = pairs('SELECT id, quest FROM gameobject_queststarter')
    ctx['q_end_go'] = pairs('SELECT id, quest FROM gameobject_questender')
    ctx['objectives'] = [(int(r['QuestID']), int(r['Type']), int(r['ObjectID']), int(r['Amount'])) for r in q('SELECT QuestID, Type, ObjectID, Amount FROM quest_objectives')]
    ctx['quest_items_npc'] = pairs('SELECT CreatureEntry, ItemId FROM creature_questitem')
    ctx['quest_items_go'] = pairs('SELECT GameObjectEntry, ItemId FROM gameobject_questitem')
    disabled = {}
    for r in q('SELECT sourceType, entry FROM disables'):
        disabled.setdefault(int(r['sourceType']), set()).add(int(r['entry']))
    ctx['disabled'] = disabled

    log('server: loot, vendors and trainers')
    loot = {}
    for name in ('creature', 'gameobject', 'item', 'reference', 'pickpocketing', 'skinning', 'fishing', 'disenchant', 'prospecting', 'milling', 'spell', 'mail'):
        # ItemType: 0 item, 1 reference to reference_loot_template (Item = its Entry), 2 currency
        rows = q(f'SELECT Entry, ItemType, Item FROM {name}_loot_template')
        loot[name] = [(int(r['Entry']), int(r['ItemType'] or 0), int(r['Item']), int(r['Item']) if int(r['ItemType'] or 0) == 1 else 0) for r in rows]
    ctx['loot'] = loot
    ctx['npc_loot'] = [(int(r['Entry']), int(r['LootID'] or 0), int(r['PickPocketLootID'] or 0), int(r['SkinLootID'] or 0))
                       for r in q('SELECT Entry, LootID, PickPocketLootID, SkinLootID FROM creature_template_difficulty WHERE DifficultyID IN (0,1)')]
    ctx['go_loot'] = [(int(r['entry']), int(r['type']), int(r['Data1'] or 0)) for r in q('SELECT entry, type, Data1 FROM gameobject_template WHERE type IN (3, 25)')]
    ctx['vendors'] = pairs('SELECT entry, item FROM npc_vendor WHERE type = 1')
    ctx['trainer_rows'] = [{k: int(v or 0) for k, v in r.items()} for r in q('SELECT TrainerId, SpellId, MoneyCost, ReqSkillLine, ReqSkillRank, ReqAbility1, ReqLevel FROM trainer_spell')]
    ctx['trainer_spells'] = [(r['TrainerId'], r['SpellId']) for r in ctx['trainer_rows']]
    ctx['trainers'] = pairs('SELECT CreatureID, TrainerID FROM creature_trainer')
    ctx['shop_items'] = ints('SELECT Param1 FROM shop_product_action WHERE Action = 1') if sqlio.has_table('shop_product_action') else set()
    ctx['create_spells'] = (ints('SELECT Spell FROM playercreateinfo_spell_custom') if sqlio.has_table('playercreateinfo_spell_custom') else set()) | \
                           (ints('SELECT spell FROM playercreateinfo_cast_spell') if sqlio.has_table('playercreateinfo_cast_spell') else set())
    ctx['learn_chain'] = pairs('SELECT entry, SpellID FROM spell_learn_spell')
    ctx['pet_training'] = (ints('SELECT SpellId FROM classic_pet_spell_training_points') if sqlio.has_table('classic_pet_spell_training_points') else set()) | \
                          ints('SELECT Spell FROM creature_template_spell')
    ctx['scripted_spells'] = {abs(int(x)) for x in sqlio.column('SELECT spell_id FROM spell_script_names')}
    ctx['achievement_rewards'] = ints('SELECT ID FROM achievement_reward')
    ctx['graveyards'] = ints('SELECT GhostZone FROM graveyard_zone')

    ctx['instance_template'] = {int(r['map']): r['script'] for r in q('SELECT map, script FROM instance_template')}
    safe_map = {int(r['ID']): int(r['MapID']) for r in q('SELECT ID, MapID FROM world_safe_locs')}
    ctx['entrances'] = {safe_map.get(int(r['PortLocID'])) for r in q('SELECT PortLocID FROM areatrigger_teleport')}
    declared = ints('SELECT ID FROM battleground_template')
    ctx['battlegrounds'] = {r['MapID'] for r in srv('BattlemasterListXMap') if r['BattlemasterListID'] in declared}
    listing = lambda key: os.listdir(CONFIG.server_path(key)) if CONFIG.server_path(key) and os.path.isdir(CONFIG.server_path(key)) else []
    tiles = {}
    for name in listing('maps'):
        if name.endswith('.map') and name[:4].isdigit():
            tiles[int(name[:4])] = tiles.get(int(name[:4]), 0) + 1
    ctx['map_tiles'] = tiles
    ctx['mmaps'] = {int(n[:4]) for n in listing('mmaps') if n.endswith('.mmap') and n[:4].isdigit()}
    ctx['vmaps'] = {int(n) for n in listing('vmaps') if n.isdigit()}
    world_map = os.path.join(DATA, 'world-map.json')
    try:
        ctx['ui_maps'] = json.load(open(world_map, encoding='utf-8'))['maps']
    except (OSError, ValueError, KeyError):
        ctx['ui_maps'] = []
    return ctx
