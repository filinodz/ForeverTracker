"""Writes the analysis into the tracker database (ft_* tables), swapped atomically."""
import datetime
import json
import re

from . import sqlio

INTERNAL_NAME = re.compile(r'^\s*\[|^\s*<|^\s*$|^zz|Test(?![a-z])|\btest\b|\b(OLD|TEST|Test|DEPRECATED|Deprecated|DEP|PH|DNT|QA|Unused|UNUSED|NYI|Monster|Monstre|PÉRIMÉ|NPC Equip|Template|Placeholder)\b|\(DNT\)|\(OLD\)|\bcopy\b')
# IDs above these did not exist in Classic: a practical 'new in Forever' marker per resource type.
NEW_FROM = {'item': 190000, 'quest': 60000, 'spell': 400000, 'talent': 3000, 'dungeon': 1000, 'boss': 3000, 'zone': 5000, 'recipe': 400000,
            'mount': 1000, 'toy': 0, 'pet': 3000, 'achievement': 0, 'taxi': 500}
DATA_KEYS = ('ranks', 'spell', 'item', 'map', 'pos', 'creature', 'kind', 'teach', 'creates')
TABLES = ('ft_builds', 'ft_entities', 'ft_changes', 'ft_meta', 'ft_tooltips', 'ft_fixes')

SCHEMA_SQL = """
CREATE TABLE ft_builds_new (build INT PRIMARY KEY, version VARCHAR(32), released DATE NULL, note TEXT, source VARCHAR(255), is_server TINYINT NOT NULL DEFAULT 0, counts JSON) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ft_entities_new (type VARCHAR(16) NOT NULL, id INT NOT NULL, name VARCHAR(255) NOT NULL, name_en VARCHAR(255) NOT NULL DEFAULT '',
  sub VARCHAR(120) NOT NULL DEFAULT '', level INT NOT NULL DEFAULT 0, quality TINYINT NOT NULL DEFAULT -1, icon VARCHAR(96) NOT NULL DEFAULT '',
  internal TINYINT NOT NULL DEFAULT 0, is_new TINYINT NOT NULL DEFAULT 0, status ENUM('ok','partial','missing') NOT NULL, score TINYINT NOT NULL, checks JSON, data JSON,
  first_build INT NOT NULL, changed_build INT NOT NULL, PRIMARY KEY (type, id), KEY ix_status (type, status, internal), KEY ix_name (name), KEY ix_name_en (name_en), KEY ix_changed (changed_build)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ft_changes_new (seq INT AUTO_INCREMENT PRIMARY KEY, build INT NOT NULL, prev_build INT NOT NULL, type VARCHAR(16) NOT NULL, id INT NOT NULL,
  kind ENUM('added','removed','modified') NOT NULL, name VARCHAR(255) NOT NULL DEFAULT '', fields JSON, KEY ix_build (build, type, kind), KEY ix_entity (type, id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ft_meta_new (k VARCHAR(64) PRIMARY KEY, v JSON) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ft_tooltips_new (type VARCHAR(16) NOT NULL, id INT NOT NULL, tip JSON NOT NULL, PRIMARY KEY (type, id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ft_fixes_new (type VARCHAR(16) NOT NULL, id INT NOT NULL, kind VARCHAR(16) NOT NULL, db VARCHAR(16) NOT NULL, rows_count INT NOT NULL DEFAULT 0,
  needs VARCHAR(16) NOT NULL DEFAULT '', summary JSON, detail JSON, sql_apply MEDIUMTEXT NOT NULL, sql_rollback MEDIUMTEXT NOT NULL,
  PRIMARY KEY (type, id, kind), KEY ix_kind (kind)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ft_notes (build INT PRIMARY KEY, title VARCHAR(255) NOT NULL DEFAULT '', url VARCHAR(500) NOT NULL DEFAULT '', body MEDIUMTEXT, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
"""


class Batch:
    def __init__(self, out, table, size=400):
        self.out, self.table, self.size, self.rows = out, table, size, []

    def add(self, row):
        self.rows.append(row)
        if len(self.rows) >= self.size:
            self.flush()

    def flush(self):
        if self.rows:
            self.out.write(f'INSERT INTO {self.table} VALUES ' + ',\n'.join(self.rows) + ';\n')
            self.rows = []


def dumps(value):
    return json.dumps(value, ensure_ascii=False, separators=(',', ':'), default=str)


