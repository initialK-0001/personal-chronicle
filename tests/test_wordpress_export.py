import importlib.util
from pathlib import Path
import sqlite3
import tempfile
import unittest
spec=importlib.util.spec_from_file_location('exporter',Path(__file__).parents[1]/'scripts/export-wordpress-staging.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class ExportTests(unittest.TestCase):
 def test_thumbnail_id_string_matches_integer_attachment(self):
  with tempfile.TemporaryDirectory() as d:
   path=Path(d)/'source.sqlite';db=sqlite3.connect(path)
   db.executescript('''CREATE TABLE wpoptions(option_name,option_value);
CREATE TABLE wpposts(ID,post_type,post_status,post_name,post_title,post_content,post_date,post_modified);
CREATE TABLE wppostmeta(post_id,meta_key,meta_value);
CREATE TABLE wpterm_relationships(object_id,term_taxonomy_id);
CREATE TABLE wpterm_taxonomy(term_taxonomy_id,term_id,taxonomy);
CREATE TABLE wpterms(term_id,name,slug);''')
   db.executemany('INSERT INTO wpoptions VALUES(?,?)',[('permalink_structure','/%postname%'),('timezone_string','Asia/Tokyo')])
   db.execute('INSERT INTO wpposts VALUES(?,?,?,?,?,?,?,?)',(1,'post','draft','test','題名','本文','2022-10-23 10:00:00','2022-10-23 10:00:00'))
   db.executemany('INSERT INTO wppostmeta VALUES(?,?,?)',[(1,'_thumbnail_id','99'),(99,'_wp_attached_file','2022/10/image.png')]);db.commit();db.close()
   r=m.export(path,'wp');self.assertEqual(r['records'][0]['thumbnail'],'/wp-content/uploads/2022/10/image.png');self.assertFalse(r['publication_enabled']);self.assertEqual(r['records'][0]['status'],'draft')
if __name__=='__main__':unittest.main()
