#!/usr/bin/env bash
# Talk to an Orbital AI agent from a desktop SIP app (MicroSIP, Linphone,
# Zoiper) through the local SIP edge (local/edge-up.sh must be running).
#
#   ./local/microsip-test.sh open <your-public-ip> [extension]
#       Allows SIP + RTP from that one IP, creates (or resets) a SIP-phone
#       extension (default 3000), regenerates the dialplan, prints the
#       MicroSIP settings.
#   ./local/microsip-test.sh status [extension]
#       Shows whether the phone is registered and the edge is healthy.
#   ./local/microsip-test.sh watch
#       Follows the AI worker log while you call.
#   ./local/microsip-test.sh close <your-public-ip>
#       Removes the firewall rules again.
#
# Env: PUBLIC_IP (required: this host's public address, the same one given
#      to edge-up.sh as EDGE_RTP_ADVERTISE_IP), WAN_IF (eno1), NAMESPACE (orbital),
#      OWNER_EMAIL (optional: Orbital user the extension is assigned to).
#
# Firewall note: this host drops NEW outside traffic to Docker-published
# ports in the DOCKER-USER chain (see /etc/ufw/after.rules), so `ufw allow`
# has no effect on the edge. The rules go into DOCKER-USER instead. They are
# runtime-only: a reboot or `ufw reload` closes the edge again.
set -euo pipefail

PUBLIC_IP="${PUBLIC_IP:-}"
WAN_IF="${WAN_IF:-eno1}"
NAMESPACE="${NAMESPACE:-orbital}"
TAG="orbital-edge-test"

die() { echo "ERROR: $*" >&2; exit 1; }

ipt_for() { case "$1" in *:*) echo ip6tables ;; *) echo iptables ;; esac; }

# (proto, dport) pairs the softphone needs: SIP signaling + rtpengine media.
# Docker DNATs 1:1 here, so the post-DNAT port in DOCKER-USER is the same.
RULES=("udp 5060" "udp 23000:23099")

fw() {
    local action="$1" ip="$2" ipt; ipt="$(ipt_for "$ip")"
    for r in "${RULES[@]}"; do
        set -- $r
        local spec=(-i "$WAN_IF" -s "$ip" -p "$1" --dport "$2" -m comment --comment "$TAG" -j RETURN)
        if [ "$action" = open ]; then
            sudo "$ipt" -C DOCKER-USER "${spec[@]}" 2>/dev/null \
                || sudo "$ipt" -I DOCKER-USER 1 "${spec[@]}"
            echo "  allowed $1/$2 from $ip"
        else
            while sudo "$ipt" -C DOCKER-USER "${spec[@]}" 2>/dev/null; do
                sudo "$ipt" -D DOCKER-USER "${spec[@]}"
            done
            echo "  removed $1/$2 from $ip"
        fi
    done
}

laravel() { kubectl -n "$NAMESPACE" exec -i deploy/orbital-orbital-laravel -- "$@"; }
asterisk_cli() {
    kubectl -n "$NAMESPACE" exec orbital-orbital-asterisk-0 -c asterisk -- asterisk -rx "$1"
}

preflight() {
    [ -n "$PUBLIC_IP" ] || die "set PUBLIC_IP to this host's public address"
    docker ps --format '{{.Names}}' | grep -qx orbital-edge-kamailio-1 \
        || die "the SIP edge isn't running; start it with:
  CLUSTER_NAME=orbital EDGE_RTP_ADVERTISE_IP=${PUBLIC_IP} ./local/edge-up.sh"
    docker ps --format '{{.Names}}' | grep -qx orbital-edge-rtpengine-1-1 \
        || die "rtpengine isn't running (docker logs orbital-edge-rtpengine-1-1)"
}

create_extension() {
    local ext="$1" password
    password="$(openssl rand -hex 12)"
    laravel env HOME=/tmp EXT="$ext" PASS="$password" OWNER="${OWNER_EMAIL:-}" \
        php artisan tinker >/dev/null <<'PHP'
$owner = getenv('OWNER') ? App\Models\User::where('email', getenv('OWNER'))->first() : null;
$clash = App\Models\Extension::withoutGlobalScopes()->where('number', getenv('EXT'))
    ->where('type', '!=', 'sip_phone')->exists();
if ($clash) { fwrite(STDERR, "extension ".getenv('EXT')." already exists and is not a SIP phone\n"); exit(1); }
App\Models\Extension::withoutGlobalScopes()->updateOrCreate(
    ['number' => getenv('EXT'), 'team_id' => null],
    [
        'type' => 'sip_phone',
        'label' => 'Desktop SIP test phone',
        'sip_username' => getenv('EXT'),
        'sip_password' => getenv('PASS'),
        'transport' => 'udp',
        'context' => 'internal',
        'recording_mode' => 'inherit',
        'is_active' => true,
        'assignable_type' => $owner ? App\Models\User::class : null,
        'assignable_id' => $owner?->id,
    ],
);
PHP
    laravel php artisan orbital:generate-config >/dev/null
    asterisk_cli "pjsip show endpoint ${ext}" | grep -q "Endpoint:  *${ext}" \
        || die "extension ${ext} was saved but Asterisk has no endpoint for it yet; re-run in a few seconds"
    echo "$password"
}

cmd="${1:-}"
case "$cmd" in
open)
    ip="${2:-}"; ext="${3:-3000}"
    [ -n "$ip" ] || die "usage: $0 open <your-public-ip> [extension]"
    preflight
    echo "==> firewall (DOCKER-USER)"
    fw open "$ip"
    echo "==> extension ${ext}"
    password="$(create_extension "$ext")"
    cat <<EOF

MicroSIP -> Menu -> Add Account
  SIP Server        ${PUBLIC_IP}
  SIP Proxy         (blank)
  Username          ${ext}
  Domain            ${PUBLIC_IP}
  Login             ${ext}
  Password          ${password}
  Transport         UDP
  Public Address    (blank / Auto)
  Media Encryption  Disabled
  Use ICE           off,  STUN: blank

Then dial *43 (echo test) and 1001 (the AI agent).
Check registration:  $0 status ${ext}
Watch the agent:     $0 watch
Close up afterwards: $0 close ${ip}
EOF
    ;;
status)
    ext="${2:-3000}"
    echo "==> registration"
    asterisk_cli "pjsip show contacts" | grep -E "Contact: +${ext}/" || echo "  ${ext} is not registered"
    echo "==> firewall rules"
    { sudo iptables -S DOCKER-USER; sudo ip6tables -S DOCKER-USER; } 2>/dev/null | grep "$TAG" || echo "  none (edge closed)"
    echo "==> telephony status"
    laravel php artisan orbital:status 2>&1 | sed -n '3,8p'
    ;;
watch)
    kubectl -n "$NAMESPACE" logs deploy/orbital-orbital-agent-worker -f --since 1m
    ;;
close)
    ip="${2:-}"
    [ -n "$ip" ] || die "usage: $0 close <your-public-ip>"
    fw close "$ip"
    ;;
*)
    sed -n '2,24p' "$0" | sed 's/^# \{0,1\}//'
    exit 1
    ;;
esac
