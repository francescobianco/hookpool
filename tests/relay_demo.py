#!/usr/bin/env python3
"""
HTTP Relay Demo
===============
Demonstrates the HTTP Relay special function of Hookpool.

Usage:
    python3 relay_demo.py <webhook_url> [local_port]

Arguments:
    webhook_url   Full URL of the Hookpool webhook endpoint
                  e.g. https://hookpool.example.com/hook?token=abc&project=myproj
    local_port    Port for the local demo server (default: 9876)

What it does:
    1. Starts a local demo HTTP server on the given port.
    2. Runs a relay client that long-polls the webhook via PATCH.
    3. When a request arrives on the webhook public side (any method except
       PATCH), it is forwarded to the local demo server and the response is
       returned to the original caller — transparently.

Once running, call the webhook with any non-PATCH method to see the relay
in action:

    curl -X POST '<webhook_url>' -H 'Content-Type: application/json' \\
         -d '{"hello":"world"}'

The response will be served by the local demo server running in this process.
"""

import sys
import json
import time
import base64
import signal
import threading
import urllib.error
import urllib.parse
import urllib.request
from http.server import BaseHTTPRequestHandler, HTTPServer

# ── Configuration ─────────────────────────────────────────────────────────────

RELAY_POLL_TIMEOUT = 33   # seconds; must be > server-side RELAY_POLL_TIMEOUT
RETRY_DELAY        = 3    # seconds to wait after a connection error
DEFAULT_LOCAL_PORT = 9876

# Headers that must not be forwarded as-is to the local server
_STRIP_FORWARD_HEADERS = {
    'HOST', 'CONTENT-LENGTH', 'TRANSFER-ENCODING',
    'CONNECTION', 'KEEP-ALIVE', 'UPGRADE',
}

# Headers that must not be forwarded back to the public caller
_STRIP_RESPONSE_HEADERS = {
    'TRANSFER-ENCODING', 'CONNECTION', 'KEEP-ALIVE',
}

# ── Local demo HTTP server ─────────────────────────────────────────────────────


class DemoHandler(BaseHTTPRequestHandler):
    """
    A minimal demo server that echoes back a JSON summary of every request it
    receives.  In a real deployment this would be replaced by an actual
    application server (e.g. FastAPI, Django, Express …).
    """

    def do_GET(self):    self._handle()
    def do_POST(self):   self._handle()
    def do_PUT(self):    self._handle()
    def do_DELETE(self): self._handle()
    def do_HEAD(self):   self._handle()
    def do_OPTIONS(self):self._handle()

    def _handle(self):
        length = int(self.headers.get('Content-Length', 0) or 0)
        raw_body = self.rfile.read(length) if length > 0 else b''

        try:
            body_text = raw_body.decode('utf-8')
        except UnicodeDecodeError:
            body_text = f'<binary {len(raw_body)} bytes>'

        payload = {
            'demo':     'HTTP Relay is working!',
            'received': {
                'method':  self.command,
                'path':    self.path,
                'headers': dict(self.headers),
                'body':    body_text or None,
            },
            'server': f'Local demo server on port {self.server.server_port}',
        }

        data = json.dumps(payload, indent=2, ensure_ascii=False).encode('utf-8')

        self.send_response(200)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(data)))
        self.send_header('X-Served-By', 'hookpool-relay-demo')
        self.end_headers()
        self.wfile.write(data)

    def log_message(self, fmt, *args):
        _log('demo-server', fmt % args)


# ── Relay client ───────────────────────────────────────────────────────────────


def _forward_to_local(local_base: str, payload: dict) -> dict:
    """
    Reconstruct the HTTP request described in *payload* and send it to the
    local server at *local_base*.  Returns a response payload dict.
    """
    method      = payload.get('method', 'GET').upper()
    path        = payload.get('path', '/')
    qs          = payload.get('query_string', '')
    fwd_headers = payload.get('headers', {})
    body_raw    = payload.get('body', '')
    is_b64      = payload.get('body_base64', False)

    if is_b64:
        body_bytes = base64.b64decode(body_raw)
    else:
        body_bytes = body_raw.encode('utf-8') if body_raw else b''

    target = local_base.rstrip('/') + (path or '/')
    if qs:
        target += '?' + qs

    _log('relay', f'Forwarding {method} {target}')

    # Strip hop-by-hop headers before forwarding
    clean_headers = {
        k: v for k, v in fwd_headers.items()
        if k.upper() not in _STRIP_FORWARD_HEADERS
    }

    try:
        req = urllib.request.Request(
            target,
            data=body_bytes or None,
            headers=clean_headers,
            method=method,
        )
        with urllib.request.urlopen(req, timeout=15) as resp:
            status       = resp.status
            resp_headers = {k: v for k, v in resp.headers.items()
                            if k.upper() not in _STRIP_RESPONSE_HEADERS}
            resp_bytes   = resp.read()

    except urllib.error.HTTPError as exc:
        # HTTPError carries a valid HTTP response — pass it through
        status       = exc.code
        resp_headers = dict(exc.headers) if exc.headers else {}
        resp_bytes   = exc.read() if exc.fp else b''

    except Exception as exc:
        _log('relay', f'Local server error: {exc} — returning 502')
        status       = 502
        resp_headers = {'Content-Type': 'application/json'}
        resp_bytes   = json.dumps({
            'error':  'relay: local server error',
            'detail': str(exc),
        }).encode('utf-8')

    # Encode body: try UTF-8, fall back to base64 for binary payloads
    try:
        body_str = resp_bytes.decode('utf-8')
        body_b64 = False
    except UnicodeDecodeError:
        body_str = base64.b64encode(resp_bytes).decode('ascii')
        body_b64 = True

    return {
        'status':      status,
        'headers':     resp_headers,
        'body':        body_str,
        'body_base64': body_b64,
    }


