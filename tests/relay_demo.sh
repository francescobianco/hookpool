#!/bin/sh
# relay_demo.sh — Hookpool HTTP-in-HTTP Relay demo (X-Relay-Mod: http)
#
# Usage:
#   HOOKPOOL_RELAY_URL=https://hookpool.example.com/proj/token.relay \
#     ./tests/relay_demo.sh

# ── Relay loop header ── do not modify this section ───────────────────────────
while [ "${HOOKPOOL_CHILD:-0}" != "1" ]; do
  : "${HOOKPOOL_RELAY_URL:?}"
  h=${h:-$(mktemp)}; r=${r:-$(mktemp)}
  curl -s -X PATCH -D "$h" -o /dev/null \
    -H "X-Relay-Mod: http" -H "X-Relay-Seq: $s" \
    --data-binary "@$r" "$HOOKPOOL_RELAY_URL"
  s=$(awk 'tolower($0)~/^x-relay-seq:/{gsub(/\r/,"");print $2;exit}' "$h")
  case "$s" in ''|0) s=""; sleep 1; continue ;; esac
  { printf 'HTTP/1.1 200 OK\r\n'
    printf 'Content-Type: text/plain\r\n'
    printf '\r\n'
    HOOKPOOL_CHILD=1 "$0"; } >"$r" 2>&1
done
# ── End of relay loop header ───────────────────────────────────────────────────


# ── Script body — edit freely below this line ─────────────────────────────────

echo "Hello from Hookpool HTTP Relay!"
echo ""
echo "Date : $(date)"
echo "Host : $(hostname)"
echo ""
ls -la

# ── End of script body ────────────────────────────────────────────────────────