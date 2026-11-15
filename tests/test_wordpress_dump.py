import importlib.util
import pathlib
import tempfile
import unittest
spec=importlib.util.spec_from_file_location('dump',pathlib.Path(__file__).parents[1]/'scripts/inspect-wordpress-dump.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class DumpTests(unittest.TestCase):
 def test_literal_roundtrip(self):
  self.assertEqual(list(m.rows(b"(1,'a\\n\\\'b,);',NULL),(2,'\\\\',0x00ff)")),[[1,"a\n'b,);",None],[2,'\\',b'\0\xff']])
 def test_non_utf8_kept(self):
  self.assertEqual(list(m.rows(b"('\x91', 'x')".replace(b', ',b','))),[[b'\x91','x']])
 def test_rejects_expressions_and_incomplete_input(self):
  for data in [b'(sleep(1))',b"('unfinished)",b'(1),',b'(1);DROP TABLE x']:
   with self.assertRaises(ValueError):list(m.rows(data))
 def test_overwrite_refused(self):
  with tempfile.TemporaryDirectory() as d:
   p=pathlib.Path(d)/'existing';p.write_text('keep')
   with self.assertRaises(ValueError):m.inspect(p,p,'wp_')
   self.assertEqual(p.read_text(),'keep')
if __name__=='__main__':unittest.main()