def write_sql(path, build_list, content, changes, results, first_seen, changed_in, icons, quest_names, ctx, server_build, tooltips, fixes):
    e = sqlio.escape
    with open(path, 'w', encoding='utf-8') as out:
        out.write('SET NAMES utf8mb4;\n')
        out.write('DROP TABLE IF EXISTS ' + ', '.join(t + '_new' for t in TABLES) + ';\n' + SCHEMA_SQL)
        for b in build_list:
            out.write(f"INSERT INTO ft_builds_new VALUES ({b['build']},{e(b['version'])},{e(b['date'])},{e(b['note'])},{e(b['source'])},{1 if b['build'] == server_build else 0},{e(dumps(b.get('counts', {})))});\n")
        batch = Batch(out, 'ft_entities_new')
        for (kind, entity_id), (status, score, checks) in results.items():
            entity = content[kind][entity_id]
            name = entity.get('name') or ''
            name_en = entity.get('name_en') or ''
            data = {}
            if kind == 'quest':
                row = ctx['quests'].get(entity_id)
                wh = quest_names.get(str(entity_id), {})
                name = ctx['quest_fr'].get(entity_id) or (row or {}).get('LogTitle') or wh.get('fr') or wh.get('en') or ''
                name_en = (row or {}).get('LogTitle') or wh.get('en') or ''
                level, minimum = ctx['quest_level'].get(entity_id, (0, 0))
                entity['level'] = level
                entity['sub'] = str(int((row or {}).get('QuestSortID') or 0))
                if wh.get('text_fr') or wh.get('text_en'):
                    data['text'] = wh.get('text_fr') or wh.get('text_en')
                data['min'] = minimum
            internal = 0
            if kind == 'quest':
                # Unnamed quests stay visible (they are what the server lacks) unless Wowhead does not know them either.
                looked_up = str(entity_id) in quest_names
                unknown_everywhere = not name and looked_up and not (quest_names[str(entity_id)].get('fr') or quest_names[str(entity_id)].get('en'))
                internal = 1 if unknown_everywhere or (name and INTERNAL_NAME.search(name)) else 0
                if not name:
                    data['unnamed'] = True
                name = name or f'#{entity_id}'
            elif kind == 'spell':
                internal = 0 if entity['sub'] in ('class', 'profession', 'racial', 'talent', 'mount', 'pet', 'rune', 'petability') else 1
            elif kind == 'item':
                internal = 1 if INTERNAL_NAME.search(name_en or name) or not name else 0
            elif kind in ('achievement', 'taxi', 'zone', 'dungeon', 'boss'):
                internal = 1 if INTERNAL_NAME.search(name_en or name) or not name or entity.get('weak') else 0
            for key in DATA_KEYS:
                if entity.get(key):
                    data[key] = entity[key]
            icon = icons.get(entity.get('icon') or 0, '')
            batch.add(f"({e(kind)},{entity_id},{e(name[:250])},{e(name_en[:250])},{e(str(entity.get('sub', ''))[:120])},{int(entity.get('level') or 0)},"
                      f"{int(entity.get('quality', -1)) if kind == 'item' else -1},{e(icon)},{internal},{1 if entity_id >= NEW_FROM.get(kind, 10**9) else 0},{e(status)},{score},"
                      f"{e(dumps(checks))},{e(dumps(data))},{first_seen.get((kind, entity_id), 0)},{changed_in.get((kind, entity_id), 0)})")
        batch.flush()
        batch = Batch(out, 'ft_changes_new')
        for build, previous, kind, entity_id, change, fields, name in changes:
            batch.add(f"(NULL,{build},{previous},{e(kind)},{entity_id},{e(change)},{e((name or '')[:250])},{e(dumps(fields))})")
        batch.flush()
        batch = Batch(out, 'ft_tooltips_new', 300)
        for (kind, entity_id), tip in tooltips.items():
            if (kind, entity_id) in results:
                batch.add(f'({e(kind)},{entity_id},{e(dumps(tip))})')
        batch.flush()
        batch = Batch(out, 'ft_fixes_new', 50)
        for kind, entity_id, fix_kind, db, rows, needs, summary, detail, apply, rollback in fixes:
            batch.add(f'({e(kind)},{entity_id},{e(fix_kind)},{e(db)},{rows},{e(needs)},{e(dumps(summary))},{e(dumps(detail))},{e(apply)},{e(rollback)})')
        batch.flush()
        meta = {'generated_at': datetime.datetime.now().isoformat(timespec='seconds'), 'server_build': server_build,
                'client_build': build_list[-1]['build'] if build_list else 0, 'hidden': content.get('_meta', {}).get('hidden', {}),
                'schema': 2}
        for key, value in meta.items():
            out.write(f"INSERT INTO ft_meta_new VALUES ({e(key)},{e(dumps(value))});\n")
        # atomic swap
        for t in TABLES:
            out.write(f'CREATE TABLE IF NOT EXISTS {t} LIKE {t}_new;\n')
        out.write('RENAME TABLE ' + ', '.join(f'{t} TO {t}_old, {t}_new TO {t}' for t in TABLES) + ';\n')
        out.write('DROP TABLE ' + ', '.join(t + '_old' for t in TABLES) + ';\n')
