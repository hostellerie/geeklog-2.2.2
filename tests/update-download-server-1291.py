import http.server
import pathlib
import ssl
import sys

payload = pathlib.Path(sys.argv[1]).read_bytes()

class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path == '/redirect.zip':
            self.send_response(302)
            self.send_header('Location', 'https://localhost/good.zip')
            self.end_headers()
            return
        bodies = {'/good.zip': payload, '/short.zip': payload[:-1],
                  '/long.zip': payload + b'x', '/bad.zip': b'x' + payload[1:]}
        body = bodies.get(self.path)
        self.send_response(200 if body is not None else 404)
        self.end_headers()
        if body is not None:
            self.wfile.write(body)

server = http.server.HTTPServer(('127.0.0.1', 443), Handler)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(sys.argv[2], sys.argv[3])
server.socket = context.wrap_socket(server.socket, server_side=True)
server.serve_forever()
