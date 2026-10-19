"""Tiny MySQL access through the mysql command-line client (no Python driver needed)."""
import os
import shutil
import subprocess
import tempfile

from .config import CONFIG


def _mysql():
    configured = CONFIG.server_path('mysql')
    if configured and os.path.isfile(configured):
        return configured
    found = shutil.which('mysql')
    if not found:
        raise SystemExit('mysql client not found: set [server] mysql in config.ini.')
    return found


def _client_file():
    handle = tempfile.NamedTemporaryFile('w', suffix='.cnf', delete=False, encoding='utf-8')
    handle.write('[client]\n'
                 f"host={CONFIG.get('database', 'host', '127.0.0.1')}\n"
                 f"port={CONFIG.get('database', 'port', '3306')}\n"
                 f"user={CONFIG.get('database', 'user')}\n"
                 f"password={CONFIG.get('database', 'password')}\n"
                 'default-character-set=utf8mb4\n')
    handle.close()
    return handle.name


def _unescape(value):
    if value == 'NULL':
        return None
    return value.replace('\\t', '\t').replace('\\n', '\n').replace('\\0', '\0').replace('\\\\', '\\')


def query(sql, database='world'):
    """Rows as dicts (values are str or None). `database` is a key of [database] or a real name."""
    cnf = _client_file()
    try:
        result = subprocess.run([_mysql(), f'--defaults-extra-file={cnf}', '--batch', CONFIG.db(database), '-e', sql], capture_output=True)
        if result.returncode:
            raise RuntimeError(f"{sql[:120]}: {result.stderr.decode('utf-8', 'replace').strip()}")
        out = result.stdout.decode('utf-8', 'replace').replace('\r\n', '\n')
    finally:
        os.unlink(cnf)
    lines = out.split('\n')
    if not lines or not lines[0]:
        return []
    header = lines[0].split('\t')
    return [dict(zip(header, map(_unescape, line.split('\t')))) for line in lines[1:] if line]


def column(sql, database='world'):
    return [next(iter(row.values())) for row in query(sql, database)]


def has_table(name, database='world'):
    return bool(query(f"SHOW TABLES LIKE '{name}'", database))


def execute_file(path, database):
    cnf = _client_file()
    try:
        with open(path, 'rb') as handle:
            result = subprocess.run([_mysql(), f'--defaults-extra-file={cnf}', CONFIG.db(database)], stdin=handle, capture_output=True)
        if result.returncode:
            raise RuntimeError(result.stderr.decode('utf-8', 'replace'))
    finally:
        os.unlink(cnf)


def escape(value):
    if value is None:
        return 'NULL'
    if isinstance(value, bool):
        return '1' if value else '0'
    if isinstance(value, int):
        return str(value)
    if isinstance(value, float):
        return repr(value) if value == value else '0'
    return "'" + str(value).replace('\\', '\\\\').replace("'", "\\'").replace('\n', '\\n').replace('\r', '\\r').replace('\0', '\\0') + "'"
