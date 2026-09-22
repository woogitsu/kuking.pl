from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import http.client
class Proxy(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'
    def forward(self):
        data = self.rfile.read(int(self.headers.get('Content-Length', 0)))
        conn = http.client.HTTPConnection('127.0.0.1', 8118, timeout=120)
        headers = dict(self.headers)
        headers['Connection'] = 'close'
        conn.request(self.command, self.path, data, headers)
        resp = conn.getresponse()
        body = resp.read()
        self.send_response(resp.status)
        for key, value in resp.getheaders():
            if key.lower() not in ('connection', 'transfer-encoding', 'content-length'):
                self.send_header(key, value)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)
        conn.close()
    do_GET = forward
    do_POST = forward
    def log_message(self, *args): pass
ThreadingHTTPServer(('192.168.0.242', 8119), Proxy).serve_forever()