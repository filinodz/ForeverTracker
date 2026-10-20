"""The locator: finds the creatures and world objects the server knows but never spawned although its quests,
vendors, trainers, loot or dungeons need them, asks Wowhead where they stand in the official game and turns
those map percentages into world positions ready to spawn (table ft_spawns of the tracker database).

    python tools/tracker.py locate [--refresh] [--limit N] [--offline]

Every candidate gets one of these states:
    ready     world positions are known (one per spawn), each with a height hint from the nearest existing spawn
    manual    it belongs inside a dungeon or raid, where Wowhead gives no position: place it in game
    unknown   Wowhead has no position for it in the Forever branch (often content Blizzard does not use)
    pending   not asked yet (--limit reached, --offline, or Wowhead could not be reached)

Wowhead answers are cached in data/wowhead, so a second run only asks for the new candidates.
"""
import datetime
import json
import math
import os
import re

from . import sqlio
from .config import DATA
from .content import builds, table
from .geo import Geo
from .server import log
from .wowhead import Wowhead

# Lower is more urgent: what blocks quests first.
PRIORITY = {'starter': 1, 'ender': 1, 'objective': 2, 'boss': 2, 'vendor': 3, 'trainer': 3, 'loot': 4}
SERVICE = {'starter', 'ender', 'vendor', 'trainer', 'boss'}  # they stand still (no wandering)
MAX_POINTS = 30
SCHEMA = """CREATE TABLE IF NOT EXISTS ft_spawns (kind VARCHAR(4) NOT NULL, entry INT NOT NULL, name VARCHAR(255) NOT NULL DEFAULT '',
  priority TINYINT NOT NULL DEFAULT 9, state VARCHAR(12) NOT NULL, map INT NULL, zone INT NULL, points JSON, reasons JSON,
  wander FLOAT NOT NULL DEFAULT 0, note VARCHAR(255) NOT NULL DEFAULT '', updated_at DATETIME NOT NULL,
  PRIMARY KEY (kind, entry), KEY ix_state (state, priority)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"""


def ints(sql, db='world'):
    return [int(x) for x in sqlio.column(sql, db) if x is not None]


def failing(checks, code):
    return any(c.get('code') == code and not c.get('ok') for c in checks)


def in_list(values):
    return ','.join(str(int(v)) for v in values) or '0'


def normal(name):
    return re.sub(r'[^a-z0-9]', '', (name or '').lower())


