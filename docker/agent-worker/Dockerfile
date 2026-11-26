FROM python:3.14-slim

WORKDIR /app

# `curl` is handy for debugging the Laravel API from inside the container.
# The HEALTHCHECK below uses heartbeat.py (mounted from ./agent-worker).
RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential \
    curl \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

# The livekit-agents CLI requires a subcommand. `start` runs the worker in
# production mode, registering with the LiveKit server and waiting for
# dispatch.
CMD ["python", "agent.py", "start"]

# Heartbeat to Laravel every 30s so the dashboard's Agent Worker health
# check stays green, reporting which provider keys this env holds
# (booleans only). The endpoint is gated by ORBITAL_API_TOKEN.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD python /app/heartbeat.py || exit 1
