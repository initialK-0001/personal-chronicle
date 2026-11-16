#!/usr/bin/env python3
"""Read-only HTTP migration audit; no login, cookies, writes or external crawling."""
import argparse
import hashlib
import json
import sys
import urllib.error
import urllib.request
from html.parser import HTMLParser
from urllib.parse import quote, unquote, urljoin, urlsplit, urlunsplit


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Page(HTMLParser):
    def __init__(self):
        super().__init__()
        self.canonicals = []
        self.images = []
        self.downloads = []
        self.heading = False

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'link' and 'canonical' in a.get('rel', '').split():
            self.canonicals.append(a.get('href', ''))
        if tag == 'img' and a.get('src'):
            self.images.append(a['src'])
        if tag == 'a' and 'wpdmdl=' in a.get('href', ''):
            self.downloads.append(a['href'])
        if tag == 'h1':
            self.heading = True


def origin(value, local=False):
    u = urlsplit(value)
    if u.username or u.password or u.query or u.fragment or u.path not in ('', '/'):
        raise ValueError('接続先は認証情報やパスを含まないURLにしてください')
    if not u.hostname or (u.scheme != 'https' and not (local and u.scheme == 'http' and u.hostname == '127.0.0.1')):
        raise ValueError('HTTPSの接続先を指定してください ローカル試験のみ明示指定でHTTPを許可します')
    return value.rstrip('/')


def normalized(value):
    u = urlsplit(value)
    return u.scheme, u.netloc, unquote(u.path) or '/', u.query


def local_path(value, allowed_origins):
    u = urlsplit(value)
    if u.username or u.password:
        return None
    if u.netloc and (u.scheme, u.netloc) not in allowed_origins:
        return None
    if u.scheme and u.scheme not in ('https', 'http'):
        return None
    return urlunsplit(('', '', quote(unquote(u.path or '/'), safe='/'), u.query, ''))


def fetch(opener, url, limit):
    request = urllib.request.Request(url, headers={'User-Agent': 'BrandNewTokyo-MigrationAudit/1.0', 'Accept-Encoding': 'identity'})
    try:
        response = opener.open(request, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        content = response.read(limit + 1)
        if len(content) > limit:
            raise ValueError('応答容量が検証上限を超えました')
        return response.status, response.headers, content


def audit(base, canonical, inventory, indexing='off', media=False, local=False):
    base = origin(base, local)
    canonical = origin(canonical, local)
    sources = {(urlsplit(e['url']).scheme, urlsplit(e['url']).netloc) for e in inventory}
    if len(sources) != 1:
        raise ValueError('台帳の公開元が一つではありません')
    sources |= {(scheme, host) for _, host in sources for scheme in ('http', 'https')}
    allowed = sources | {(urlsplit(base).scheme, urlsplit(base).netloc), (urlsplit(canonical).scheme, urlsplit(canonical).netloc)}
    opener = urllib.request.build_opener(NoRedirect())
    results = []
    assets = {}
    external = set()
    paths = set()
    for entry in inventory:
        path = local_path(entry['url'], allowed)
        if path is None or path in paths:
            raise ValueError('台帳のURLが不正または重複しています')
        paths.add(path)
        row = {'kind': 'page', 'path': path, 'errors': []}
        try:
            status, headers, content = fetch(opener, base + path, 8 * 1024 * 1024)
            row['status'] = status
            if status != 200:
                row['errors'].append('HTTP 200ではありません 転送も要確認です')
            if headers.get_content_type() != 'text/html':
                row['errors'].append('HTMLではありません')
            page = Page()
            page.feed(content.decode('utf-8', errors='strict'))
            if not page.heading:
                row['errors'].append('見出しがありません')
            if len(page.canonicals) != 1 or normalized(page.canonicals[0]) != normalized(canonical + path):
                row['errors'].append('正規URLが一致しません')
            robots = headers.get('X-Robots-Tag', '').replace(' ', '').lower().split(',')
            if ('noindex' if indexing == 'off' else 'index') not in robots or (indexing == 'on' and 'noindex' in robots):
                row['errors'].append('検索登録の設定が一致しません')
            for kind, values in [('image', page.images + entry.get('images', [])), ('download', page.downloads)]:
                for value in values:
                    resolved = urljoin(base + path, value)
                    asset = local_path(resolved, allowed)
                    if asset is None:
                        external.add(resolved)
                    else:
                        assets[asset] = kind
        except (OSError, ValueError, UnicodeError) as error:
            # Do not print response bodies, credentials, or server exception details.
            row['errors'].append('通信または応答解析に失敗しました ' + type(error).__name__)
        results.append(row)
        if len(results) % 25 == 0:
            print(f'記事確認 {len(results)}/{len(inventory)}', file=sys.stderr, flush=True)
    # Anonymous reads must never expose editor state or the private contact inbox.
    for path, expected in [('/api/state', 401), ('/api/admin/contact', 401), ('/admin', 303), ('/admin/contact', 303)]:
        row = {'kind': 'access', 'path': path, 'errors': []}
        try:
            status, headers, content = fetch(opener, base + path, 8 * 1024 * 1024)
            row['status'] = status
            if status != expected:
                row['errors'].append('未ログイン時の拒否が確認できません')
            if expected == 303 and headers.get('Location') != '/login':
                row['errors'].append('ログイン画面への転送先が一致しません')
        except (OSError, ValueError) as error:
            row['errors'].append('通信に失敗しました ' + type(error).__name__)
        results.append(row)
    if media:
        for path, kind in sorted(assets.items()):
            row = {'kind': kind, 'path': path, 'errors': []}
            try:
                status, headers, content = fetch(opener, base + path, 64 * 1024 * 1024)
                row.update(status=status, bytes=len(content))
                if status != 200 or not content:
                    row['errors'].append('取得できないか空ファイルです')
                if kind == 'image' and not headers.get_content_type().startswith('image/'):
                    row['errors'].append('画像形式ではありません')
                if kind == 'download' and not headers.get('Content-Disposition', '').lower().startswith('attachment'):
                    row['errors'].append('配布ファイルの応答ではありません')
                if not row['errors']:
                    row['sha256'] = hashlib.sha256(content).hexdigest()
            except (OSError, ValueError) as error:
                row['errors'].append('通信または容量確認に失敗しました ' + type(error).__name__)
            results.append(row)
    return {'base': base, 'indexing': indexing, 'pages': len(inventory), 'media_checked': media,
            'media_candidates': len(assets), 'external_media_not_fetched': len(external),
            'failed': sum(bool(r['errors']) for r in results), 'results': results}


if __name__ == '__main__':
    p = argparse.ArgumentParser(description='配備先の旧URLと画像・配布ファイルを読み取り専用で検証')
    p.add_argument('--base', required=True)
    p.add_argument('--canonical-origin', required=True)
    p.add_argument('--inventory', required=True)
    p.add_argument('--indexing', choices=['on', 'off'], default='off')
    p.add_argument('--media', action='store_true')
    p.add_argument('--local-test', action='store_true')
    a = p.parse_args()
    try:
        with open(a.inventory) as f:
            result = audit(a.base, a.canonical_origin, json.load(f), a.indexing, a.media, a.local_test)
        print(json.dumps(result, ensure_ascii=False, indent=2))
        sys.exit(bool(result['failed']))
    except (ValueError, OSError) as error:
        print('検証を開始できません ' + str(error), file=sys.stderr)
        sys.exit(2)
