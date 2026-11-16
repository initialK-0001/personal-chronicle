#!/usr/bin/env python3
"""Preserve original post/page records in a PRIVATE, non-publishing package."""
import argparse,datetime,hashlib,json,re,sqlite3
from pathlib import Path
from zoneinfo import ZoneInfo

def export(dbpath,prefix):
 if not re.fullmatch(r'[A-Za-z0-9_]+',prefix):raise ValueError('接頭辞が不正です')
 db=sqlite3.connect(dbpath.resolve().as_uri()+'?mode=ro',uri=True);db.row_factory=sqlite3.Row
 try:
  options=dict((r[0],r[1]) for r in db.execute('select option_name,option_value from '+prefix+'options'))
  if options.get('permalink_structure')!='/%postname%':raise ValueError('パーマリンク形式の確認が必要です')
  zone=ZoneInfo(options.get('timezone_string') or 'UTC');records=[]
  def date(value):
   if value.startswith('0000-'):return None
   return datetime.datetime.strptime(value,'%Y-%m-%d %H:%M:%S').replace(tzinfo=zone).astimezone(datetime.timezone.utc).isoformat()
  for row in db.execute('select * from '+prefix+'posts where post_type in (?,?) order by ID',('post','page')):
   r=dict(row)
   terms=[dict(x) for x in db.execute('SELECT t.term_id,t.name,t.slug,tt.taxonomy FROM '+prefix+'term_relationships rel JOIN '+prefix+'term_taxonomy tt ON rel.term_taxonomy_id=tt.term_taxonomy_id JOIN '+prefix+'terms t ON tt.term_id=t.term_id WHERE rel.object_id=? ORDER BY t.term_id',(r['ID'],))]
   records.append({'legacy_id':r['ID'],'path':'/'+r['post_name'],'status':r['post_status'],'type':r['post_type'],'title':r['post_title'],'original_html':r['post_content'],'original_sha256':hashlib.sha256(r['post_content'].encode()).hexdigest(),'published_at':date(r['post_date']),'updated_at':date(r['post_modified']),'terms':terms,'original_record':r})
  # Featured images are public only when their owning article is public
  for record in records:
   q=db.execute('SELECT meta_value FROM '+prefix+'postmeta WHERE post_id=? AND meta_key=?',(record['legacy_id'],'_thumbnail_id')).fetchone()
   if q:
    f=db.execute('SELECT meta_value FROM '+prefix+'postmeta WHERE post_id=? AND meta_key=?',(int(q[0]),'_wp_attached_file')).fetchone()
    if f:record['thumbnail']='/wp-content/uploads/'+f[0]
  import importlib.util
  spec=importlib.util.spec_from_file_location('media_audit',Path(__file__).with_name('audit-media-archive.py'));mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
  downloads=[]
  for row in db.execute('SELECT ID,post_status FROM '+prefix+'posts WHERE post_type=?',('wpdmpro',)):
   meta=dict((x[0],x[1]) for x in db.execute('SELECT meta_key,meta_value FROM '+prefix+'postmeta WHERE post_id=?',(row[0],)))
   files=mod.php_files(meta.get('__wpdm_files','a:0:{}'))
   access=meta.get('__wpdm_access','')
   downloads.append({'id':row[0],'files':files,'public':row[1]=='publish' and not meta.get('__wpdm_password') and ('guest' in access or access=='')})
  return {'schema_version':1,'publication_enabled':False,'records':records,'downloads':downloads}
 finally:db.close()
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('database',type=Path);p.add_argument('--prefix',required=True);p.add_argument('--output',type=Path,required=True);a=p.parse_args();data=export(a.database,a.prefix)
 with a.output.open('x',encoding='utf-8') as f:a.output.chmod(0o600);json.dump(data,f,ensure_ascii=False)
 from collections import Counter
 print(json.dumps({'records':len(data['records']),'states':dict(Counter(r['status'] for r in data['records'])),'published':0},ensure_ascii=False))
