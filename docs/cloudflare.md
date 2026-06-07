# Cloudflare Zero Trust And Tunnel

## Cloudflare Access Usage

Cloudflare Access should protect the Laravel PAM public hostname. Users authenticate through the configured identity provider before traffic reaches the application.

Example policy ideas:

- allow only approved student and admin email domains
- require MFA at the identity provider
- separate admin and student groups
- optionally require managed devices for admin access

Use placeholder examples in documentation and `.env.example` only. Do not commit Cloudflare tokens, service keys, or private tunnel credentials.

## Cloudflare Tunnel Usage

Cloudflare Tunnel connects the public Cloudflare hostname to the private Ubuntu jump server. This avoids exposing Nginx, Laravel, SSH, Proxmox, or WebSocket ports directly to the public internet.

Example conceptual route:

```text
https://pam.example.com -> http://127.0.0.1:80
```

If WebSocket traffic is served under the same hostname, route the WebSocket path through Nginx:

```text
https://pam.example.com/ws/terminal -> http://127.0.0.1:80/ws/terminal
```

## Public Hostname Routing

A typical Cloudflare configuration has:

- public hostname: `pam.example.com`
- tunnel service: `http://localhost:80`
- Access application: `pam.example.com`
- Access policy: allow approved users only

Laravel should be configured with:

```env
APP_URL=https://pam.example.com
TERMINAL_WEBSOCKET_URL=wss://pam.example.com/ws/terminal
```

## Why No Public Port Is Opened Directly

The jump server should not expose public inbound ports for Laravel, WebSocket terminal, SSH, or Proxmox. Cloudflare Tunnel makes an outbound connection from the jump server to Cloudflare, and Cloudflare Access authenticates users before forwarding traffic.

This supports the Zero Trust model:

- authenticate before application access
- reduce public attack surface
- keep Proxmox private
- keep SSH private
- centralize access policy at Cloudflare and Laravel

## Placeholder Tunnel Example

```yaml
tunnel: <tunnel-id-placeholder>
credentials-file: /etc/cloudflared/<tunnel-id-placeholder>.json

ingress:
  - hostname: pam.example.com
    service: http://127.0.0.1:80
  - service: http_status:404
```

The real tunnel ID and credentials file are secrets and must stay outside the repository.
