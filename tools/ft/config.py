"""Reads config.ini (shared with the website) and resolves the project and server paths."""
import configparser
import json
import os

PROJECT = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
DATA = os.path.join(PROJECT, 'data')
BUILDS = os.path.join(DATA, 'builds')
SCHEMA = os.path.join(PROJECT, 'tools', 'schema', 'db2-schema.json')


def _clean(value):
    value = value.strip()
    if len(value) >= 2 and value[0] == value[-1] and value[0] in '"\'':
        value = value[1:-1]
    return value


class Config:
    def __init__(self, path=None):
        path = path or os.environ.get('FOREVER_TRACKER_CONFIG') or os.path.join(PROJECT, 'config.ini')
        if not os.path.isfile(path):
            raise SystemExit(f'Missing {path}: copy config.sample.ini to config.ini and edit it.')
        parser = configparser.ConfigParser(interpolation=None, inline_comment_prefixes=(';',))
        parser.optionxform = str
        parser.read(path, encoding='utf-8-sig')
        self.parser = parser

    def get(self, section, key, default=''):
        if self.parser.has_option(section, key):
            return _clean(self.parser.get(section, key))
        return default

    def db(self, name):
        """Name of a database: tracker, world, hotfixes or auth."""
        return self.get('database', name, name)

    def server_path(self, key):
        value = self.get('server', key)
        if not value:
            return ''
        return os.path.normpath(value if os.path.isabs(value) else os.path.join(self.get('server', 'root'), value))

    def dbc_folder(self):
        """(folder, configured): the server's DB2 folder. When the configured one has no DB2 file, the locale
        folders next to it (and under <root>/data/dbc) are tried; folder is None when none is found."""
        configured = self.server_path('dbc')
        has_db2 = lambda path: bool(path) and os.path.isfile(os.path.join(path, 'SpellName.db2'))
        if has_db2(configured):
            return configured, configured
        root = self.get('server', 'root')
        bases = [b for b in (configured, configured and os.path.dirname(configured), os.path.join(root, 'data', 'dbc'), os.path.join(root, 'dbc')) if b]
        for base in bases:
            for locale in ('enUS', 'frFR', 'deDE', 'esES', 'esMX', 'ruRU', 'ptBR', 'itIT', 'koKR', 'zhCN', 'zhTW'):
                if has_db2(os.path.join(base, locale)):
                    return os.path.join(base, locale), configured
        return None, configured

    def server_build(self):
        build_file = self.server_path('build_file')
        if build_file and os.path.isfile(build_file):
            info = json.load(open(build_file, encoding='utf-8-sig'))
            return int(info.get('Build') or info.get('build') or 0)
        return int(self.get('server', 'build', '0') or 0)


CONFIG = Config()
