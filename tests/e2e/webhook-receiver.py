"""The owner's webhook, for the end-to-end tests (D-35): HTTPS, a certificate from the test's own
authority, and each request's signature checked as a receiver would check it. Each request is
recorded as one JSON line in /tmp/hooks.log; one whose signature doesn't hold is answered 401.

    WEBHOOK_SECRET=… python3 webhook-receiver.py cert.pem key.pem
"""

import hashlib
import hmac
import http.server
import json
import os
import ssl
import sys

SECRET = os.environ['WEBHOOK_SECRET'].encode()
LOG = '/tmp/hooks.log'


class Receiver(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
        expected = 'sha256=' + hmac.new(SECRET, body, hashlib.sha256).hexdigest()
        valid = hmac.compare_digest(expected, self.headers.get('X-Quire-Signature-256', ''))
        try:
            data = json.loads(body)
        except ValueError:
            data = {}
        record = {
            'valid': valid,
            'path': self.path,
            'type': self.headers.get('Content-Type'),
            'event': data.get('event'),
            'server': data.get('server'),
            'sent_at': data.get('sent_at'),
            'subject': str(data.get('text', '')).split('\n', 1)[0],
            'problems': [{'event': p.get('event'), 'key': p.get('key')} for p in data.get('problems') or []],
        }
        with open(LOG, 'a') as log:
            log.write(json.dumps(record) + '\n')
        self.send_response(200 if valid else 401)
        self.end_headers()

    def log_message(self, *args):
        pass


server = http.server.ThreadingHTTPServer(('', 443), Receiver)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(sys.argv[1], sys.argv[2])
server.socket = context.wrap_socket(server.socket, server_side=True)
server.serve_forever()
