"""In-game tooltips (items, spells and every other resource) computed from the official client data.

Item values follow the core's own formulas: stats from RandPropPoints and StatPercentEditor
(Item::GetItemStatValue), armor from ItemArmorQuality/Total/ArmorLocation (ItemTemplate::GetArmor),
weapon damage from the ItemDamage* tables (ItemTemplate::GetDPS / GetDamage). Texts are kept in
English and French; the website picks the visitor's language.
"""
import math
import os
import re

from .config import CONFIG
from .content import table
from .spelltext import Renderer, SpellData

INVTYPE_PROP_INDEX = {1: 0, 4: 0, 5: 0, 7: 0, 15: 0, 17: 0, 20: 0, 25: 0, 13: 3, 21: 3, 22: 3, 3: 1, 6: 1, 8: 1, 10: 1, 12: 1,
                      2: 2, 9: 2, 11: 2, 14: 2, 16: 2, 23: 2, 28: 4}
ITEM_FLAG2_CASTER_WEAPON = 0x200
SPELL_ATTR0_PASSIVE = 0x40
SPELL_ATTR0_ON_NEXT_SWING = 0x4 | 0x400
SPELL_ATTR1_CHANNELED = 0x4 | 0x40
COLOR_CODE = re.compile(r'\|c[0-9a-fA-F]{8}|\|r|\|T[^|]*\|t|\|A[^|]*\|a')


def clean(text):
    """Removes WoW markup from a display string (SourceText, descriptions)."""
    if not text:
        return ''
    return re.sub(r'[ \t]+', ' ', COLOR_CODE.sub('', text).replace('|n', '\n')).strip()


def both(fr_value, en_value):
    out = {'fr': fr_value or en_value or ''}
    if en_value and en_value != out['fr']:
        out['en'] = en_value
    return out


