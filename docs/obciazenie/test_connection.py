#!/usr/bin/env python3
"""Regresja narzędzi: wyłącznie stuby psql/createdb, bez połączenia do PostgreSQL."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parent


class ConnectionTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="kuking-bench-test-")
        self.addCleanup(self.temp.cleanup)
        self.folder = Path(self.temp.name)
        self.log = self.folder / "commands.jsonl"
        stub = self.folder / "psql"
        stub.write_text("""#!/usr/bin/env python3
import json, os, sys
args=sys.argv[1:]
sql='' if '-f' in args or '-tAc' in args else sys.stdin.read()
with open(os.environ['STUB_LOG'],'a') as f:f.write(json.dumps({'args':args,'sql':sql})+'\\n')
if 'SELECT count(*) FROM pg_database' in sql:print(os.environ.get('STUB_EXISTS','0'))
""")
        stub.chmod(0o755)
        # Nawet przypadkowe przejście na createdb nie może dotknąć prawdziwej bazy.
        (self.folder / "createdb").symlink_to(stub)
        (self.folder / "dropdb").symlink_to(stub)
        self.env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
        self.env.update(PATH=str(self.folder)+os.pathsep+os.environ['PATH'],
                        PGHOST='127.0.0.1', PGPORT='55439', PGUSER='benchmark_user',
                        STUB_LOG=str(self.log))

    def run_script(self, db='kuking_bench_test', extra=(), updates=None):
        return subprocess.run(['bash', str(ROOT/'build_scale.sh'), db, '10', '20', '30', *extra],
                              env=self.env | (updates or {}), capture_output=True, text=True)

    def commands(self):
        return [json.loads(x) for x in self.log.read_text().splitlines()] if self.log.exists() else []

    def test_bad_name_never_calls_database_tools(self):
        for name in ['kuking', 'postgres', '', 'kuking_bench_', 'kuking_bench_x;DROP DATABASE kuking', 'kuking_bench_"']:
            with self.subTest(name=name):
                self.assertNotEqual(0, self.run_script(name).returncode)
                self.assertEqual([], self.commands())

    def test_unsafe_connection_never_calls_database_tools(self):
        for change in [{'PGPORT': ''}, {'PGPORT': '5432'}, {'PGPORT': '65536'}, {'PGHOST': 'remote.example'}, {'PGUSER': ''}, {'PGHOSTADDR':'192.0.2.1'}, {'PGSERVICE':'production'}]:
            with self.subTest(change=change):
                self.assertNotEqual(0, self.run_script(updates=change).returncode)
                self.assertEqual([], self.commands())

    def test_existing_database_is_not_reset_by_default(self):
        self.assertNotEqual(0, self.run_script(updates={'STUB_EXISTS':'1'}).returncode)
        commands=self.commands()
        self.assertEqual(1, len(commands))
        self.assertNotIn('DROP', commands[0]['sql'])

    def test_reset_must_name_exact_database(self):
        self.assertNotEqual(0, self.run_script(extra=['--reset=kuking_bench_other']).returncode)
        self.assertEqual([], self.commands())

    def test_new_database_keeps_explicit_connection_and_does_not_drop(self):
        result=self.run_script(updates={'PGDATABASE':'kuking'})
        self.assertEqual(0, result.returncode, result.stderr)
        commands=self.commands()
        self.assertEqual(7, len(commands))
        self.assertFalse(any('DROP' in x['sql'] for x in commands))
        for command in commands:
            args=command['args']
            self.assertEqual('55439', args[args.index('-p')+1])
            self.assertEqual('127.0.0.1', args[args.index('-h')+1])
            self.assertEqual('benchmark_user', args[args.index('-U')+1])
            self.assertIn(args[args.index('-d')+1], ['postgres','kuking_bench_test'])
            self.assertIn('-X', args)
        self.assertIn("format('CREATE DATABASE %I OWNER %I'", commands[1]['sql'])
        self.assertIn('bench_owner=benchmark_user', commands[1]['args'])

    def test_explicit_reset_quotes_identifier(self):
        result=self.run_script(extra=['--reset=kuking_bench_test'], updates={'STUB_EXISTS':'1'})
        self.assertEqual(0, result.returncode, result.stderr)
        commands=self.commands()
        self.assertIn("format('DROP DATABASE %I', :'bench_db')", commands[1]['sql'])
        self.assertIn('bench_db=kuking_bench_test', commands[1]['args'])

    def test_php_validation_does_not_connect(self):
        php=os.environ.get('PHP_BIN','php')
        code='require $argv[1]; echo json_encode(benchmarkConnection());'
        for db, port in [('kuking','55439'),('kuking_bench_test','5432'),('kuking_bench_x;bad','55439')]:
            p=subprocess.run([php,'-r',code,str(ROOT/'connection.php')],env=self.env|{'BENCH_DB':db,'PGPORT':port},capture_output=True,text=True)
            self.assertNotEqual(0,p.returncode)
        p=subprocess.run([php,'-r',code,str(ROOT/'connection.php')],env=self.env|{'BENCH_DB':'kuking_bench_test'},capture_output=True,text=True)
        self.assertEqual(0,p.returncode,p.stderr)
        self.assertEqual(['pgsql:host=127.0.0.1;port=55439;dbname=kuking_bench_test','benchmark_user',None],json.loads(p.stdout))


if __name__ == '__main__':
    unittest.main()
