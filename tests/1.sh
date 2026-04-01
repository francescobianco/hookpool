#!/bin/bash

while [ ! "$PPID" = "$_p" ]; do
  h=$(mktemp)
  r=$(mktemp)
  curl "http://localhost:8080/test-relay/q2keg4.relay" \
    -H "X-Relay-Mod: http" -H "X-Relay-Seq: $s" -s -X PATCH -D "$h" -o /dev/null --data-binary "@$r"
  s=$(awk 'tolower($0)~/^x-relay-seq:/{gsub(/\r/,"");print $2;exit}' "$h")
  { printf 'HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n\r\n'; _p=$$ "$0"; } > "$r" 2>&1
done

ls
