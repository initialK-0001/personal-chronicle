#!/usr/bin/env python3
"""Compare a PRIVATE staging database with an existing public URL inventory."""
import argparse
import json
import re
import sqlite3
from pathlib import Path
from urllib.parse import urlsplit

def audit(database, prefix, inventory):
    if not re.fullmatch(r'[A-Za-z0-9_]+',prefix):raise ValueError('テーブル接頭辞が不正です')
    db=sqlite3.connect(database.resolve().as_uri()+'?mode=ro',uri=True)
    try:
        options=dict(db.execute('SELECT option_name,option_value FROM "'+prefix+'options"'))
        if options.get('permalink_structure')!='/%postname%':
            raise ValueError('このパーマリンク形式にはまだ対応していません')
        posts=list(db.execute('SELECT ID,post_name,post_type,post_parent,post_password FROM "'+prefix+'posts" WHERE post_status=? AND post_type IN (?,?)',('publish','post','page')))
        routes={}
        for id,slug,kind,parent,password in posts:
            if password:raise ValueError('パスワード保護の移行設計が必要です')
            if kind=='page' and parent:raise ValueError('階層ページのURL処理が必要です')
            if not isinstance(slug,str) or not slug or '/' in slug or '?' in slug or '#' in slug:raise ValueError('記事名の確認が必要です')
            path='/'+slug
            if path in routes:raise ValueError('URLが重複しています')
            routes[path]=id
        inventory_data=json.loads(inventory.read_text())
        home=urlsplit(options['home'])
        if any(urlsplit(x['url']).netloc!=home.netloc for x in inventory_data):raise ValueError('台帳のホストが一致しません')
        paths={urlsplit(x['url']).path.rstrip('/') or '/' for x in inventory_data}
        return {'status':'not_deployed','public_post_page_routes':len(routes),'inventory_paths':len(paths),'matched_paths':len(paths.intersection(routes)),'inventory_not_post_or_page':sorted(paths-set(routes)),'post_page_not_in_inventory':sorted(set(routes)-paths),'routes':routes}
    finally:db.close()

if __name__=='__main__':
    p=argparse.ArgumentParser(description='旧URL台帳と移行抽出データを照合')
    p.add_argument('database',type=Path);p.add_argument('--prefix',required=True);p.add_argument('--inventory',required=True,type=Path)
    a=p.parse_args();print(json.dumps(audit(a.database,a.prefix,a.inventory),ensure_ascii=False,indent=2))
