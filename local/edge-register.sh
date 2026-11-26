#!/usr/bin/env bash
# Stage 3: register the off-cluster edge with the app.
#
# Points the rtpengine node registry at the edge's rtpengine container (its
# IP on the k3d network; NG control is only published on the host's
# loopback) so the app can NG-ping it — the rtpengine health card goes
# green and drain/activate work. The Kamailio dispatcher itself is
# managed statically by edge-up.sh (in this split topology the app can't
# write the edge's dispatcher.list, but its JSON-RPC dispatcher.reload
# still re-reads that static file).
set -euo pipefail
NAMESPACE="${NAMESPACE:-orbital}"
if [ -z "${EDGE_HOST:-}" ]; then
    EDGE_HOST="$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}' orbital-edge-rtpengine-1-1 2>/dev/null | awk '{print $1}')"
    [ -n "${EDGE_HOST}" ] || { echo "ERROR: rtpengine container not found; run ./local/edge-up.sh first or set EDGE_HOST" >&2; exit 1; }
fi

echo "==> pointing rtpengine registry at ${EDGE_HOST}:22222"
# HOME=/tmp so psysh can write its config dir (the sail user has no
# writable home in the container).
kubectl -n "$NAMESPACE" exec -i deploy/orbital-orbital-laravel -- env HOME=/tmp php artisan tinker <<PHP
App\Models\RtpengineNode::query()->update(['ng_host' => '${EDGE_HOST}', 'is_active' => true]);
echo App\Models\RtpengineNode::count()." rtpengine node(s) -> ${EDGE_HOST}\n";
PHP

# Flush the 60s health cache so the dashboard repaints promptly.
kubectl -n "$NAMESPACE" exec deploy/orbital-orbital-laravel -- php artisan cache:forget system_health:checks >/dev/null 2>&1 || true
echo "Done. rtpengine health card should go green shortly."