def candidates():
    """{(kind, entry): {'name', 'reasons': [[role, type, id], ...]}} of templates that exist and are not spawned."""
    found = {}

    def add(kind, entry, role, ref_type, ref_id):
        item = found.setdefault((kind, int(entry)), {'name': '', 'reasons': []})
        if len(item['reasons']) < 12 and [role, ref_type, int(ref_id)] not in item['reasons']:
            item['reasons'].append([role, ref_type, int(ref_id)])

    entities = {}
    for row in sqlio.query("SELECT type, id, name, name_en, checks, data FROM ft_entities WHERE internal = 0 AND status <> 'ok' AND type IN ('quest', 'item', 'spell', 'recipe', 'boss')", 'tracker'):
        entities.setdefault(row['type'], []).append((int(row['id']), row, json.loads(row['checks'] or '[]')))

    # ---- quests: givers, enders and objectives nobody spawned
    quests = {qid: checks for qid, _row, checks in entities.get('quest', [])}
    starter_q = [q for q, c in quests.items() if failing(c, 'starter')]
    ender_q = [q for q, c in quests.items() if failing(c, 'ender')]
    objective_q = [q for q, c in quests.items() if failing(c, 'objectives')]
    unspawned_npc = "NOT EXISTS (SELECT 1 FROM creature c WHERE c.id = {col}) AND EXISTS (SELECT 1 FROM creature_template t WHERE t.entry = {col})"
    unspawned_go = "NOT EXISTS (SELECT 1 FROM gameobject g WHERE g.id = {col}) AND EXISTS (SELECT 1 FROM gameobject_template t WHERE t.entry = {col})"
    for table_name, role, quest_list, kind, cond in (('creature_queststarter', 'starter', starter_q, 'npc', unspawned_npc), ('gameobject_queststarter', 'starter', starter_q, 'go', unspawned_go),
                                                   ('creature_questender', 'ender', ender_q, 'npc', unspawned_npc), ('gameobject_questender', 'ender', ender_q, 'go', unspawned_go)):
        if quest_list:
            for r in sqlio.query(f"SELECT s.id, s.quest FROM {table_name} s WHERE s.quest IN ({in_list(quest_list)}) AND {cond.format(col='s.id')}"):
                add(kind, r['id'], role, 'quest', r['quest'])
    if objective_q:
        for r in sqlio.query(f"SELECT o.ObjectID id, o.QuestID quest, o.Type type FROM quest_objectives o WHERE o.QuestID IN ({in_list(objective_q)}) AND "
                             f"((o.Type = 0 AND {unspawned_npc.format(col='o.ObjectID')}) OR (o.Type = 2 AND {unspawned_go.format(col='o.ObjectID')}))"):
            add('npc' if r['type'] == '0' else 'go', r['id'], 'objective', 'quest', r['quest'])

    # ---- items: vendors and creatures that would make them obtainable
    items = [iid for iid, _row, checks in entities.get('item', []) if failing(checks, 'source')]
    for chunk in range(0, len(items), 2000):
        part = in_list(items[chunk:chunk + 2000])
        for r in sqlio.query(f"SELECT v.entry, MIN(v.item) item FROM npc_vendor v WHERE v.item IN ({part}) AND {unspawned_npc.format(col='v.entry')} GROUP BY v.entry"):
            add('npc', r['entry'], 'vendor', 'item', r['item'])
        for r in sqlio.query(f"SELECT d.Entry entry, MIN(l.Item) item FROM creature_loot_template l JOIN creature_template_difficulty d ON d.LootID = l.Entry AND d.DifficultyID IN (0, 1) "
                             f"WHERE l.ItemType = 0 AND l.Item IN ({part}) AND {unspawned_npc.format(col='d.Entry')} GROUP BY d.Entry"):
            add('npc', r['entry'], 'loot', 'item', r['item'])

    # ---- spells: trainers that teach them but stand nowhere
    spells = [(t, sid) for t in ('spell', 'recipe') for sid, _row, checks in entities.get(t, []) if failing(checks, 'learn')]
    if spells:
        kinds = {sid: t for t, sid in spells}
        for r in sqlio.query(f"SELECT ct.CreatureID entry, MIN(ts.SpellId) spell FROM trainer_spell ts JOIN creature_trainer ct ON ct.TrainerID = ts.TrainerId "
                             f"WHERE ts.SpellId IN ({in_list(kinds)}) AND {unspawned_npc.format(col='ct.CreatureID')} GROUP BY ct.CreatureID"):
            add('npc', r['entry'], 'trainer', kinds.get(int(r['spell']), 'spell'), r['spell'])

    # ---- bosses: a creature of that name that is not spawned
    bosses = [(bid, row) for bid, row, checks in entities.get('boss', []) if failing(checks, 'boss_spawn')]
    if bosses:
        names = {}
        for bid, row in bosses:
            for name in {row['name_en'], row['name']} - {'', None}:
                names.setdefault(normal(name), []).append((bid, json.loads(row['data'] or '{}').get('map')))
        quoted = ','.join(sqlio.escape(n) for n in {row['name_en'] or row['name'] for _bid, row in bosses})
        for r in sqlio.query(f"SELECT t.entry, t.name FROM creature_template t WHERE t.name IN ({quoted}) AND NOT EXISTS (SELECT 1 FROM creature c WHERE c.id = t.entry)"):
            for bid, map_id in names.get(normal(r['name']), []):
                add('npc', r['entry'], 'boss', 'boss', bid)
                found[('npc', int(r['entry']))]['map'] = map_id

    # names
    for kind, table_name in (('npc', 'creature_template'), ('go', 'gameobject_template')):
        ids = [e for k, e in found if k == kind]
        for chunk in range(0, len(ids), 3000):
            for r in sqlio.query(f"SELECT entry, name FROM {table_name} WHERE entry IN ({in_list(ids[chunk:chunk + 3000])})"):
                found[(kind, int(r['entry']))]['name'] = r['name'] or ''
    return found


