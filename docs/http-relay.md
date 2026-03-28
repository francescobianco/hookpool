# HTTP Relay — Special Function

## Overview

**HTTP Relay** turns a webhook into a transparent reverse-proxy gateway for services that live inside a private network and are not reachable from the internet.

The technique is based on **long-polling**: a lightweight relay client runs inside the private network, keeps a persistent outbound PATCH connection to the webhook, and uses that channel to shuttle incoming HTTP requests back and forth. No inbound port needs to be opened on the firewall.

```
  Internet caller           Hookpool server           Private network
  ─────────────             ───────────────           ───────────────
  POST /hook/token  ──────► (holds request)           relay client
                            ◄──────────────── PATCH ──── (polling)
                            ──── request ──────────────► (relay)
                            ◄──── response ─────────── PATCH+seq
  ◄── response ─────────── (delivers)
```

---

## Sub-Path Routing

Any path segment after the webhook token is forwarded to the local server as-is.

| Incoming public request               | Forwarded to local server |
|---------------------------------------|---------------------------|
| `GET  /slug/token`                    | `GET  /`                  |
| `GET  /slug/token/api/v1/users`       | `GET  /api/v1/users`      |
| `POST /slug/token/orders?page=2`      | `POST /orders?page=2`     |
| `DELETE /slug/token/items/42`         | `DELETE /items/42`        |

The relay client receives the stripped path in the `path` field of the request payload.
The query string is forwarded unchanged in the `query_string` field.

---

## How It Works — Step by Step

1. The relay client inside the private network opens a long-poll connection:
   ```
   PATCH /hook/{token}   (no body, no X-Relay-Seq)
   ```
   The server holds this connection open waiting for an inbound public request.

2. An external caller sends any non-PATCH request to the same webhook endpoint:
   ```
   POST /hook/{token}
   Content-Type: application/json
   {"order_id": 42}
   ```
   The server records the incoming request, **holds the caller's connection open**, and immediately responds to the waiting PATCH with the request payload:
   ```
   HTTP/1.1 200 OK
   X-Relay-Seq: 1
   Content-Type: application/json

   {"method":"POST","path":"/","query_string":"","headers":{...},"body":"{\"order_id\":42}","body_base64":false}
   ```

3. The relay client receives the request payload (seq = 1), reconstructs and forwards the HTTP call to the configured local server (e.g. `http://localhost:8080`), waits for the local response, then immediately sends a new PATCH carrying the response:
   ```
   PATCH /hook/{token}
   X-Relay-Seq: 1
   Content-Type: application/json

   {"status":200,"headers":{"Content-Type":"application/json"},"body":"{\"ok\":true}","body_base64":false}
   ```
   This single PATCH simultaneously **delivers** the response for seq 1 **and** opens the next long-poll session.

4. The server matches `X-Relay-Seq: 1` to the hanging public request and responds to the original caller with the reconstructed HTTP response (status, headers, body). The PATCH connection from step 3 now becomes the next idle long-poll slot.

---

## Access Sides

| Side    | Methods                     | Description                                     |
|---------|-----------------------------|-------------------------------------------------|
| Public  | GET, POST, PUT, DELETE, …   | Any external caller reaching a private service  |
| Private | PATCH only                  | The relay client living inside the private net  |

The PATCH method is **reserved** for the relay protocol when HTTP Relay is enabled. Any PATCH sent on the public side is treated as a relay client connection attempt, not as a regular event.

---

## Protocol Specification

### Private side — polling request (client → server)

```
PATCH /hook/{token}[?project={slug}]
Content-Type: application/json
[X-Relay-Seq: {N}]          ← present only when delivering a response
[Content-Length: ...]

[response payload JSON]     ← present only when delivering a response
```

| Field           | When present         | Meaning                                          |
|-----------------|----------------------|--------------------------------------------------|
| `X-Relay-Seq`   | On response delivery | Sequence number matching the request to respond  |
| Request body    | On response delivery | Response payload JSON (see format below)         |

When neither `X-Relay-Seq` nor a body is present the server enters wait mode.

### Server response to PATCH (server → client)

The server responds to the PATCH only when a public request has arrived (or the long-poll timeout expires):

```
HTTP/1.1 200 OK
Content-Type: application/json
X-Relay-Seq: {N}

{request payload JSON}
```

On timeout (no public request within the poll window) the server responds with:

```
HTTP/1.1 204 No Content
X-Relay-Seq: 0
```

The client must reconnect immediately.

### Request payload (server → client)

Delivered as the body of the PATCH response.

```json
{
  "method":       "POST",
  "path":         "/api/orders",
  "query_string": "debug=1",
  "headers": {
    "Content-Type":  "application/json",
    "Authorization": "Bearer eyJ..."
  },
  "body":         "{\"order_id\":42}",
  "body_base64":  false
}
```

| Field          | Type    | Description                                                  |
|----------------|---------|--------------------------------------------------------------|
| `method`       | string  | HTTP method of the public request (uppercase)                |
| `path`         | string  | Request path (without query string)                          |
| `query_string` | string  | Raw query string (without leading `?`)                       |
| `headers`      | object  | Request headers as key→value map                             |
| `body`         | string  | Request body. UTF-8 string, or base64 string if binary       |
| `body_base64`  | boolean | `true` when `body` is base64-encoded (binary payload)        |

### Response payload (client → server)

Delivered as the body of the next PATCH, alongside `X-Relay-Seq: {N}`.

```json
{
  "status":  200,
  "headers": {
    "Content-Type": "application/json"
  },
  "body":        "{\"ok\":true}",
  "body_base64": false
}
```

