"""Schema-aware WDC5 (DB2) reader for the Forever Tracker build tools.

    from ft.db2 import Table
    for row in Table('path/ItemSparse.db2'): row['ID'], row['Display']

Column names and types come from db2-schema.json (generated from the core's
DB2Metadata.h / DB2LoadInfo.h). Handles compact (bit-packed, pallet, common
data) and sparse (offset-map, inline strings) tables, id lists, copy tables
and relationship (parent) columns. Encrypted sections are skipped.
"""
import json
import os
import struct

from .config import SCHEMA as SCHEMA_PATH

SCHEMA = json.load(open(SCHEMA_PATH, encoding='utf-8'))
DECRYPTED = 0x5452494E49545900  # b'TRINITY\\0'
SIZES = {'byte': 1, 'short': 2, 'int': 4, 'float': 4, 'long': 8, 'string': 4, 'string_not_localized': 4}

# Layout names for tables the core does not load (from WoWDBDefs).
EXTRA_NAMES = {
    'Spell': ['ID', 'NameSubtext', 'Description', 'AuraDescription'],
    'TalentTab': ['ID', 'Name', 'BackgroundFile', 'Description', 'OrderIndex', 'RaceMask', 'ClassMask', 'CategoryEnumID',
                  'SpellIconID', 'RoleMask', 'MasterySpellID1', 'MasterySpellID2'],
    'Creature': ['ID', 'Name', 'NameAlt', 'Title', 'TitleAlt', 'Classification', 'CreatureType', 'CreatureFamily',
                 'StartAnimState', 'DisplayID1', 'DisplayID2', 'DisplayID3', 'DisplayID4', 'DisplayProbability1',
                 'DisplayProbability2', 'DisplayProbability3', 'DisplayProbability4', 'AlwaysItem1', 'AlwaysItem2', 'AlwaysItem3'],
    'ItemSubClass': ['DisplayName', 'VerboseName', 'ID', 'ClassID', 'SubClassID', 'AuctionHouseSortOrder', 'PrerequisiteProficiency',
                     'Flags', 'DisplayFlags', 'WeaponSwingSize', 'PostrequisiteProficiency'],
}


def _convert(raw, kind, signed, size):
    if kind == 'float':
        return round(struct.unpack('<f', struct.pack('<I', raw & 0xFFFFFFFF))[0], 4)
    if size < 8:
        raw &= (1 << (size * 8)) - 1  # bit-packed and pallet values can carry bits beyond the field's own size
    if signed and size < 8 and raw >= 1 << (size * 8 - 1):
        return raw - (1 << (size * 8))
    return raw