def relay_client(webhook_url: str, local_base: str, stop_event: threading.Event):
    """
    Long-polling relay client loop.

    Each iteration sends one PATCH request that simultaneously:
      - delivers the response from the previous transaction (if any), and
      - polls for the next incoming public request.

    The server either:
      - responds immediately with a pending request payload + X-Relay-Seq, or
      - holds the connection open until a request arrives, or
      - returns 204 No Content on poll timeout (client must reconnect).
    """
    pending_seq:      int | None  = None
    pending_response: dict | None = None

    _log('relay', f'Starting — webhook: {webhook_url}')
    _log('relay', f'Forwarding to:     {local_base}')

    while not stop_event.is_set():
        headers: dict = {'Accept': 'application/json'}
        body_bytes: bytes = b''

        if pending_seq is not None and pending_response is not None:
            # Carry the response for the previous transaction
            body_bytes = json.dumps(pending_response, ensure_ascii=False).encode('utf-8')
            headers['Content-Type']  = 'application/json; charset=utf-8'
            headers['Content-Length'] = str(len(body_bytes))
            headers['X-Relay-Seq']   = str(pending_seq)
            _log('relay', f'Delivering response seq={pending_seq} + polling for next')
        else:
            _log('relay', 'Polling for next public request…')

        try:
            req = urllib.request.Request(
                webhook_url,
                data=body_bytes or None,
                headers=headers,
                method='PATCH',
            )
            with urllib.request.urlopen(req, timeout=RELAY_POLL_TIMEOUT) as resp:
                seq_header = resp.headers.get('X-Relay-Seq', '0')
                resp_body  = resp.read()

                # 204 → poll timeout, no request arrived; reconnect cleanly
                if resp.status == 204 or not resp_body or seq_header == '0':
                    _log('relay', 'Poll timeout — reconnecting')
                    pending_seq      = None
                    pending_response = None
                    continue

                try:
                    payload = json.loads(resp_body)
                except json.JSONDecodeError:
                    _log('relay', 'Malformed payload received — reconnecting')
                    pending_seq      = None
                    pending_response = None
                    continue

                _log('relay', f'Received request seq={seq_header} '
                              f'{payload.get("method","?")} {payload.get("path","?")}')

                pending_seq      = int(seq_header)
                pending_response = _forward_to_local(local_base, payload)

                _log('relay',
                     f'Local server → {pending_response["status"]}, '
                     f'delivering on next PATCH')

        except urllib.error.HTTPError as exc:
            _log('relay', f'HTTP {exc.code} — retrying in {RETRY_DELAY}s')
            pending_seq      = None
            pending_response = None
            stop_event.wait(RETRY_DELAY)

        except Exception as exc:
            _log('relay', f'Connection error: {exc} — retrying in {RETRY_DELAY}s')
            pending_seq      = None
            pending_response = None
            stop_event.wait(RETRY_DELAY)


# ── Helpers ───────────────────────────────────────────────────────────────────


def _log(component: str, msg: str) -> None:
    ts = time.strftime('%H:%M:%S')
    print(f'[{ts}] [{component}] {msg}', flush=True)


# ── Entry point ────────────────────────────────────────────────────────────────


def main() -> None:
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(1)

    webhook_url = sys.argv[1]
    local_port  = int(sys.argv[2]) if len(sys.argv) > 2 else DEFAULT_LOCAL_PORT
    local_base  = f'http://127.0.0.1:{local_port}'

    # ── Start local demo server ────────────────────────────────────────────────
    httpd = HTTPServer(('127.0.0.1', local_port), DemoHandler)
    httpd.server_port = local_port  # expose for DemoHandler log message
    server_thread = threading.Thread(target=httpd.serve_forever, daemon=True,
                                     name='demo-server')
    server_thread.start()
    _log('demo-server', f'Listening on {local_base}')

    # ── Start relay client ─────────────────────────────────────────────────────
    stop_event = threading.Event()
    relay_thread = threading.Thread(
        target=relay_client,
        args=(webhook_url, local_base, stop_event),
        daemon=True,
        name='relay-client',
    )
    relay_thread.start()

    # ── Banner ─────────────────────────────────────────────────────────────────
    sep = '─' * 62
    print()
    print(sep)
    print('  HTTP Relay Demo — running')
    print(sep)
    print(f'  Webhook URL  : {webhook_url}')
    print(f'  Local server : {local_base}')
    print()
    print('  Call the webhook with any non-PATCH method:')
    print()
    print(f'    curl -X POST \'{webhook_url}\' \\')
    print( '         -H \'Content-Type: application/json\' \\')
    print( '         -d \'{"hello":"world"}\'')
    print()
    print('  The response will be served by the local demo server.')
    print('  Press Ctrl+C to stop.')
    print(sep)
    print()

    # ── Wait for Ctrl+C ────────────────────────────────────────────────────────
    def _shutdown(sig, frame):
        print()
        _log('main', 'Shutting down…')
        stop_event.set()
        httpd.shutdown()
        sys.exit(0)

    signal.signal(signal.SIGINT,  _shutdown)
    signal.signal(signal.SIGTERM, _shutdown)

    # Keep the main thread alive
    while not stop_event.is_set():
        time.sleep(0.5)


if __name__ == '__main__':
    main()
