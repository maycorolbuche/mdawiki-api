"""Real PHP HTTP/session/SMTP integration against the isolated CI MySQL database."""
import email
from email import policy
import http.cookiejar
import json
import os
from pathlib import Path
import socketserver
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.request

messages = []

class SMTP(socketserver.StreamRequestHandler):
    def handle(self):
        self.wfile.write(b"220 local.test ESMTP\r\n")
        while line := self.rfile.readline():
            command = line.split(b" ", 1)[0].strip().upper()
            if command in (b"EHLO", b"HELO"):
                self.wfile.write(b"250-local.test\r\n250 8BITMIME\r\n")
            elif command == b"DATA":
                self.wfile.write(b"354 End with dot\r\n")
                body = []
                while (part := self.rfile.readline()) not in (b".\r\n", b""):
                    body.append(part)
                messages.append(b"".join(body).decode("utf-8", "replace"))
                self.wfile.write(b"250 Queued\r\n")
            elif command == b"QUIT":
                self.wfile.write(b"221 Bye\r\n")
                return
            else:
                self.wfile.write(b"250 OK\r\n")

smtp = socketserver.ThreadingTCPServer(("127.0.0.1", 0), SMTP)
threading.Thread(target=smtp.serve_forever, daemon=True).start()
cookies = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))
origin = "https://site.mda.wiki.br"
base = "http://127.0.0.1:8089"

def request(path, method="GET", data=None, token="", request_origin=origin):
    headers = {"Origin": request_origin, "Accept": "application/json"}
    if data is not None:
        headers.update({"Content-Type": "application/json", "X-CSRF-Token": token})
    req = urllib.request.Request(base + path, data=None if data is None else json.dumps(data).encode(), headers=headers, method=method)
    try:
        response = client.open(req, timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    body = response.read()
    return response.status, response.headers, json.loads(body) if "application/json" in response.headers.get("Content-Type", "") else body

with tempfile.TemporaryDirectory(prefix="mdawiki-http-") as storage:
    env = dict(os.environ, APP_KEY="test-key-" * 8, ALLOWED_ORIGINS=origin, FRONTEND_URL=origin,
               DB_HOST="127.0.0.1", DB_PORT="3306", DB_DATABASE="mdawiki_test", DB_USERNAME="root", DB_PASSWORD="test", DB_CHARSET="latin1",
               SESSION_SECURE="false", STORAGE_DIR=storage, MEDIA_ROOT=storage + "/media", MEDIA_PUBLIC_URL=base + "/media",
               SMTP_HOST="127.0.0.1", SMTP_PORT=str(smtp.server_address[1]), SMTP_SECURE="none", SMTP_USERNAME="", SMTP_PASSWORD="", SMTP_FROM="ci@example.test")
    with open(storage + "/server.log", "wb") as log:
        server = subprocess.Popen(["php", "-d", "display_errors=1", "-S", "127.0.0.1:8089", "-t", "public", "public/router.php"], env=env, stdout=log, stderr=log)
        try:
            for _ in range(50):
                try:
                    if request("/health")[0] == 200:
                        break
                except OSError:
                    time.sleep(.1)
            status, headers, data = request("/v1/auth/session")
            assert status == 200 and data["data"]["user"] is None
            assert "httponly" in headers["Set-Cookie"].lower()
            assert headers["Access-Control-Allow-Origin"] == origin
            assert headers["Access-Control-Allow-Credentials"] == "true"
            old_cookie = list(cookies)[0].value
            csrf = data["data"]["csrf_token"]
            status, headers, data = request("/v1/auth/login", "POST", {"email": "admin@example.test", "password": "old-password"}, csrf)
            assert status == 200, data
            assert list(cookies)[0].value != old_cookie, "Session fixation prevention"
            csrf = data["data"]["csrf_token"]
            assert request("/v1/profile")[2]["data"]["EMAIL"] == "admin@example.test"
            assert request("/v1/profile", "PUT", {"NOME": "Denied"}, "bad")[0] == 403
            assert request("/v1/profile", request_origin="https://evil.test")[0] == 403
            assert request("/v1/view/Privada")[2]["data"]["is_public"] is False
            assert request("/v1/pages/Privada")[0] == 404
            editor_path = "/v1/pages/Teste-Resposta-Json"
            assert request(editor_path)[0] == 404
            status, _, editor = request(editor_path + "/editor")
            assert status == 200, editor
            # Block the lock directory to force an fopen warning on save.
            lock_directory = Path(storage) / "locks"
            lock_directory.write_text("cannot use this as a directory")
            status, _, result = request(editor_path, "PUT", {
                "title": "Teste Resposta Json",
                "source": "Conteúdo de teste",
                "base_version": editor["data"]["base_version"],
            }, csrf)
            assert status == 503 and result["error"]["code"] == "service_unavailable", result
            lock_directory.unlink()
            assert request("/v1/auth/logout", "POST", {}, csrf)[0] == 200
            assert request("/v1/profile")[0] == 401
            csrf = request("/v1/auth/session")[2]["data"]["csrf_token"]
            status, _, result = request("/v1/auth/register", "POST", {"name": "HTTP Test", "email": "http@example.test", "password": "http-password-123", "password_confirmation": "http-password-123"}, csrf)
            assert status == 200, result
            assert len(messages) == 1
            message = email.message_from_string(messages[0], policy=policy.default)
            assert "site.mda.wiki.br/page@AtivaConta" in message.get_body(preferencelist=("plain",)).get_content()
            assert b"<?php" not in request("/media/wiki/..%2F..%2F..%2Fconfig%2Fbootstrap.php")[2]
            print("Native PHP HTTP, cookies, CSRF, CORS, private reads and SMTP integration passed.")
        finally:
            server.terminate()
            server.wait(timeout=10)
            smtp.shutdown()
