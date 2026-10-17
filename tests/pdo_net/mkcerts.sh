#!/bin/bash
# Throwaway TLS material for mc-pg-ssl (generated at `up`, deleted by down.sh, never committed): a CA, a server cert
# (CN=localhost, SAN localhost + 127.0.0.1), a client cert (CN=cu) from the same CA, and an unrelated CA for "wrong root" cases.
set -eu
d="$1"
mkdir -p "$d"
cd "$d"
[ -f server.crt ] && exit 0
q() { openssl "$@" >/dev/null 2>&1; }
q req -x509 -newkey rsa:2048 -nodes -keyout ca.key -out ca.pem -days 30 -subj "/CN=mc-test-ca" -addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign,cRLSign"
q req -x509 -newkey rsa:2048 -nodes -keyout ca2.key -out ca2.pem -days 30 -subj "/CN=mc-other-ca" -addext "basicConstraints=critical,CA:TRUE"
q req -newkey rsa:2048 -nodes -keyout server.key -out server.csr -subj "/CN=localhost"
printf 'subjectAltName=DNS:localhost,IP:127.0.0.1\nbasicConstraints=CA:FALSE\n' > server.ext
q x509 -req -in server.csr -CA ca.pem -CAkey ca.key -CAcreateserial -out server.crt -days 30 -extfile server.ext
q req -newkey rsa:2048 -nodes -keyout client.key -out client.csr -subj "/CN=cu"
printf 'basicConstraints=CA:FALSE\n' > client.ext
q x509 -req -in client.csr -CA ca.pem -CAkey ca.key -CAcreateserial -out client.crt -days 30 -extfile client.ext
# a CRL listing the client cert (revocation case)
mkdir -p crl; : > crl/index.txt; echo 01 > crl/crlnumber
printf '[ca]\ndefault_ca=c\n[c]\ndatabase=crl/index.txt\ncrlnumber=crl/crlnumber\ndefault_md=sha256\ndefault_crl_days=30\n' > crl.cnf
q ca -config crl.cnf -gencrl -keyfile ca.key -cert ca.pem -out empty.crl
chmod 644 *.pem *.crt *.crl; chmod 644 server.key ca.key ca2.key; chmod 600 client.key
q ca -config crl.cnf -gencrl -keyfile ca2.key -cert ca2.pem -out other.crl
chmod 644 *.crl
