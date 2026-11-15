#!/usr/bin/env python3
"""Read an Xserver/mysqldump export without executing its SQL.
Output is a PRIVATE staging database, not application/publication data.
"""
import argparse
import collections
import gzip
import hashlib
import json
import os
import re
import sqlite3
from pathlib import Path

TABLES = ('posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'comments', 'commentmeta', 'options')
SAFE_OPTIONS = {'home', 'siteurl', 'permalink_structure', 'timezone_string', 'gmt_offset', 'uploads_use_yearmonth_folders'}
ESCAPES = {ord('0'): b'\0', ord('n'): b'\n', ord('r'): b'\r', ord('t'): b'\t', ord('b'): b'\b', ord('Z'): b'\x1a'}

def rows(data):
    """Parse only literal VALUES rows; reject expressions rather than evaluate SQL."""
    i = 0
    while i < len(data):
        if data[i:i+1] != b'(':
            raise ValueError('VALUESの開始位置が不正です')
        i += 1
        row = []
        while True:
            if data[i:i+1] == b"'":
                i += 1
                value = bytearray()
                while True:
                    if i >= len(data):
                        raise ValueError('文字列が途中で終了しています')
                    c = data[i]
                    i += 1
                    if c == 92:
                        if i >= len(data):
                            raise ValueError('エスケープが途中で終了しています')
                        c = data[i]; i += 1
                        value.extend(ESCAPES.get(c, bytes([c])))
                    elif c == 39:
                        if data[i:i+1] == b"'":
                            value.append(39); i += 1
                        else:
                            break
                    else:
                        value.append(c)
                # Never replace invalid bytes. Store as SQLite BLOB if non-UTF8.
                try:
                    value = value.decode('utf-8')
                except UnicodeDecodeError:
                    value = bytes(value)
            else:
                end = i
                while end < len(data) and data[end] not in (44, 41):
                    end += 1
                token = data[i:end]; i = end
                if token == b'NULL':
                    value = None
                elif re.fullmatch(rb'-?\d+', token):
                    value = int(token)
                    if not -(2**63) <= value < 2**63:
                        value = token.decode('ascii')
                elif re.fullmatch(rb'0x[0-9a-fA-F]*', token):
                    value = bytes.fromhex(token[2:].decode('ascii'))
                elif re.fullmatch(rb'-?\d+\.\d+(?:[eE][+-]?\d+)?', token):
                    value = token.decode('ascii')  # preserve decimal precision
                else:
                    raise ValueError('未対応のSQLリテラルです')
            row.append(value)
            delimiter = data[i:i+1]; i += 1
            if delimiter == b')':
                break
            if delimiter != b',':
                raise ValueError('VALUES区切りが不正です')
        yield row
        if i < len(data):
            if data[i:i+1] != b',':
                raise ValueError('行区切りが不正です')
            i += 1
            if i == len(data):
                raise ValueError('末尾の行がありません')


def inspect(source, output, prefix):
    if not re.fullmatch(r'[A-Za-z0-9_]+', prefix):
        raise ValueError('テーブル接頭辞が不正です')
    if output.exists():
        raise ValueError('出力先が存在するため上書きしません')
    data = source.read_bytes()
    source_sha = hashlib.sha256(data).hexdigest()
    if data[:2] == b'\x1f\x8b':
        data = gzip.decompress(data)
    schemas = {}
    for m in re.finditer(rb'^CREATE TABLE `([^`]+)` \((.*?)^\)', data, re.M | re.S):
        name = m[1].decode('ascii')
        if name in {prefix+t for t in TABLES}:
            schemas[name] = [c.decode('ascii') for c in re.findall(rb'^  `([A-Za-z0-9_]+)`', m[2], re.M)]
    if prefix+'posts' not in schemas or prefix+'options' not in schemas:
        raise ValueError('対象の投稿と設定テーブルがありません')
    output.parent.mkdir(parents=True, exist_ok=True)
    fd = os.open(output, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600); os.close(fd)
    db = sqlite3.connect(output)
    try:
        totals = collections.Counter(); binary = collections.Counter(); seen = collections.Counter()
        for name, columns in schemas.items():
            db.execute('CREATE TABLE "'+name+'" ('+','.join('"'+c+'"' for c in columns)+')')
        for m in re.finditer(rb'^INSERT INTO `([^`]+)` VALUES (.*);\r?$', data, re.M):
            name = m[1].decode('ascii')
            if name not in schemas:
                continue
            seen[name] += 1
            columns = schemas[name]
            for row in rows(m[2]):
                if len(row) != len(columns):
                    raise ValueError('列数が一致しません: '+name)
                if name == prefix+'options' and row[columns.index('option_name')] not in SAFE_OPTIONS:
                    continue
                db.execute('INSERT INTO "'+name+'" VALUES ('+','.join('?' for _ in row)+')',row)
                totals[name] += 1
                binary[name] += sum(isinstance(v, bytes) for v in row)
        for name in schemas:
            if len(re.findall(rb'^INSERT INTO `'+name.encode()+rb'`', data, re.M)) != seen[name]:
                raise ValueError('未対応のINSERT形式: '+name)
        options = dict(db.execute('SELECT option_name,option_value FROM "'+prefix+'options"'))
        counts = [dict(zip(('type','status','count'),r)) for r in db.execute('SELECT post_type,post_status,count(*) FROM "'+prefix+'posts" GROUP BY post_type,post_status')]
        protected = db.execute('SELECT count(*) FROM "'+prefix+'posts" WHERE post_password != ?',('',)).fetchone()[0]
        db.commit()
        return {'source_sha256':source_sha,'prefix':prefix,'rows':dict(totals),'binary_cells':dict(binary),'options':options,'post_counts':counts,'password_protected_records':protected,'published':False,'stage':'private-extraction-only'}
    except Exception:
        db.rollback(); db.close(); output.unlink(missing_ok=True)
        raise
    finally:
        db.close()

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='WordPress SQLを実行せず移行用の非公開SQLiteへ抽出')
    parser.add_argument('source',type=Path)
    parser.add_argument('--prefix',required=True)
    parser.add_argument('--output',required=True,type=Path)
    args = parser.parse_args()
    print(json.dumps(inspect(args.source,args.output,args.prefix),ensure_ascii=False,indent=2))
