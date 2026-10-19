"""Generates db2-schema.json from a TrinityCore/ForeverCore source tree.

    python tools/make_db2_schema.py <core>/src/server/game/DataStores

DB2Metadata.h gives the on-disk layout (types, array sizes, index/parent
fields); DB2LoadInfo.h gives column names; DB2Stores.cpp maps files to them.
Only needed again when the core's DB2 definitions change.
"""
import json
import os
import re
import sys

FIELD_META = re.compile(r'\{\s*\.Type\s*=\s*(FT_\w+),\s*\.ArraySize\s*=\s*(\d+),\s*\.IsSigned\s*=\s*(true|false)\s*\}')
FIELD_NAME = re.compile(r'\{\s*\.IsSigned\s*=\s*(true|false),\s*\.Type\s*=\s*(FT_\w+),\s*\.Name\s*=\s*"([^"]+)"\s*\}')


def blocks(text, kind):
    """Yields (name, body) for `struct <Name><kind>` blocks."""
    for match in re.finditer(r'struct (\w+)' + kind + r'\s*\{', text):
        depth, start = 1, match.end()
        index = start
        while depth:
            if text[index] == '{':
                depth += 1
            elif text[index] == '}':
                depth -= 1
            index += 1
        yield match.group(1), text[start:index]


def main(folder):
    read = lambda name: open(os.path.join(folder, name), encoding='utf-8', errors='replace').read()
    metas = {}
    for name, body in blocks(read('DB2Metadata.h'), 'Meta'):
        fields = [{'type': t[3:].lower(), 'array': int(a), 'signed': s == 'true'} for t, a, s in FIELD_META.findall(body)]
        instance = {key: int(value, 0) for key, value in re.findall(r'\.(IndexField|ParentIndexField|FieldCount|FileFieldCount|LayoutHash)\s*=\s*(-?0x[0-9A-Fa-f]+|-?\d+)', body)}
        metas[name] = {'fields': fields, 'index': instance.get('IndexField', -1), 'parent': instance.get('ParentIndexField', -1),
                       'fieldCount': instance.get('FieldCount'), 'fileFieldCount': instance.get('FileFieldCount'),
                       'layoutHash': '%08X' % (instance.get('LayoutHash', 0) & 0xFFFFFFFF)}
    names = {name: [n for _, _, n in FIELD_NAME.findall(body)] for name, body in blocks(read('DB2LoadInfo.h'), 'LoadInfo')}
    files = dict(re.findall(r'\("(\w+)\.db2",\s*&(\w+)LoadInfo::Instance\)', read('DB2Stores.cpp')))

    schema = {}
    for file_name, info in files.items():
        meta = metas.get(info)
        if not meta or info not in names:
            continue
        schema[file_name] = {**meta, 'names': names[info]}
    # Tables the core does not load still get a layout (columns named f0, f1...).
    for name, meta in metas.items():
        if name not in schema and name not in files.values():
            schema.setdefault(name, {**meta, 'names': None})
    target = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'schema', 'db2-schema.json')
    with open(target, 'w', encoding='utf-8') as handle:
        json.dump(schema, handle, separators=(',', ':'))
    print(f'{len(schema)} tables ({sum(1 for s in schema.values() if s["names"])} with column names) -> {target}')


if __name__ == '__main__':
    main(sys.argv[1])
