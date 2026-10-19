#!/usr/bin/env bash
# Startet einen lokalen Dovecot (IMAP mit TLS, selbstsigniert) für die
# Integrationstests der Mailbox. Nur für Testumgebungen.
#
#   sudo bash tests/integration/dovecot-test-server.sh
#
# Danach: IMAP klartext/STARTTLS 127.0.0.1:1143, TLS 127.0.0.1:1993.
# Benutzer:  test@example.de / pässwort,  sven@example.de / svenpw,
#            katrin@example.de / katrinpw
set -euo pipefail

D=/run/dvtest
pkill -9 dovecot 2>/dev/null || true
rm -rf "$D"
mkdir -p "$D/mail" "$D/run"

openssl req -x509 -newkey rsa:2048 -nodes -keyout "$D/key.pem" -out "$D/cert.pem" -days 30 -subj "/CN=localhost" 2>/dev/null
chmod 644 "$D/cert.pem" "$D/key.pem"

cat > "$D/users" <<USERS
test@example.de:{PLAIN}pässwort:1000:1000::$D/mail/test::
sven@example.de:{PLAIN}svenpw:1000:1000::$D/mail/sven::
katrin@example.de:{PLAIN}katrinpw:1000:1000::$D/mail/katrin::
USERS
chown 1000:1000 "$D/mail"

cat > "$D/dv.conf" <<CONF
base_dir = $D/run
log_path = $D/log
protocols = imap
listen = 127.0.0.1
mail_location = maildir:~/Maildir
mail_uid = root
mail_gid = root
first_valid_uid = 0
disable_plaintext_auth = no
auth_mechanisms = plain login
ssl = yes
ssl_cert = <$D/cert.pem
ssl_key = <$D/key.pem
service imap-login {
  inet_listener imap {
    port = 1143
  }
  inet_listener imaps {
    port = 1993
    ssl = yes
  }
}
service auth {
  unix_listener auth-userdb {
    mode = 0666
  }
}
passdb {
  driver = passwd-file
  args = scheme=PLAIN username_format=%u $D/users
}
userdb {
  driver = passwd-file
  args = username_format=%u $D/users
}
namespace inbox {
  inbox = yes
  mailbox Sent {
    special_use = \Sent
    auto = subscribe
  }
  mailbox Drafts {
    special_use = \Drafts
    auto = subscribe
  }
  mailbox Trash {
    special_use = \Trash
    auto = subscribe
  }
  mailbox Spam {
    special_use = \Junk
    auto = subscribe
  }
}
CONF

dovecot -c "$D/dv.conf"
sleep 2
echo "Dovecot läuft (IMAP 1143, IMAPS 1993)."
