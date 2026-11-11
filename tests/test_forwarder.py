#!/usr/bin/env python3
"""Recette hors réseau : pont Hostinger, plusieurs SIM, HMAC, TLS et reprises.

N'exécute jamais le webhook de production. Utilise des secrets et SMS factices
dans un répertoire temporaire, et un faux relais HTTPS sur 127.0.0.1.
"""
from __future__ import annotations

import hashlib
import hmac
import http.server
import json
import os
from pathlib import Path
import shutil
import sqlite3
import ssl
import subprocess
import tempfile
import threading
from datetime import datetime, timezone


SECRETS = {"tel_sim1": "s" * 43, "tel_sim2": "t" * 43}
SLOTS = {"SIM1": "tel_sim1", "SIM2": "tel_sim2"}
SOURCE = Path(__file__).resolve().parents[1] / "payment-forwarder.php"


class FakeRelais(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        assert self.path == "/api/relais/v1/sms", self.path
        data = self.rfile.read(int(self.headers.get("Content-Length", "0")))
        terminal = self.headers.get("X-Relais", "")
        timestamp = self.headers.get("X-Horodatage", "")
        signature = self.headers.get("X-Signature", "")
        secret = SECRETS.get(terminal, "")
        expected = hmac.new(
            secret.encode("utf8"),
            (timestamp + "\nPOST\n" + self.path + "\n").encode("utf8") + data,
            hashlib.sha256,
        ).hexdigest()
        message = json.loads(data)
        valid = (
            terminal in SECRETS
            and hmac.compare_digest(expected, signature)
            and SLOTS.get(message.get("sim")) == terminal
            and isinstance(message.get("id"), str)
            and isinstance(message.get("recu_le"), str)
            and timestamp.isdigit()
        )
        self.server.records.append((terminal, message, valid))
        if not valid:
            self.send_response(401)
            response = {"ok": False}
        elif "RETRY_TEST" in message["contenu"] and not self.server.failed_once:
            self.server.failed_once = True
            self.send_response(503)
            response = {"ok": False}
        else:
            self.send_response(201)
            response = {"ok": True, "id": message["id"]}
        body = json.dumps(response).encode()
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, fmt, *args):
        # Ne pas afficher les corps ni les en-têtes dans la CI.
        pass


def run_checked(command, **kwargs):
    r = subprocess.run(command, capture_output=True, text=True, check=False, **kwargs)
    if r.returncode:
        raise AssertionError(
            "Commande de recette en échec (code %d) : %s" % (r.returncode, r.stderr[-800:])
        )
    return r


def statuses(db):
    return dict(db.execute("SELECT sms_id, statut FROM sms_paiement_transmissions").fetchall())


def main():
    assert SOURCE.is_file(), "Script PHP introuvable"
    with tempfile.TemporaryDirectory(prefix="studioaty-sms-recette-") as temp:
        root = Path(temp)
        web = root / "public_html" / "api" / "sms"
        private = root / "studioaty_sms_private"
        web.mkdir(parents=True)
        private.mkdir()
        bridge = web / "payment-forwarder.php"
        shutil.copy2(SOURCE, bridge)
        db_path = private / "messages.sqlite"

        connection = sqlite3.connect(db_path)
        connection.execute(
            "CREATE TABLE sms(id INTEGER PRIMARY KEY, sender TEXT NOT NULL, "
            "body TEXT NOT NULL, received_at TEXT NOT NULL, sim TEXT)"
        )
        now = datetime.now(timezone.utc).isoformat()
        messages = [
            (1, "OrangeMoney", "SMS ORANGE DE TEST 500 FCFA", now, "SIM1"),
            (2, "Moov", "SMS MOOV DE TEST 2000 FCFA", now, "SIM2"),
            (3, "OrangeMoney", "SIM INCONNUE DOIT RESTER EN ATTENTE", now, "SIM_INCONNUE"),
            (4, "OrangeMoney", "RETRY_TEST SMS ORANGE", now, "SIM1"),
        ]
        connection.executemany(
            "INSERT INTO sms(id,sender,body,received_at,sim) VALUES(?,?,?,?,?)", messages
        )
        connection.commit()

        certificate = root / "cert.pem"
        key = root / "key.pem"
        run_checked([
            "openssl", "req", "-x509", "-nodes", "-newkey", "rsa:2048",
            "-keyout", str(key), "-out", str(certificate), "-days", "1",
            "-subj", "/CN=127.0.0.1",
            "-addext", "subjectAltName=IP:127.0.0.1",
        ])

        server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), FakeRelais)
        server.records = []
        server.failed_once = False
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ctx.load_cert_chain(str(certificate), str(key))
        server.socket = ctx.wrap_socket(server.socket, server_side=True)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()

        env = os.environ.copy()
        env.update({
            "STUDIOATY_PAIEMENT_RELAIS_URL":
                f"https://127.0.0.1:{server.server_port}/api/relais/v1/sms",
            "STUDIOATY_PAIEMENT_SIM_CONFIG_JSON":
                json.dumps({
                    sim: {"terminal": terminal, "secret": SECRETS[terminal]}
                    for sim, terminal in SLOTS.items()
                }),
            "STUDIOATY_PAIEMENT_SMS_START_ID": "0",
        })
        try:
            cmd = [
                "php", "-d", f"curl.cainfo={certificate}",
                "-d", f"openssl.cafile={certificate}",
                str(bridge),
            ]
            first = run_checked(cmd, env=env)
            assert "SMS transmis: 2" in first.stdout, first.stdout
            assert statuses(connection) == {
                1: "transmis", 2: "transmis", 3: "reessayer", 4: "reessayer",
            }, statuses(connection)
            assert len(server.records) == 3, server.records
            assert all(x[2] for x in server.records), "HMAC/slot incorrect"
            assert {t for t, _, _ in server.records} == set(SECRETS), "Route SIM non isolée"
            assert not any(m.get("sim") == "SIM_INCONNUE" for _, m, _ in server.records)
            pending = [m for _, m, _ in server.records if "RETRY_TEST" in m["contenu"]]
            assert len(pending) == 1
            id_before_retry = pending[0]["id"]

            # Faire expirer artificiellement les délais sans dormir 15 minutes.
            connection.execute(
                "UPDATE sms_paiement_transmissions SET prochain_essai=0 "
                "WHERE statut='reessayer'"
            )
            connection.commit()
            second = run_checked(cmd, env=env)
            assert "SMS transmis: 1" in second.stdout, second.stdout
            assert statuses(connection) == {
                1: "transmis", 2: "transmis", 3: "reessayer", 4: "transmis",
            }, statuses(connection)
            assert len(server.records) == 4, "Un SMS transmis a été envoyé deux fois"
            retried = [m for _, m, _ in server.records if "RETRY_TEST" in m["contenu"]]
            assert len(retried) == 2 and retried[1]["id"] == id_before_retry, (
                "L'UUID a changé lors de la reprise"
            )

            third = run_checked(cmd, env=env)
            assert "SMS transmis: 0" in third.stdout
            assert len(server.records) == 4, "Une transaction acquittée a été réenvoyée"
            # Aucun texte SMS, ni secret, ne doit apparaître dans les sorties CLI.
            for output in [first.stdout, first.stderr, second.stdout, second.stderr]:
                for secret in SECRETS.values():
                    assert secret not in output
                assert "RETRY_TEST" not in output
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=5)
            connection.close()

    print("Recette PHP/SQLite/HMAC/TLS/multi-SIM/reprises : OK")


if __name__ == "__main__":
    main()
