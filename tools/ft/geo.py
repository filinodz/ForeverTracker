"""Map coordinates: Wowhead's zone percentages (zone id + floor + x%, y%) to world positions (map, x, y).

Wowhead places a creature with the AreaTable id of its zone and percentages on that zone's map (UiMap). The
client's UiMapAssignment rows give, for every UiMap, the world rectangle (Region) drawn on each part of it, so
the inverse of the projection the game uses is exact on X and Y. The height is left to the server (".go xyz"
without z computes the ground, terrain and buildings included) or to the terrain files.
"""
from .content import table


class Geo:
    def __init__(self, folder):
        rows = lambda name: table(folder, name)
        self.ui_maps = {r['ID']: r for r in rows('UiMap')}
        self.assignments = {}
        for a in rows('UiMapAssignment'):
            self.assignments.setdefault(a['UiMapID'], []).append(a)
        self.floors = {}  # UiMapGroupID -> {floor index: UiMapID}
        self.group_of = {}
        for m in rows('UiMapGroupMember'):
            self.floors.setdefault(m['UiMapGroupID'], {})[m['FloorIndex']] = m['UiMapID']
            self.group_of[m['UiMapID']] = m['UiMapGroupID']
        self.areas = {r['ID']: r for r in rows('AreaTable')}
        self.maps = {r['ID']: r for r in rows('Map')}
        self.map_by_area = {}
        for m in self.maps.values():
            if m['AreaTableID']:
                self.map_by_area.setdefault(m['AreaTableID'], m['ID'])

    def zone_ui_maps(self, zone):
        """UiMaps that draw a zone (AreaTable id), best first: zone maps, then dungeon floors."""
        found = []
        for ui_id, rows in self.assignments.items():
            for a in rows:
                if a['AreaID'] == zone:
                    found.append(ui_id)
                    break
        map_id = self.map_by_area.get(zone)
        if map_id is not None:
            for ui_id, rows in self.assignments.items():
                if ui_id not in found and any(a['MapID'] == map_id for a in rows) and self.ui_maps.get(ui_id, {}).get('Type') in (4, 5, 6):
                    found.append(ui_id)
        # zone (3) and dungeon (4) maps before micro maps and continents
        order = {3: 0, 4: 1, 5: 2, 6: 3, 2: 4, 1: 5, 0: 6}
        return sorted(set(found), key=lambda u: (order.get(self.ui_maps.get(u, {}).get('Type'), 9), u))

    def floor_ui_map(self, zone, floor):
        """UiMap of a floor of a zone; floor 0 (or a single-floor zone) is the zone's own map."""
        candidates = self.zone_ui_maps(zone)
        if not candidates:
            return None
        if floor:
            for ui_id in candidates:
                group = self.group_of.get(ui_id)
                if group:
                    floors = self.floors[group]
                    # Wowhead numbers floors from 1 in the order of the group
                    ordered = [floors[k] for k in sorted(floors)]
                    if 0 < floor <= len(ordered):
                        return ordered[floor - 1]
        return candidates[0]

    def to_world(self, ui_map, u, v):
        """(map, x, y) of a point of a UiMap given in 0..1 units, or None."""
        best = None
        for a in self.assignments.get(ui_map, []):
            if a['UiMinX'] - 1e-6 <= u <= a['UiMaxX'] + 1e-6 and a['UiMinY'] - 1e-6 <= v <= a['UiMaxY'] + 1e-6:
                area = (a['UiMaxX'] - a['UiMinX']) * (a['UiMaxY'] - a['UiMinY'])
                if best is None or area < best[0] or (area == best[0] and a['OrderIndex'] < best[1]['OrderIndex']):
                    best = (area, a)
        if not best:
            return None
        a = best[1]
        min_x, min_y, max_x, max_y = a['Region1X'], a['Region1Y'], a['Region2X'], a['Region2Y']
        span_u, span_v = (a['UiMaxX'] - a['UiMinX']) or 1, (a['UiMaxY'] - a['UiMinY']) or 1
        y = max_y - (u - a['UiMinX']) / span_u * (max_y - min_y)
        x = max_x - (v - a['UiMinY']) / span_v * (max_x - min_x)
        return a['MapID'], round(x, 2), round(y, 2)

    def wowhead(self, zone, floor, x_percent, y_percent):
        ui_map = self.floor_ui_map(zone, int(floor))
        if ui_map is None:
            return None
        world = self.to_world(ui_map, x_percent / 100, y_percent / 100)
        return (world + (ui_map,)) if world else None