class Neighbours:
    """Existing spawns by map and 32-yard cell: height hints and facing for new spawns."""
    CELL = 32.0

    def __init__(self):
        self.cells = {}
        for table_name in ('creature', 'gameobject'):
            for r in sqlio.query(f'SELECT map, position_x x, position_y y, position_z z FROM {table_name}'):
                m, x, y = int(r['map']), float(r['x']), float(r['y'])
                self.cells.setdefault((m, int(x // self.CELL), int(y // self.CELL)), []).append((x, y, float(r['z'])))

    def near(self, map_id, x, y, radius):
        cx, cy, reach = int(x // self.CELL), int(y // self.CELL), int(radius // self.CELL) + 1
        out = []
        for i in range(cx - reach, cx + reach + 1):
            for j in range(cy - reach, cy + reach + 1):
                for px, py, pz in self.cells.get((map_id, i, j), ()):
                    d = math.hypot(px - x, py - y)
                    if d <= radius:
                        out.append((d, px, py, pz))
        return sorted(out)

    def place(self, map_id, x, y):
        """[x, y, z hint or None, hint distance or None, orientation]: the closest spawn gives the height,
        the spawns around give the facing (towards them, as townsfolk face their town)."""
        around = self.near(map_id, x, y, 30.0)
        hint = [s for s in around if s[0] <= 20.0]
        z = round(hint[0][3], 2) if hint else None
        dist = round(hint[0][0], 1) if hint else None
        if around:
            mx = sum(s[1] for s in around) / len(around) - x
            my = sum(s[2] for s in around) / len(around) - y
            orientation = round(math.atan2(my, mx) % (2 * math.pi), 3) if abs(mx) + abs(my) > 1 else 0.0
        else:
            orientation = 0.0
        return [round(x, 2), round(y, 2), z, dist, orientation]


def cluster(points, radius):
    """Groups points closer than radius; returns the medoid of each group (a real point, not an average)."""
    groups = []
    for p in points:
        for g in groups:
            if any(math.hypot(p[0] - q[0], p[1] - q[1]) <= radius for q in g):
                g.append(p)
                break
        else:
            groups.append([p])
    out = []
    for g in groups:
        out.append(min(g, key=lambda p: sum(math.hypot(p[0] - q[0], p[1] - q[1]) for q in g)))
    return out


def run(args):
    started = datetime.datetime.now()
    sqlio.query(SCHEMA, 'tracker')
    build_list = builds()
    if not build_list:
        raise SystemExit('No snapshot in data/builds. Run the snapshot command first.')
    folder = build_list[-1]['dir']
    if not table(folder, 'UiMapAssignment'):
        raise SystemExit(f'The snapshot {folder} has no UiMap tables: run the snapshot command again for this build '
                         '(the locator needs UiMap, UiMapAssignment and UiMapGroupMember).')
    geo = Geo(folder)
    log('locator: looking for unspawned creatures and objects that the content needs')
    found = candidates()
    log(f"locator: {len(found)} candidates ({sum(1 for k, _ in found if k == 'npc')} creatures, {sum(1 for k, _ in found if k == 'go')} objects)")
    neighbours = Neighbours()
    wowhead = Wowhead(refresh=args.refresh)
    instance_maps = {m['ID'] for m in geo.maps.values() if m.get('InstanceType') in (1, 2)}
    order = sorted(found, key=lambda k: (min(PRIORITY[r[0]] for r in found[k]['reasons']), k[1]))
    rows, asked, counts = [], 0, {}
    for index, key in enumerate(order):
        kind, entry = key
        info = found[key]
        roles = {r[0] for r in info['reasons']}
        priority = min(PRIORITY[r] for r in roles)
        # only quest objectives come in packs (kill ten of them); anything else is one creature per place: Wowhead's
        # several points around it are its wandering, not more copies
        pack = 'objective' in roles
        service = roles <= SERVICE
        state, map_id, zone, points, note = 'pending', info.get('map'), None, [], ''
        data = wowhead.cached('npc' if kind == 'npc' else 'object', entry)
        if data is None and not args.offline and (not args.limit or asked < args.limit):
            data = wowhead.get('npc' if kind == 'npc' else 'object', entry)
            asked += 1
            if asked % 50 == 0:
                log(f'locator: {asked} asked to Wowhead ({index + 1}/{len(order)})')
        if data is not None:
            where = data.get('map') or {}
            zone = where.get('zone')
            world = []
            for floor, coords in (where.get('coords') or {}).items():
                for px, py in coords:
                    w = geo.wowhead(zone, int(floor), px, py)
                    if w:
                        world.append(w)
            if world:
                by_map = {}
                for m, x, y, _ui in world:
                    by_map.setdefault(m, []).append((x, y))
                map_id = max(by_map, key=lambda m: len(by_map[m]))  # one map per entry: the one with most points
                spots = cluster(by_map[map_id], 6.0 if pack else 60.0)[:MAX_POINTS]
                points = [neighbours.place(map_id, x, y) for x, y in spots]
                state = 'ready'
            elif data.get('missing'):
                state, note = 'unknown', 'not on Wowhead (Forever)'
            else:
                if zone and map_id is None:
                    map_id = geo.map_by_area.get(zone)
                if map_id in instance_maps or 'boss' in roles:
                    state, note = 'manual', 'inside an instance: no position on Wowhead'
                else:
                    state, note = 'unknown', 'no position on Wowhead'
        elif 'boss' in roles and map_id is not None:
            state, note = 'manual', 'inside an instance'
        counts[state] = counts.get(state, 0) + 1
        wander = 0.0 if service else 5.0
        rows.append(f"({sqlio.escape(kind)},{entry},{sqlio.escape(info['name'][:250])},{priority},{sqlio.escape(state)},{'NULL' if map_id is None else int(map_id)},"
                    f"{'NULL' if zone is None else int(zone)},{sqlio.escape(json.dumps(points))},{sqlio.escape(json.dumps(info['reasons']))},{wander},{sqlio.escape(note)},NOW())")
    path = os.path.join(DATA, 'spawns.sql')
    with open(path, 'w', encoding='utf-8') as out:
        out.write('SET NAMES utf8mb4;\nDROP TABLE IF EXISTS ft_spawns_new;\n' + SCHEMA.replace('IF NOT EXISTS ft_spawns', 'ft_spawns_new') + ';\n')
        for i in range(0, len(rows), 300):
            out.write('INSERT INTO ft_spawns_new VALUES ' + ',\n'.join(rows[i:i + 300]) + ';\n')
        out.write('RENAME TABLE ft_spawns TO ft_spawns_old, ft_spawns_new TO ft_spawns;\nDROP TABLE ft_spawns_old;\n')
    sqlio.execute_file(path, 'tracker')
    os.unlink(path)
    log(f"locator: {', '.join(f'{k}={v}' for k, v in sorted(counts.items()))} · {wowhead.requests} Wowhead request(s) · "
        f"done in {(datetime.datetime.now() - started).seconds} s")
