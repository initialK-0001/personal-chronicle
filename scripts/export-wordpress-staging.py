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
  return {'schema_version':1,'publication_enabled':False,'records':records}
 finally:db.close()
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('database',type=Path);p.add_argument('--prefix',required=True);p.add_argument('--output',type=Path,required=True);a=p.parse_args();data=export(a.database,a.prefix)
 with a.output.open('x',encoding='utf-8') as f:a.output.chmod(0o600);json.dump(data,f,ensure_ascii=False)
 from collections import Counter
 print(json.dumps({'records':len(data['records']),'states':dict(Counter(r['status'] for r in data['records'])),'published':0},ensure_ascii=False))