| Field          | Type    | Description                                                  |
|----------------|---------|--------------------------------------------------------------|
| `status`       | integer | HTTP status code to return to the public caller              |
| `headers`      | object  | Response headers to forward                                  |
| `body`         | string  | Response body. UTF-8 string, or base64 string if binary      |
| `body_base64`  | boolean | `true` when `body` is base64-encoded (binary payload)        |

---

## Sequence Numbers (`X-Relay-Seq`)

Each relay transaction is identified by a monotonically increasing integer (`N ≥ 1`).

- The server assigns the sequence number when it dispatches the request to the client.
- The client echoes it back in the PATCH that carries the response.
- The server uses it to locate the suspended public request in the relay cache and resume it.

A sequence counter resets to 1 when the webhook is reconfigured or the server restarts. The cache entry is keyed by `(webhook_id, seq)`.

---

## Server-Side Relay Cache

The server maintains an in-memory relay cache (one entry per active transaction):

```
relay_cache[webhook_id][seq] = {
    suspended_response_callback,
    arrived_at,
    timeout_at,
}
```

- When a public request arrives and no client is polling: the request is queued (up to 1 pending request; excess requests receive `503 Service Unavailable`).
- When a client is already polling: the pending request is dispatched immediately.
- When the client delivers a response: the entry is removed and the public caller is resumed.
- **Response timeout**: if the relay client does not deliver a response within `RELAY_RESPONSE_TIMEOUT` seconds the server returns `504 Gateway Timeout` to the public caller.
- **Poll timeout**: if no public request arrives within `RELAY_POLL_TIMEOUT` seconds the server returns `204 No Content` to the client and the client must reconnect.

Recommended defaults:

| Parameter               | Default |
|-------------------------|---------|
| `RELAY_POLL_TIMEOUT`    | 28 s    |
| `RELAY_RESPONSE_TIMEOUT`| 30 s    |
| `RELAY_QUEUE_DEPTH`     | 1       |

---

## Enabling HTTP Relay

1. Navigate to `/?page=webhook&action=settings&id={id}`.
2. In the **Special Functions** section select **HTTP Relay**.
3. Save. The webhook immediately starts accepting PATCH polling connections.

When HTTP Relay is active:
- PATCH requests are **not** logged as regular events.
- All other methods are logged normally but the response is held until the relay client delivers it.
- Standard guards (IP whitelist, token header, …) still apply to public-side requests.

---

## Relay Client — Integration Guide

The relay client is a small process that runs inside the private network. It needs only outbound HTTPS access to the Hookpool server.

### Minimal loop (pseudocode)

```
seq      = None
response = None

loop forever:
    headers = {"Content-Type": "application/json"}
    body    = ""

    if seq is not None:
        headers["X-Relay-Seq"] = str(seq)
        body = json_encode(response)

    patch_resp = PATCH(webhook_url, headers=headers, body=body, timeout=35)

    if patch_resp.status == 204:          # poll timeout, reconnect
        seq      = None
        response = None
        continue

    if patch_resp.status != 200:          # unexpected error
        sleep(3)
        seq      = None
        response = None
        continue

    seq     = int(patch_resp.header("X-Relay-Seq"))
    payload = json_decode(patch_resp.body)

    local_resp = forward_to_local_server(payload)

    response = {
        "status":      local_resp.status,
        "headers":     local_resp.headers,
        "body":        local_resp.body,         # base64 if binary
        "body_base64": local_resp.is_binary,
    }
    # immediately loop → next PATCH delivers the response AND polls
```

### Headers to strip when forwarding to the local server

The relay client should strip or replace the following headers before forwarding to the local server, as they are specific to the original transport:

- `Host` → replace with the local server hostname
- `Content-Length` → recalculate from actual body
- `Transfer-Encoding`
- `Connection`
- `Keep-Alive`
- `Upgrade`

---

## Security Considerations

- The public side of the webhook is subject to all configured **guards** (IP whitelist, static token, query secret, required header). Use at least one guard to prevent abuse.
- The PATCH (private) side is authenticated by the webhook **token** itself — keep it secret.
- All transit is over HTTPS; the relay cache is only held in-memory on the server.
- The relay client never exposes any local port to the internet. Only outbound HTTPS is needed.
- Response payloads travel through the server — avoid relaying sensitive services without end-to-end encryption at the application layer.

---

## Error Codes

| Condition                                    | Public caller receives |
|----------------------------------------------|------------------------|
| No relay client connected, no queue slot      | `503 Service Unavailable` |
| Relay client connected, request dispatched    | (held open)            |
| Client delivers response in time             | The actual response    |
| Client does not respond within timeout        | `504 Gateway Timeout`  |
| Webhook disabled or paused                   | `410 Gone`             |

---

## Demo

See [`tests/relay_demo.py`](../tests/relay_demo.py) for a self-contained Python demonstration that:

1. Starts a local demo HTTP server (port 9876 by default).
2. Runs a relay client that long-polls the configured webhook via PATCH.
3. Forwards any incoming public request to the local server and returns the response.

### Quick start

```bash
# Install no dependencies — uses only Python standard library

python3 tests/relay_demo.py 'https://<your-hookpool-host>/hook?token=<token>&project=<slug>'

# In another terminal, call the webhook:
curl -X POST 'https://<your-hookpool-host>/hook?token=<token>&project=<slug>' \
     -H 'Content-Type: application/json' \
     -d '{"hello":"world"}'

# Response will be served by the local demo server:
# {"demo":"HTTP Relay is working!","received":{...},"server":"Local demo server on port 9876"}
```