class Builder:
    def __init__(self, folder):
        self.folder = folder
        fr = lambda name: table(folder, name)
        en = lambda name: table(folder, name, 'enUS')
        by_id = lambda rows: {r['ID']: r for r in rows}
        self.sparse = by_id(fr('ItemSparse'))
        self.sparse_en = by_id(en('ItemSparse'))
        self.items = by_id(fr('Item'))
        self.subclass = {(r['ClassID'], r['SubClassID']): r for r in fr('ItemSubClass')}
        self.subclass_en = {(r['ClassID'], r['SubClassID']): r for r in en('ItemSubClass')}
        self.sets, self.sets_en = by_id(fr('ItemSet')), by_id(en('ItemSet'))
        self.set_spells = {}
        for r in fr('ItemSetSpell'):
            self.set_spells.setdefault(r['ItemSetID'], []).append(r)
        self.name_desc, self.name_desc_en = by_id(fr('ItemNameDescription')), by_id(en('ItemNameDescription'))
        self.rand_points = by_id(fr('RandPropPoints'))
        self.armor_quality, self.armor_total = by_id(fr('ItemArmorQuality')), by_id(fr('ItemArmorTotal'))
        self.armor_location, self.armor_shield = by_id(fr('ArmorLocation')), by_id(fr('ItemArmorShield'))
        self.damage = {name: by_id(fr('ItemDamage' + name)) for name in ('Ammo', 'OneHand', 'OneHandCaster', 'TwoHand', 'TwoHandCaster')}
        self.skills, self.skills_en = by_id(fr('SkillLine')), by_id(en('SkillLine'))
        self.classes, self.classes_en = by_id(fr('ChrClasses')), by_id(en('ChrClasses'))
        self.races, self.races_en = by_id(fr('ChrRaces')), by_id(en('ChrRaces'))
        self.factions, self.factions_en = by_id(fr('Faction')), by_id(en('Faction'))
        item_effects = by_id(fr('ItemEffect'))
        self.item_effects = {}
        for link in fr('ItemXItemEffect'):
            effect = item_effects.get(link['ItemEffectID'])
            if effect:
                self.item_effects.setdefault(link['ItemID'], []).append(effect)
        self.socket_cost = self._socket_costs()

        # spells
        d = SpellData()
        names_en = by_id(en('SpellName'))
        for r in fr('SpellName'):
            d.names[r['ID']] = {'fr': r['Name'], 'en': names_en.get(r['ID'], {}).get('Name', r['Name'])}
        texts_en = by_id(en('Spell'))
        for r in fr('Spell'):
            other = texts_en.get(r['ID'], r)
            d.texts[r['ID']] = {'fr': {'desc': r['Description'], 'aura': r['AuraDescription'], 'rank': r['NameSubtext']},
                                'en': {'desc': other['Description'], 'aura': other['AuraDescription'], 'rank': other['NameSubtext']}}
        for r in fr('SpellEffect'):
            if r['DifficultyID'] == 0:
                d.effects.setdefault(r['SpellID'], {})[r['EffectIndex']] = r
        d.misc = {r['SpellID']: r for r in fr('SpellMisc') if r['DifficultyID'] == 0}
        d.durations, d.ranges, d.radius = by_id(fr('SpellDuration')), by_id(fr('SpellRange')), by_id(fr('SpellRadius'))
        d.aura_options = {r['SpellID']: r for r in fr('SpellAuraOptions') if r['DifficultyID'] == 0}
        d.targets = {r['SpellID']: r for r in fr('SpellTargetRestrictions') if r['DifficultyID'] == 0}
        self.spells = d
        self.ranges_en = by_id(en('SpellRange'))
        self.cast_times = by_id(fr('SpellCastTimes'))
        self.cooldowns = {r['SpellID']: r for r in fr('SpellCooldowns') if r['DifficultyID'] == 0}
        self.powers = {}
        for r in fr('SpellPower'):
            self.powers.setdefault(r['SpellID'], []).append(r)
        self.reagents = {r['SpellID']: r for r in fr('SpellReagents')}
        self.totems = {r['SpellID']: r for r in fr('SpellTotems')}
        self.levels = {r['SpellID']: r for r in fr('SpellLevels') if r['DifficultyID'] == 0}
        self.render = {'fr': Renderer(d, 'fr'), 'en': Renderer(d, 'en')}

    def _socket_costs(self):
        path = os.path.join(CONFIG.get('server', 'root'), 'data', 'gt', 'ItemSocketCostPerLevel.txt')
        costs = {}
        if os.path.isfile(path):
            for line in open(path, encoding='utf-8', errors='replace'):
                parts = line.strip().split('\t')
                if len(parts) >= 2 and parts[0].isdigit():
                    try:
                        costs[int(parts[0])] = float(parts[1])
                    except ValueError:
                        pass
        return costs

    # ------------------------------------------------------------------ helpers
    def item_name(self, item_id):
        row = self.sparse.get(item_id)
        if not row:
            return None
        return both(row['Display'], self.sparse_en.get(item_id, {}).get('Display'))

    def spell_name(self, spell_id):
        return self.spells.names.get(spell_id)

    def spell_desc(self, spell_id, key='desc'):
        out = {}
        for locale in ('fr', 'en'):
            source = ((self.spells.texts.get(spell_id) or {}).get(locale) or {}).get(key, '')
            out[locale] = self.render[locale].render(source, spell_id) if source else ''
        if out.get('en') == out['fr']:
            out.pop('en')
        return out if out['fr'] or out.get('en') else None

    def _points(self, row):
        quality = row['OverallQualityID']
        index = INVTYPE_PROP_INDEX.get(row['InventoryType'])
        if row['InventoryType'] == 26:
            base = self.items.get(row['ID'], {})
            index = 3 if base.get('SubclassID') == 19 else 0
        points = self.rand_points.get(row['ItemLevel'])
        if index is None or not points:
            return 0
        prefix = {2: 'GoodF', 3: 'SuperiorF', 7: 'SuperiorF', 4: 'EpicF', 5: 'EpicF', 6: 'EpicF'}.get(quality)
        return points[f'{prefix}{index + 1}'] if prefix else 0

    def _armor(self, row, base):
        quality = 3 if row['OverallQualityID'] == 7 else row['OverallQualityID']
        if quality > 6 or base.get('ClassID') != 4:
            return 0
        level = row['ItemLevel']
        if base.get('SubclassID') == 6:
            shield = self.armor_shield.get(level)
            return int(shield[f'Quality{quality + 1}'] + 0.5) if shield else 0
        if not 1 <= base.get('SubclassID', 0) <= 4:
            return 0
        aq, total = self.armor_quality.get(level), self.armor_total.get(level)
        location = self.armor_location.get(5 if row['InventoryType'] == 20 else row['InventoryType'])
        if not aq or not total or not location:
            return 0
        material, modifier = {1: ('Cloth', 'Clothmodifier'), 2: ('Leather', 'Leathermodifier'), 3: ('Mail', 'Chainmodifier'), 4: ('Plate', 'Platemodifier')}[base['SubclassID']]
        return int(aq[f'Qualitymod{quality + 1}'] * total[material] * location[modifier] + 0.5)

    def _dps(self, row, base):
        quality = 3 if row['OverallQualityID'] == 7 else row['OverallQualityID']
        if base.get('ClassID') != 2 or quality > 6:
            return 0.0
        caster = bool(row['Flags2'] & ITEM_FLAG2_CASTER_WEAPON)
        inv, sub = row['InventoryType'], base.get('SubclassID')
        name = None
        if inv == 24:
            name = 'Ammo'
        elif inv == 17:
            name = 'TwoHandCaster' if caster else 'TwoHand'
        elif inv in (15, 25, 26):
            if sub == 19:
                name = 'OneHandCaster'
            elif sub in (2, 3, 18):
                name = 'TwoHandCaster' if caster else 'TwoHand'
        elif inv in (13, 21, 22):
            name = 'OneHandCaster' if caster else 'OneHand'
        entry = self.damage[name].get(row['ItemLevel']) if name else None
        return entry[f'Quality{quality + 1}'] if entry else 0.0

    # ------------------------------------------------------------------ items
    def item(self, item_id):
        row = self.sparse.get(item_id)
        if not row:
            return None
        base = self.items.get(item_id, {})
        en = self.sparse_en.get(item_id, {})
        tip = {'k': 'item', 'n': both(row['Display'], en.get('Display')), 'q': row['OverallQualityID'], 'ilvl': row['ItemLevel']}
        if row['ItemNameDescriptionID'] and row['ItemNameDescriptionID'] in self.name_desc:
            nd = self.name_desc[row['ItemNameDescriptionID']]
            tip['nd'] = both(nd['Description'], self.name_desc_en.get(nd['ID'], {}).get('Description'))
        if row['Bonding']:
            tip['bind'] = row['Bonding']
        if row['MaxCount']:
            tip['uniq'] = row['MaxCount']
        if row['StartQuestID']:
            tip['start'] = row['StartQuestID']
        cls, sub = base.get('ClassID', -1), base.get('SubclassID', -1)
        tip['cls'], tip['subc'] = cls, sub
        if row['InventoryType']:
            tip['slot'] = row['InventoryType']
        names = self.subclass.get((cls, sub))
        if names and cls in (2, 4) and not (cls == 4 and sub == 0):
            tip['sub'] = both(names['DisplayName'], self.subclass_en.get((cls, sub), {}).get('DisplayName'))
        elif names and cls not in (2, 4) and cls not in (15,) and names['DisplayName']:
            tip['type'] = both(names['DisplayName'], self.subclass_en.get((cls, sub), {}).get('DisplayName'))
        dps = self._dps(row, base)
        if dps and row['ItemDelay']:
            average = dps * row['ItemDelay'] / 1000
            low = (row['DmgVariance'] * -0.5 + 1.0) * average
            high = math.floor(average * (row['DmgVariance'] * 0.5 + 1.0) + 0.5)
            tip['dmg'] = [int(low), int(high), row['DamageDamageType']]
            tip['speed'] = row['ItemDelay']
            tip['dps'] = round((int(low) + int(high)) / 2 / (row['ItemDelay'] / 1000), 1)
        armor = self._armor(row, base)
        if armor:
            tip['armor'] = armor
        points = self._points(row)
        stats = []
        socket = self.socket_cost.get(row['ItemLevel'], 0.0)
        for i in range(1, 11):
            stat, percent = row[f'StatModifierBonusStat{i}'], row[f'StatPercentEditor{i}']
            if stat in (-1,) or not percent:
                continue
            value = percent * points * 0.0001 - row[f'StatPercentageOfSocket{i}'] * socket
            value = int(math.floor(value + 0.5))
            if value:
                stats.append([stat, value])
        if stats:
            tip['stats'] = stats
        if cls == 1 and row['ContainerSlots']:
            tip['bag'] = row['ContainerSlots']
        if row['RequiredLevel'] > 1:
            tip['req'] = row['RequiredLevel']
        if row['RequiredSkill'] and row['RequiredSkill'] in self.skills:
            tip['skill'] = [both(self.skills[row['RequiredSkill']]['DisplayName'], self.skills_en.get(row['RequiredSkill'], {}).get('DisplayName')), row['RequiredSkillRank']]
        if row['RequiredAbility'] and self.spell_name(row['RequiredAbility']):
            tip['ability'] = self.spell_name(row['RequiredAbility'])
        if row['MinFactionID'] and row['MinFactionID'] in self.factions:
            tip['rep'] = [both(self.factions[row['MinFactionID']]['Name'], self.factions_en.get(row['MinFactionID'], {}).get('Name')), row['MinReputation']]
        playable = {c for c in self.classes}
        mask = row['AllowableClass']
        if mask not in (-1, 0):
            allowed = [c for c in sorted(playable) if mask & (1 << (c - 1))]
            if allowed and len(allowed) < len(playable):
                tip['classes'] = [[c, both(self.classes[c]['Name'], self.classes_en.get(c, {}).get('Name'))] for c in allowed]
        race_mask = row['AllowableRace1']
        if race_mask not in (-1, 0):
            playable_races = {r['ID']: r for r in self.races.values() if r['PlayableRaceBit'] >= 0 and r['Flags'] & 1}
            allowed = [rid for rid, r in sorted(playable_races.items()) if race_mask & (1 << r['PlayableRaceBit'])]
            if allowed and len(allowed) < len(playable_races):
                tip['races'] = [both(self.races[r]['Name'], self.races_en.get(r, {}).get('Name')) for r in allowed]
        effects = []
        for effect in sorted(self.item_effects.get(item_id, []), key=lambda e: e['LegacySlotIndex']):
            spell = effect['SpellID']
            text = self.spell_desc(spell) or self.spell_name(spell)
            if not text:
                continue
            fx = {'t': effect['TriggerType'], 'd': text, 's': spell}
            if effect['Charges']:
                fx['c'] = abs(effect['Charges'])
            if effect['CoolDownMSec'] > 0:
                fx['cd'] = effect['CoolDownMSec']
            effects.append(fx)
        if effects:
            tip['fx'] = effects
        if row['ItemSet'] and row['ItemSet'] in self.sets:
            item_set = self.sets[row['ItemSet']]
            set_en = self.sets_en.get(row['ItemSet'], {})
            members = [m for m in (item_set[f'ItemID{i}'] for i in range(1, 18)) if m]
            bonuses = []
            # Threshold shares its packed storage with the next byte in this layout: the low byte is the piece count.
            for bonus in sorted(self.set_spells.get(row['ItemSet'], []), key=lambda b: b['Threshold'] & 0xFF):
                text = self.spell_desc(bonus['SpellID'])
                if text:
                    bonuses.append([bonus['Threshold'] & 0xFF, text])
            tip['set'] = {'n': both(item_set['Name'], set_en.get('Name')), 'items': [[m, self.item_name(m) or {'fr': f'#{m}'}] for m in members], 'b': bonuses}
        if row['Description']:
            tip['desc'] = both(clean(row['Description']), clean(en.get('Description')))
        if row['SellPrice']:
            tip['sell'] = row['SellPrice']
        return tip

    # ------------------------------------------------------------------ spells
    def spell(self, spell_id):
        name = self.spell_name(spell_id)
        if not name:
            return None
        d = self.spells
        tip = {'k': 'spell', 'n': both(name['fr'], name['en'])}
        texts = d.texts.get(spell_id, {})
        rank = both((texts.get('fr') or {}).get('rank', ''), (texts.get('en') or {}).get('rank', ''))
        if rank['fr']:
            tip['rank'] = rank
        misc = d.misc.get(spell_id)
        if misc:
            attributes0, attributes1 = misc['Attributes1'], misc['Attributes2']
            if attributes0 & SPELL_ATTR0_PASSIVE:
                tip['passive'] = 1
            if attributes0 & SPELL_ATTR0_ON_NEXT_SWING:
                tip['swing'] = 1
            if attributes1 & SPELL_ATTR1_CHANNELED:
                tip['chan'] = 1
            cast = self.cast_times.get(misc['CastingTimeIndex'])
            if cast and cast['Base'] > 0:
                tip['cast'] = cast['Base']
            spell_range = d.ranges.get(misc['RangeIndex'])
            if spell_range and spell_range['RangeMax1'] > 0 and misc['RangeIndex'] != 1:
                tip['range'] = [spell_range['RangeMin1'], spell_range['RangeMax1'], 1 if spell_range['Flags'] & 1 else 0]
        costs = []
        for power in sorted(self.powers.get(spell_id, []), key=lambda p: p['OrderIndex']):
            if power['ManaCost'] or power['PowerCostPct']:
                costs.append([power['PowerType'], power['ManaCost'], round(power['PowerCostPct'], 1), power['ManaPerSecond']])
        if costs:
            tip['cost'] = costs
        cooldown = self.cooldowns.get(spell_id)
        if cooldown:
            value = max(cooldown['RecoveryTime'], cooldown['CategoryRecoveryTime'])
            if value > 1500:
                tip['cd'] = value
        reagents = self.reagents.get(spell_id)
        if reagents:
            parts = []
            for i in range(1, 9):
                item, count = reagents[f'Reagent{i}'], reagents[f'ReagentCount{i}']
                if item > 0 and count > 0:
                    parts.append([item, count, self.item_name(item) or {'fr': f'#{item}'}])
            if parts:
                tip['reag'] = parts
        totems = self.totems.get(spell_id)
        if totems:
            tools = [[t, self.item_name(t) or {'fr': f'#{t}'}] for t in (totems['Totem1'], totems['Totem2']) if t > 0]
            if tools:
                tip['tools'] = tools
        level = self.levels.get(spell_id)
        if level and (level['BaseLevel'] or level['SpellLevel']) > 1:
            tip['lvl'] = level['BaseLevel'] or level['SpellLevel']
        desc = self.spell_desc(spell_id)
        if desc:
            tip['desc'] = desc
        aura = self.spell_desc(spell_id, 'aura')
        if aura:
            tip['aura'] = aura
        return tip


