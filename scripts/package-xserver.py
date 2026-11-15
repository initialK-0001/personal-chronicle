#!/usr/bin/env python3
"""Create a code-only archive after composer install --no-dev."""
import argparse,hashlib,json,zipfile
from pathlib import Path
root=Path(__file__).resolve().parents[1]
p=argparse.ArgumentParser();p.add_argument('output');args=p.parse_args()
base=root/'xserver'
if not (base/'vendor/autoload.php').is_file():raise SystemExit('先に xserver で composer install --no-dev を実行してください')
files=[]
for directory in ('src','public','ui','bin','vendor'):
 files.extend(f for f in (base/directory).rglob('*') if f.is_file())
files += [base/'composer.json',base/'composer.lock']
manifest={str(f.relative_to(base)):hashlib.sha256(f.read_bytes()).hexdigest() for f in sorted(files)}
with zipfile.ZipFile(args.output,'w',zipfile.ZIP_DEFLATED) as z:
 for f in sorted(files):z.write(f,'xserver/'+str(f.relative_to(base)))
 z.writestr('SHA256.json',json.dumps(manifest,indent=2))
 z.write(root/'docs/XSERVER.md','DEPLOYMENT.md')
print(f'コードのみ {len(files)} ファイルを梱包しました 設定と投稿データは含みません')
