#!/bin/sh
set -efu

PUBLIC_KEY='/run/landingops-test/audit.pub'
[ -f "$PUBLIC_KEY" ] && [ ! -L "$PUBLIC_KEY" ] || exit 90
key=$(/usr/bin/awk 'NR==1 && $1=="ssh-ed25519" && NF>=2 {print $1 " " $2 " landingops-audit@landingops"}' "$PUBLIC_KEY")
[ -n "$key" ] || exit 90

/usr/sbin/groupadd --system landingops-audit
/usr/sbin/useradd --system --gid landingops-audit --home-dir /var/lib/landingops-audit \
    --shell /usr/local/libexec/landingops-readonly-wrapper landingops-audit
/usr/sbin/usermod --lock landingops-audit
/usr/bin/install -d -o root -g root -m 0755 /var/lib/landingops-audit /var/lib/landingops-audit/.ssh /run/sshd
/usr/bin/printf 'restrict,command="/usr/local/libexec/landingops-readonly-wrapper" %s\n' "$key" > /var/lib/landingops-audit/.ssh/authorized_keys
/usr/bin/chown root:root /var/lib/landingops-audit/.ssh/authorized_keys
/usr/bin/chmod 0644 /var/lib/landingops-audit/.ssh/authorized_keys
/usr/bin/ssh-keygen -A

/usr/bin/cat > /etc/ssh/sshd_config.d/landingops-integration.conf <<'EOF'
Port 2222
ListenAddress 0.0.0.0
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
AuthenticationMethods publickey
AuthorizedKeysFile .ssh/authorized_keys
AuthorizedKeysCommand none
TrustedUserCAKeys none
PermitUserEnvironment no
HostbasedAuthentication no
GSSAPIAuthentication no
UsePAM no
AllowUsers landingops-audit
EOF

exec /usr/sbin/sshd -D -e
