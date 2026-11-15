import importlib.util, pathlib, sqlite3, tempfile, unittest, zipfile
spec=importlib.util.spec_from_file_location('media',pathlib.Path(__file__).parents[1]/'scripts/audit-media-archive.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class MediaAuditTests(unittest.TestCase):
 def test_serialized_unicode_and_rejection(self):
  self.assertEqual(m.php_files('a:1:{i:0;s:10:"画像.png";}'),['画像.png'])
  for raw in ['a:1:{i:0;s:1:"画像.png";}','O:3:"Foo":0:{}']:
   with self.assertRaises(ValueError):m.php_files(raw)
 def test_missing_and_crc(self):
  with tempfile.TemporaryDirectory() as d:
   p=pathlib.Path(d);db=p/'db';z=p/'a.zip';c=sqlite3.connect(db)
   c.execute('create table ppostmeta(meta_key text,meta_value text)')
   c.executemany('insert into ppostmeta values(?,?)',[('_wp_attached_file','2022/a.png'),('_wp_attached_file','2022/missing.png'),('__wpdm_files','a:1:{i:0;s:5:"a.zip";}')]);c.commit();c.close()
   with zipfile.ZipFile(z,'w') as f:f.writestr('2022/a.png',b'image');f.writestr('download-manager-files/a.zip',b'file')
   r=m.audit(z,db,'p');self.assertEqual(r['matched_attachments'],1);self.assertEqual(r['missing_attachments'],['2022/missing.png']);self.assertEqual(r['matched_downloads'],1)
   with zipfile.ZipFile(z,'w') as f:f.writestr('../unsafe',b'x')
   with self.assertRaises(ValueError):m.audit(z,db,'p')
