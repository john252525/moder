#!/usr/bin/env bash
# Минимальный devops-воркер: забирает approved, "применяет" и отмечает applied.
# Заменить блок "apply" на реальные действия (git merge / smtp / deploy).
set -euo pipefail
BASE="${MODER_BASE:-http://127.0.0.1:39010}"
TOKEN="${MODER_TOKEN_DEVOPS:-}"
if [ -z "$TOKEN" ]; then
  echo "error: MODER_TOKEN_DEVOPS is not set" >&2
  exit 2
fi
LIMIT="${1:-5}"

resp=$(curl -sS -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d "{\"limit\":$LIMIT}" "$BASE/api/devops/claim")

count=$(echo "$resp" | python3 -c 'import json,sys; print(json.load(sys.stdin)["count"])')
echo "claimed: $count"

echo "$resp" | python3 -c '
import json,sys
for it in json.load(sys.stdin)["items"]:
    print(it["id"], it["type"], it["project"])
' | while read -r id type project; do
  echo "applying $id ($type / $project)"
  # === APPLY HERE ===
  # case "$type" in
  #   email) ... send via smtp ;;
  #   article) ... publish to cms ;;
  #   site_change) ... git merge+deploy ;;
  #   ad) ... push to ads api ;;
  # esac
  if curl -sS -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer $TOKEN" \
      -H "Content-Type: application/json" \
      -d '{"comment":"applied by worker"}' "$BASE/api/devops/$id/applied" | grep -q '^200$'; then
    echo "  -> applied"
  else
    curl -sS -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
      -d '{"error":"worker failed"}' "$BASE/api/devops/$id/failed" || true
    echo "  -> failed"
  fi
done
