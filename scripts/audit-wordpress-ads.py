#!/usr/bin/env python3
"""Extract only AdSense identifiers/placement flags; never copy arbitrary option values."""
import argparse,gzip,hashlib,html,importlib.util,json,os,re
from pathlib import Path
spec=importlib.util.spec_from_file_location('dump',Path(__file__).with_name('inspect-wordpress-dump.py'));dump=importlib.util.module_from_spec(spec);spec.loader.exec_module(dump)
def audit(source,prefix):
 if not re.fullmatch(r'[A-Za-z0-9_]+',prefix):raise ValueError('接頭辞が不正です')
 raw=source.read_bytes();data=gzip.decompress(raw) if raw[:2]==b'\x1f\x8b' else raw
 units=[];flags={};manual=None
 for match in re.finditer(rb'^INSERT INTO `'+prefix.encode()+rb'options` VALUES (.*);\r?$',data,re.M):
  for row in dump.rows(match[1]):
   name,value=row[1:3]
   if not isinstance(value,str) or not (name.startswith('widget_') or name=='theme_mods_cocoon-child-master'):continue
   clean=html.unescape(value).replace('\\"','"')
   for unit in re.findall(r'<ins\b[^>]*class="adsbygoogle"[^>]*>',clean):
    client=re.search(r'data-ad-client="(ca-pub-\d{16})"',unit);slot=re.search(r'data-ad-slot="(\d{10})"',unit)
    if client and slot:
     fmt=re.search(r'data-ad-format="([^"]+)"',unit)
     units.append({'option':name,'client':client[1],'slot':slot[1],'format':fmt[1] if fmt else ''})
   if name=='theme_mods_cocoon-child-master':
    flags=dict(re.findall(r's:\d+:"(ad_pos_[a-z_]+_visible)";s:\d+:"([01]?)";',value))
    manual='s:10:"by_myself"' in value or '"by_myself"' in value
 common=[u for u in units if u['option']=='theme_mods_cocoon-child-master' and u['format']=='auto']
 clients=set(u['client'] for u in units)
 if len(clients)!=1 or len(common)!=1:raise ValueError('共通広告枠を一意に特定できません')
 return {'source_sha256':hashlib.sha256(raw).hexdigest(),'manual_placement':manual,'units':units,'placement_flags':flags,'account_settings_verified':False,'config_fragment':{'adsense':{'enabled':False,'account_settings_verified':False,'client':common[0]['client'],'slot':common[0]['slot']}}}
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('source',type=Path);p.add_argument('--prefix',required=True);p.add_argument('--output',required=True,type=Path);a=p.parse_args();result=audit(a.source,a.prefix)
 fd=os.open(a.output,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
 with os.fdopen(fd,'w') as f:json.dump(result,f,ensure_ascii=False,indent=2)
 print('広告識別子と配置設定を非公開ファイルへ抽出しました 広告配信は無効です')
