#!/usr/bin/env bash
# Демо: заливка пары записей от «агентов»
set -euo pipefail
BASE="${MODER_BASE:-http://127.0.0.1:39010}"
T="${MODER_TOKEN_AGENT:-}"
if [ -z "$T" ]; then
  echo "error: MODER_TOKEN_AGENT is not set" >&2
  exit 2
fi

curl -sS -X POST -H "Authorization: Bearer $T" -H "Content-Type: application/json" -d '{
  "idempotency_key":"demo-'$(date +%s)'",
  "type":"article","project":"blog","agent_name":"content-writer",
  "title":"Демо-статья от агента",
  "reason":"Проверить флоу публикации",
  "payload":{"title":"Демо-статья","slug":"demo","markdown":"# Привет\n\nЭто **демо** от агента."}
}' "$BASE/api/queue"
echo
