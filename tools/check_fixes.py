"""Dry-runs every Forge fix against the server databases: each fix is applied, then its rollback,
inside a transaction that is always rolled back. Nothing is kept. Reports the fixes that fail.

    python tools/check_fixes.py [--kind hotfix|trainer|instance] [--limit N]
"""
import argparse
import json
import os
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from ft import sqlio  # noqa: E402


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--kind')
    parser.add_argument('--limit', type=int, default=0)
    args = parser.parse_args()
    where = f"WHERE kind = '{args.kind}'" if args.kind in ('hotfix', 'trainer', 'instance') else ''
    fixes = sqlio.query(f'SELECT type, id, kind, db, sql_apply, sql_rollback FROM ft_fixes {where} ORDER BY kind, type, id' + (f' LIMIT {args.limit}' if args.limit else ''), 'tracker')
    by_db = {}
    for fix in fixes:
        by_db.setdefault(fix['db'], []).append(fix)
    failed = 0
    for database, items in by_db.items():
        for fix in items:
            # one transaction per fix, so a failure names its fix; ROLLBACK always discards it
            script = 'SET autocommit = 0;\nSTART TRANSACTION;\n' + fix['sql_apply'] + '\n' + fix['sql_rollback'] + '\nROLLBACK;\n'
            with tempfile.NamedTemporaryFile('w', suffix='.sql', delete=False, encoding='utf-8') as handle:
                handle.write(script)
            try:
                sqlio.execute_file(handle.name, database)
            except RuntimeError as error:
                failed += 1
                print(f"FAIL {fix['kind']} {fix['type']} {fix['id']}: {str(error).strip()[:300]}")
            finally:
                os.unlink(handle.name)
    print(json.dumps({'checked': len(fixes), 'failed': failed}))


if __name__ == '__main__':
    main()
