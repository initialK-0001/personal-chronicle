import importlib.util,json,pathlib,sqlite3,tempfile,unittest
spec=importlib.util.spec_from_file_location('routes',pathlib.Path(__file__).parents[1]/'scripts/audit-wordpress-routes.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class RoutesTests(unittest.TestCase):
 def test_public_only_and_separate_categories(self):
  with tempfile.TemporaryDirectory() as d:
   root=pathlib.Path(d);dbpath=root/'stage.sqlite';c=sqlite3.connect(dbpath)
   c.executescript('CREATE TABLE wp_options(option_name,option_value);CREATE TABLE wp_posts(ID,post_name,post_type,post_parent,post_password,post_status);')
   c.executemany('INSERT INTO wp_options VALUES (?,?)',[('home','https://example.test'),('permalink_structure','/%postname%')])
   c.executemany('INSERT INTO wp_posts VALUES (?,?,?,?,?,?)',[(1,'article','post',0,'','publish'),(2,'private','post',0,'','private'),(3,'draft','post',0,'','draft')]);c.commit();c.close()
   inventory=root/'urls.json';inventory.write_text(json.dumps([{'url':'https://example.test/article'},{'url':'https://example.test/category/example'}]))
   result=m.audit(dbpath,'wp_',inventory)
   self.assertEqual(result['routes'],{'/article':1});self.assertEqual(result['matched_paths'],1);self.assertEqual(result['inventory_not_post_or_page'],['/category/example'])
   c=sqlite3.connect(dbpath);c.execute("UPDATE wp_posts SET post_password='protected' WHERE ID=1");c.commit();c.close()
   with self.assertRaises(ValueError):m.audit(dbpath,'wp_',inventory)
if __name__=='__main__':unittest.main()
