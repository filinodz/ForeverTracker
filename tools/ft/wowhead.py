"""Wowhead's public Forever tooltip service (the one its tooltip script uses), politely: one request at a
time, a pause between requests, and every answer cached on disk so a resource is only asked for once.

    wh = Wowhead()
    wh.get('npc', 1365)   -> {'name': 'Goli Krumn', 'map': {'zone': 1537, 'coords': {'0': [[29.2, 60.0], ...]}}, ...}

Answers are kept in data/wowhead/<kind>/<id>.json; {"missing": true} records a resource Wowhead does not know.
"""
import json
import os
import time
import urllib.error
import urllib.request

from .config import CONFIG, DATA

URL = 'https://nether.wowhead.com/{branch}/tooltip/{kind}/{id}?locale={locale}'
AGENT = 'ForeverTracker/1.1 (private server admin tool; https://github.com/filinodz/ForeverTracker)'


class Wowhead:
    def __init__(self, refresh=False, pause=None):
        self.branch = CONFIG.get('wowhead', 'branch', 'forever') or 'forever'
        self.pause = float(pause if pause is not None else CONFIG.get('wowhead', 'pause', '0.4') or 0.4)
        self.refresh = refresh
        self.folder = os.path.join(DATA, 'wowhead')
        self.requests = 0
        self._last = 0.0

    def path(self, kind, entity_id):
        return os.path.join(self.folder, kind, f'{int(entity_id)}.json')

    def cached(self, kind, entity_id):
        path = self.path(kind, entity_id)
        if os.path.isfile(path):
            try:
                return json.load(open(path, encoding='utf-8'))
            except ValueError:
                return None
        return None

    def get(self, kind, entity_id, locale=0):
        """The tooltip data, {'missing': True} when Wowhead does not know it, None when it could not be reached."""
        if not self.refresh:
            data = self.cached(kind, entity_id)
            if data is not None:
                return data
        data = self._fetch(kind, entity_id, locale)
        if data is not None:
            data.pop('tooltip', None) if kind in ('npc', 'object') else None
            data['fetched'] = int(time.time())
            os.makedirs(os.path.dirname(self.path(kind, entity_id)), exist_ok=True)
            with open(self.path(kind, entity_id), 'w', encoding='utf-8') as handle:
                json.dump(data, handle, ensure_ascii=False, separators=(',', ':'))
        return data

    def _fetch(self, kind, entity_id, locale):
        url = URL.format(branch=self.branch, kind=kind, id=int(entity_id), locale=locale)
        for attempt in range(4):
            wait = self.pause - (time.time() - self._last)
            if wait > 0:
                time.sleep(wait)
            self._last = time.time()
            self.requests += 1
            try:
                request = urllib.request.Request(url, headers={'User-Agent': AGENT, 'Accept': 'application/json'})
                with urllib.request.urlopen(request, timeout=20) as response:
                    data = json.loads(response.read().decode('utf-8'))
                if not data or data.get('error') or not data.get('name'):
                    return {'missing': True}
                return data
            except urllib.error.HTTPError as error:
                if error.code == 404:
                    return {'missing': True}
                if error.code in (429, 503):
                    time.sleep(5 * (attempt + 1))  # asked to slow down
                    continue
                return None
            except (urllib.error.URLError, TimeoutError, ValueError, OSError):
                time.sleep(2 * (attempt + 1))
        return None