def build(content, folder, quest_names, ctx):
    """{(type, id): tooltip dict} for every official resource."""
    b = Builder(folder)
    tips = {}
    for item_id in content.get('item', {}):
        tip = b.item(item_id)
        if tip:
            tips[('item', item_id)] = tip
    for spell_id in content.get('spell', {}):
        tip = b.spell(spell_id)
        if tip:
            tips[('spell', spell_id)] = tip
    for recipe_id, entity in content.get('recipe', {}).items():
        tip = dict(tips.get(('spell', recipe_id)) or {'k': 'spell', 'n': both(entity['name'], entity['name_en'])})
        tip['skill'] = [both(entity['sub'], ''), entity['level']]
        if entity.get('creates'):
            tip['creates'] = entity['creates']
        tips[('recipe', recipe_id)] = tip
    for talent_id, entity in content.get('talent', {}).items():
        first = (entity.get('ranks') or [0])[0]
        tip = dict(tips.get(('spell', first)) or {'k': 'spell', 'n': both(entity['name'], entity['name_en'])})
        tip['ranks'] = len(entity.get('ranks') or [])
        tip['tree'] = entity['sub']
        tips[('talent', talent_id)] = tip
    for kind in ('mount', 'pet'):
        for entity_id, entity in content.get(kind, {}).items():
            tip = dict(tips.get(('spell', entity['spell'])) or {'k': 'spell'})
            tip['n'] = both(entity['name'], entity['name_en'])
            tip['col'] = kind
            if entity.get('source'):
                tip['src'] = both(clean(entity['source']), clean(entity.get('source_en')))
            tips[(kind, entity_id)] = tip
    for toy_id, entity in content.get('toy', {}).items():
        tip = dict(tips.get(('item', entity['item'])) or {'k': 'item', 'n': both(entity['name'], entity['name_en'])})
        tip['col'] = 'toy'
        if entity.get('source'):
            tip['src'] = both(clean(entity['source']), clean(entity.get('source_en')))
        tips[('toy', toy_id)] = tip
    for ach_id, entity in content.get('achievement', {}).items():
        tip = {'k': 'achievement', 'n': both(entity['name'], entity['name_en']), 'pts': entity['level'],
               'desc': both(clean(entity['facts'].get('desc')), '')}
        if entity.get('reward'):
            tip['reward'] = both(clean(entity['reward']), '')
        tips[('achievement', ach_id)] = tip
    zones = content.get('zone', {})
    for quest_id in content.get('quest', {}):
        wh = quest_names.get(str(quest_id), {})
        row = ctx['quests'].get(quest_id) or {}
        level, minimum = ctx['quest_level'].get(quest_id, (0, 0))
        tip = {'k': 'quest', 'n': both(ctx['quest_fr'].get(quest_id) or row.get('LogTitle') or wh.get('fr') or '', row.get('LogTitle') or wh.get('en'))}
        if level:
            tip['lvl'] = level
        if minimum:
            tip['min'] = minimum
        zone = zones.get(int(row.get('QuestSortID') or 0))
        if zone:
            tip['zone'] = both(zone['name'], zone['name_en'])
        text = wh.get('text_fr') or wh.get('text_en')
        if text:
            tip['text'] = both(wh.get('text_fr') or '', wh.get('text_en'))
        tips[('quest', quest_id)] = tip
    for map_id, entity in content.get('dungeon', {}).items():
        tips[('dungeon', map_id)] = {'k': 'dungeon', 'n': both(entity['name'], entity['name_en']), 'kind': entity['kind'], 'players': entity['level']}
    for boss_id, entity in content.get('boss', {}).items():
        instance = content.get('dungeon', {}).get(entity['map'], {})
        tips[('boss', boss_id)] = {'k': 'boss', 'n': both(entity['name'], entity['name_en']), 'map': both(instance.get('name', entity['sub']), instance.get('name_en')), 'order': entity['level'] + 1}
    for zone_id, entity in zones.items():
        tips[('zone', zone_id)] = {'k': 'zone', 'n': both(entity['name'], entity['name_en']), 'cont': both(entity['sub'], ''), 'lvl': entity['level']}
    for node_id, entity in content.get('taxi', {}).items():
        tips[('taxi', node_id)] = {'k': 'taxi', 'n': both(entity['name'], entity['name_en']), 'cont': both(entity['sub'], '')}
    return tips
