#!/usr/bin/env python3
"""Enable TLS in icecast.xml (SSL listen socket + PEM bundle) and set <hostname>."""
from __future__ import annotations

import pathlib
import sys

SSL_BLOCK_COMMENTED = """    <!--
    <listen-socket>
        <port>8443</port>
        <ssl>1</ssl>
    </listen-socket>
    -->"""

SSL_CERT_COMMENT = """        <!-- The certificate file needs to contain both public and private part.
             Both should be PEM encoded.
        <ssl-certificate>/usr/share/icecast2/icecast.pem</ssl-certificate>
        -->"""


def main() -> int:
    if len(sys.argv) != 4:
        print("usage: patch_xml_ssl.py <icecast.xml> <hostname> <ssl-port>", file=sys.stderr)
        return 2
    path = pathlib.Path(sys.argv[1])
    hostname = sys.argv[2]
    ssl_port = sys.argv[3]
    text = path.read_text(encoding="utf-8")

    text = text.replace("<hostname>localhost</hostname>", f"<hostname>{hostname}</hostname>", 1)

    ssl_socket = f"""    <listen-socket>
        <port>{ssl_port}</port>
        <ssl>1</ssl>
    </listen-socket>"""
    if SSL_BLOCK_COMMENTED not in text:
        print("patch_xml_ssl: SSL listen-socket block not found, skipping", file=sys.stderr)
        return 1
    text = text.replace(SSL_BLOCK_COMMENTED, ssl_socket, 1)

    ssl_cert_line = "        <ssl-certificate>/etc/icecast2/bundle.pem</ssl-certificate>\n"
    if SSL_CERT_COMMENT not in text:
        print("patch_xml_ssl: ssl-certificate comment block not found, skipping", file=sys.stderr)
        return 1
    text = text.replace(SSL_CERT_COMMENT, ssl_cert_line, 1)

    path.write_text(text, encoding="utf-8")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
