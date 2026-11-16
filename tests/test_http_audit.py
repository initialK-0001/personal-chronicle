import importlib.util
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

spec = importlib.util.spec_from_file_location('audit_http', Path(__file__).resolve().parents[1] / 'scripts/audit-http.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class AuditTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.requests = []
        class Handler(BaseHTTPRequestHandler):
            def do_GET(self):
                cls.requests.append(self.path)
                status, mime, content = 200, 'text/html', b''
                if self.path in ['/api/state', '/api/admin/contact', '/admin', '/admin/contact']:
                    self.send_response(303 if self.path.startswith('/admin') else 401)
                    if self.path.startswith('/admin'):
                        self.send_header('Location', '/login')
                    self.end_headers()
                    return
                if self.path == '/redirect':
                    self.send_response(302)
                    self.send_header('Location', 'https://external.invalid/never')
                    self.end_headers()
                    return
                if self.path == '/picture.png':
                    mime, content = 'image/png', b'fixture-image'
                elif self.path == '/?wpdmdl=7':
                    mime, content = 'application/octet-stream', b'fixture-download'
                else:
                    target = '/other' if self.path == '/wrong' else self.path
                    content = (f'<h1>記事</h1><link rel="canonical" href="https://brand-new.tokyo{target}">'
                               '<img src="/picture.png"><img src="https://external.invalid/image.png">'
                               '<a href="/?wpdmdl=7">配布</a>').encode()
                self.send_response(status)
                self.send_header('Content-Type', mime)
                self.send_header('X-Robots-Tag', 'noindex, nofollow')
                if self.path == '/?wpdmdl=7':
                    self.send_header('Content-Disposition', 'attachment; filename="pose.json"')
                self.end_headers()
                self.wfile.write(content)
            def log_message(self, *args):
                pass
        cls.server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()
        cls.base = f'http://127.0.0.1:{cls.server.server_port}'

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.thread.join()

    def check(self, path, **kwargs):
        return m.audit(self.base, 'https://brand-new.tokyo', [{'url': 'https://brand-new.tokyo'+path}], local=True, **kwargs)

    def test_pages_and_deduplicated_media(self):
        result = self.check('/article', media=True)
        self.assertEqual(result['failed'], 0)
        self.assertEqual(result['media_candidates'], 2)
        self.assertEqual(result['external_media_not_fetched'], 1)
        self.assertEqual(len(result['results']), 7)
        self.assertEqual(result['results'][5]['sha256'], m.hashlib.sha256(b'fixture-download').hexdigest())
        self.assertNotIn('/never', self.requests)

    def test_canonical_and_indexing_fail(self):
        self.assertEqual(self.check('/wrong')['failed'], 1)
        self.assertEqual(self.check('/article', indexing='on')['failed'], 1)

    def test_redirect_not_followed(self):
        result = self.check('/redirect')
        self.assertEqual(result['results'][0]['status'], 302)
        self.assertEqual(result['failed'], 1)

    def test_origin_and_url_validation(self):
        for value in ['http://example.com', 'https://user:secret@example.com', 'https://example.com/path', 'https://example.com?token=value']:
            with self.assertRaises(ValueError):
                m.origin(value)
        self.assertIsNone(m.local_path('https://other.invalid/image.png', {('https', 'brand-new.tokyo')}))
        self.assertIsNone(m.local_path('data:image/png;base64,AAA', set()))
        self.assertEqual(m.normalized('https://brand-new.tokyo/%E7%94%BB%E5%83%8F'), m.normalized('https://brand-new.tokyo/画像'))


if __name__ == '__main__':
    unittest.main()