class Table:
    def __init__(self, path, name=None):
        self.name = name or os.path.splitext(os.path.basename(path))[0]
        meta = SCHEMA.get(self.name)
        if meta is None:
            raise KeyError(f'No schema for {self.name}')
        self.meta = meta
        with open(path, 'rb') as handle:
            self.data = data = handle.read()
        if data[:4] != b'WDC5':
            raise ValueError(f'{path}: not WDC5')
        o = 136
        (self.record_count, self.field_count, self.record_size, self.string_table_size, self.table_hash, self.layout_hash,
         _min, _max, _loc) = struct.unpack_from('<9I', data, o)
        o += 36
        self.flags, self.id_index = struct.unpack_from('<HH', data, o)
        o += 4
        _total, _bp, _lookup, storage_size, common_size, pallet_size, section_count = struct.unpack_from('<7I', data, o)
        o += 28
        self.sections = []
        for _ in range(section_count):
            self.sections.append(struct.unpack_from('<QIIIIIIII', data, o))
            o += 40
        o += 4 * self.field_count
        self.storage = [struct.unpack_from('<HHIIIII', data, o + i * 24) for i in range(storage_size // 24)]
        o += storage_size
        self.pallet, self.common = {}, {}
        pallet_cursor, common_cursor = o, o + pallet_size
        for index, (_bo, _bits, extra, kind, *_v) in enumerate(self.storage):
            if kind in (3, 4):
                self.pallet[index] = pallet_cursor
                pallet_cursor += extra
            elif kind == 2:
                self.common[index] = {k: v for k, v in (struct.unpack_from('<II', data, common_cursor + e * 8) for e in range(extra // 8))}
                common_cursor += extra
        fields = meta['fields'][:meta['fileFieldCount']]  # a parent column outside the file comes from relationship data
        self.file_fields = fields
        names = meta['names'] or EXTRA_NAMES.get(self.name)
        flat = []  # (file field index or None, element index, type, signed)
        if meta['index'] == -1:
            flat.append(('id', 0, 'int', False))
        for index, field in enumerate(fields):
            for element in range(field['array']):
                flat.append((index, element, field['type'], field['signed']))
        if meta['fieldCount'] > meta['fileFieldCount']:
            flat.append(('parent', 0, 'int', False))
        if not names or len(names) != len(flat):
            names = [f'f{i}' for i in range(len(flat))]
        self.columns = list(zip(names, flat))

    # ---------- dense records ----------
    def _bits(self, base, bit_offset, bit_count):
        start = base + bit_offset // 8
        return (int.from_bytes(self.data[start:start + 9], 'little') >> (bit_offset % 8)) & ((1 << bit_count) - 1)

    def _dense_field(self, base, index, record_id, field, virtual=0):
        bit_offset, bit_count, _extra, kind, val1, _val2, val3 = self.storage[index]
        size = SIZES[field['type']]
        if kind == 0:
            start = base + bit_offset // 8
            values = []
            for element in range(field['array']):
                at = start + element * size
                raw = int.from_bytes(self.data[at:at + size], 'little')
                if field['type'].startswith('string'):
                    # WDC3+: offset from the field's place in the virtual block of every section's records
                    raw = ('str', virtual + bit_offset // 8 + element * size + raw)
                values.append(raw)
            return values
        if kind in (1, 5):
            raw = self._bits(base, bit_offset, bit_count)
            if kind == 5 and raw & (1 << (bit_count - 1)):
                raw -= 1 << bit_count
            return [raw & 0xFFFFFFFF if raw < 0 else raw]
        if kind == 2:
            return [self.common.get(index, {}).get(record_id, val1)]
        if kind == 3:
            slot = self._bits(base, bit_offset, bit_count)
            return [struct.unpack_from('<I', self.data, self.pallet[index] + slot * 4)[0]]
        if kind == 4:
            slot = self._bits(base, bit_offset, bit_count)
            return list(struct.unpack_from(f'<{val3}I', self.data, self.pallet[index] + slot * 4 * val3))
        raise ValueError(f'storage type {kind}')

    def _string(self, virtual):
        offset = virtual - self.total_records * self.record_size
        for start, file_pos, size in self.string_blocks:
            if start <= offset < start + size:
                address = file_pos + offset - start
                end = self.data.index(b'\0', address)
                return self.data[address:end].decode('utf-8', 'replace')
        return ''

    # ---------- iteration ----------
    def __iter__(self):
        data, fields = self.data, self.file_fields
        sparse = bool(self.flags & 1)
        self.total_records = sum(s[2] for s in self.sections)
        self.hidden_records = 0
        self.string_blocks, concat = [], 0
        for s in self.sections:
            self.string_blocks.append((concat, s[1] + s[2] * self.record_size, s[3]))
            concat += s[3]
        global_base = -1
        for (tact_key, file_offset, rec_count, str_size, records_end, id_size, rel_size, map_count, copy_count) in self.sections:
            global_base = (global_base if global_base >= 0 else 0)
            section_base = global_base
            global_base += rec_count
            # 'TRINITY' marks a section the extractor could decrypt; other keyed sections are zero-filled.
            if tact_key and tact_key != DECRYPTED:
                self.hidden_records += rec_count
                continue
            if sparse:
                cursor = records_end
            else:
                cursor = file_offset + rec_count * self.record_size + str_size
            ids = list(struct.unpack_from(f'<{id_size // 4}I', data, cursor)) if id_size else []
            cursor += id_size
            copies = [struct.unpack_from('<II', data, cursor + i * 8) for i in range(copy_count)]
            cursor += copy_count * 8
            offset_map = [struct.unpack_from('<IH', data, cursor + i * 6) for i in range(map_count)]
            cursor += map_count * 6
            relations = {}
            if rel_size:
                count = struct.unpack_from('<I', data, cursor)[0]
                for i in range(count):
                    foreign, index = struct.unpack_from('<II', data, cursor + 12 + i * 8)
                    relations[index] = foreign
                cursor += rel_size
            map_ids = list(struct.unpack_from(f'<{map_count}I', data, cursor)) if sparse and map_count else []
            produced = {}
            total = map_count if sparse else rec_count
            for index in range(total):
                if sparse:
                    offset, size = offset_map[index]
                    if not size:
                        continue
                    record_id = ids[index] if ids else map_ids[index]
                    values = self._sparse_record(offset, fields)
                else:
                    base = file_offset + index * self.record_size
                    record_id = ids[index] if ids else None
                    if record_id is None:
                        index_field = self.meta['index']
                        record_id = self._dense_field(base, index_field, 0, fields[index_field])[0]
                    virtual = (section_base + index) * self.record_size
                    values = [self._dense_field(base, f, record_id, fields[f], virtual) for f in range(len(fields))]
                row = self._row(record_id, values, relations.get(index))
                produced[record_id] = row
                yield row
            for new_id, old_id in copies:
                if old_id in produced:
                    row = dict(produced[old_id])
                    for name, (where, *_r) in self.columns:
                        if where == 'id':
                            row[name] = new_id
                    if self.meta['index'] >= 0:
                        row[self.columns[self._index_column()][0]] = new_id
                    yield row

    def _index_column(self):
        position = 0 if self.meta['index'] != -1 else 1
        for index, field in enumerate(self.meta['fields']):
            if index == self.meta['index']:
                return position
            position += field['array']
        return 0

    def _sparse_record(self, offset, fields):
        values, cursor = [], offset
        for field in fields:
            element_values = []
            for _ in range(field['array']):
                if field['type'].startswith('string'):
                    end = self.data.index(b'\0', cursor)
                    element_values.append(self.data[cursor:end].decode('utf-8', 'replace'))
                    cursor = end + 1
                else:
                    size = SIZES[field['type']]
                    element_values.append(int.from_bytes(self.data[cursor:cursor + size], 'little'))
                    cursor += size
            values.append(element_values)
        return values

    def _row(self, record_id, values, relation):
        row = {}
        for name, (where, element, kind, signed) in self.columns:
            if where == 'id':
                row[name] = record_id
            elif where == 'parent':
                row[name] = relation or 0
            else:
                raw = values[where][element] if element < len(values[where]) else 0
                if isinstance(raw, tuple):
                    raw = self._string(raw[1])
                if isinstance(raw, int):
                    raw = _convert(raw, kind, signed, SIZES[kind])
                row[name] = raw
        return row


def load(folder, name, key='ID'):
    """Dict of rows by key (ID by default)."""
    return {row[key]: row for row in Table(os.path.join(folder, name + '.db2'), name)}
