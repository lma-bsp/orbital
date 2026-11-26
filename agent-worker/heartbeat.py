"""Liveness heartbeat for the Orbital dashboard.

Run by the container HEALTHCHECK (and the Helm readiness probe) every
30 seconds. POSTs to Laravel's /api/agent-worker/heartbeat with which
provider keys this worker's own environment holds, so `orbital:status`
and the AI Providers health card can tell whether a voice persona will
work: the worker falls back to these env keys for any key Laravel does
not send it.

Booleans only. Key values never leave the process.

Standard library only, so it starts fast and needs nothing the image
doesn't already have. Exits non-zero when the POST fails, which is what
the healthcheck reads.
"""

import json
import os
import socket
import sys
import urllib.request

# Provider => env vars the worker's plugins read for it. ElevenLabs'
# LiveKit plugin reads ELEVEN_API_KEY; compose sets both spellings.
PROVIDER_ENV = {
    "anthropic": ("ANTHROPIC_API_KEY",),
    "openai": ("OPENAI_API_KEY",),
    "openrouter": ("OPENROUTER_API_KEY",),
    "elevenlabs": ("ELEVEN_API_KEY", "ELEVENLABS_API_KEY"),
    "deepgram": ("DEEPGRAM_API_KEY",),
    "cartesia": ("CARTESIA_API_KEY",),
}


def env_keys(environ=None) -> dict:
    """Provider => whether any of its env vars holds a non-blank value."""
    environ = os.environ if environ is None else environ
    return {
        provider: any((environ.get(name) or "").strip() != "" for name in names)
        for provider, names in PROVIDER_ENV.items()
    }


def payload(environ=None) -> dict:
    return {"worker": worker_id(environ), "keys": env_keys(environ)}


def worker_id(environ=None) -> str:
    environ = os.environ if environ is None else environ
    raw = environ.get("HOSTNAME") or socket.gethostname() or "agent-worker"
    # Laravel accepts [A-Za-z0-9._:-]{1,128}.
    cleaned = "".join(c if c.isalnum() or c in "._:-" else "-" for c in raw)
    return cleaned[:128] or "agent-worker"


def main() -> int:
    base = (os.environ.get("ORBITAL_API_URL") or "").rstrip("/")
    token = os.environ.get("ORBITAL_API_TOKEN") or ""
    if not base:
        print("heartbeat: ORBITAL_API_URL not set", file=sys.stderr)
        return 1

    request = urllib.request.Request(
        f"{base}/agent-worker/heartbeat",
        data=json.dumps(payload()).encode(),
        method="POST",
        headers={
            "Authorization": f"Bearer {token}",
            "Accept": "application/json",
            "Content-Type": "application/json",
        },
    )

    try:
        with urllib.request.urlopen(request, timeout=5) as response:
            return 0 if 200 <= response.status < 300 else 1
    except Exception as exc:  # noqa: BLE001 - any failure is an unhealthy beat
        print(f"heartbeat: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
