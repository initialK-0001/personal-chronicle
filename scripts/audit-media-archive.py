#!/usr/bin/env python3
"""Audit a private uploads ZIP without extracting or publishing its contents."""
import argparse, hashlib, json, re, sqlite3, zipfile
from pathlib import Path, PurePosixPath

def php_files(raw):
    """Read only WordPress Download Manager's flat integer/string array."""
    b=raw.encode('utf-8'); m=re.match(rb'a:(\d+):\{',b)
    if not m: raise ValueError('配布ファイル一覧の形式が未対応です')
    pos=m.end(); values=[]
    for _ in range(int(m[1])):
        m=re.match(rb'i:\d+;s:(\d+):"',b[pos:])
        if not m: raise ValueError('配布ファイル一覧の項目が未対応です')
        pos+=m.end(); length=int(m[1]); value=b[pos:pos+length]; pos+=length
        if b[pos:pos+2]!=b'";': raise ValueError('配布ファイル名の長さが不正です')
        pos+=2; values.append(value.decode('utf-8'))
    if b[pos:]!=b'}': raise ValueError('配布ファイル一覧の末尾が不正です')
    return values

def audit(archive,database,prefix):
    if not re.fullmatch(r'[A-Za-z0-9_]+',prefix): raise ValueError('接頭辞が不正です')
    files={}
    with zipfile.ZipFile(archive) as z:
        entries=z.infolist()
        if sum(e.file_size for e in entries)>8*1024**3: raise ValueError('検証対象が8GBを超えています')
        seen=set()
        for e in entries:
            path=PurePosixPath(e.filename)
            if path.is_absolute() or '..' in path.parts or '\\' in e.filename or e.filename in seen: raise ValueError('安全でないか重複したファイル名です')
            seen.add(e.filename)
            if e.is_dir(): continue
            h=hashlib.sha256(); length=0
            with z.open(e) as f:
                while chunk:=f.read(1024*1024): h.update(chunk); length+=len(chunk)
            if length!=e.file_size: raise ValueError('ファイルサイズが一致しません')
            files[e.filename]={'bytes':length,'sha256':h.hexdigest()}
    db=sqlite3.connect(database.resolve().as_uri()+'?mode=ro',uri=True)
    try:
        attachments=[r[0] for r in db.execute('SELECT meta_value FROM "'+prefix+'postmeta" WHERE meta_key=?',('_wp_attached_file',))]
        downloads=[]
        for (raw,) in db.execute('SELECT meta_value FROM "'+prefix+'postmeta" WHERE meta_key=?',('__wpdm_files',)):
            downloads.extend(php_files(raw))
    finally: db.close()
    missing_attachments=[p for p in attachments if p not in files]
    missing_downloads=[p for p in downloads if 'download-manager-files/'+p not in files]
    return {'scope':'selected upload directories only; not full site restore verification','archive_crc_verified':True,'archive_files':len(files),'attachment_records':len(attachments),'matched_attachments':len(attachments)-len(missing_attachments),'missing_attachments':missing_attachments,'download_references':len(downloads),'matched_downloads':len(downloads)-len(missing_downloads),'missing_downloads':missing_downloads,'files':files}

if __name__=='__main__':
    p=argparse.ArgumentParser(description='非公開の画像バックアップとDBを照合')
    p.add_argument('archive',type=Path);p.add_argument('database',type=Path);p.add_argument('--prefix',required=True);p.add_argument('--output',required=True,type=Path)
    a=p.parse_args(); result=audit(a.archive,a.database,a.prefix)
    with a.output.open('x',encoding='utf-8') as f: a.output.chmod(0o600);json.dump(result,f,ensure_ascii=False,indent=2)
    print(json.dumps({k:v for k,v in result.items() if k not in ('files','missing_attachments','missing_downloads')},ensure_ascii=False))
