# PAM Terminal

## Session Lifecycle

A terminal session is created when a user opens terminal access for an allowed VM. The session tracks status, owner, VM, last activity, expiration, close events, and revocation events.

Typical lifecycle:

```text
pending -> active -> closed
pending -> active -> expired
pending -> active -> revoked
```

Expired, closed, and revoked sessions cannot execute commands.

## Monitored Command Mode

Monitored command mode sends specific commands through the PAM command service. It logs commands, command status, duration, exit code, blocked reason, and output excerpts. Unsafe commands can be blocked by policy.

Shared Practical VMs use monitored command mode so many students can work on one shared target while logs remain tied to each authenticated user.

## WebSocket Terminal Service

The WebSocket terminal service is started with:

```bash
php artisan terminal:websocket-server
```

Production deployments should run it with systemd as `pam-terminal-websocket`. Nginx can proxy `/ws/terminal` to the local WebSocket port, commonly `127.0.0.1:8090`.

## Interactive PTY Terminal

Interactive PTY mode is intended for Self-Service VMs owned by the student. It gives a more shell-like experience while still passing through PAM session authorization and logging boundaries.

The terminal page includes a maximize/focus mode for small laptop or lab monitors. The green macOS-style window control expands the existing terminal panel to the full browser viewport without reloading the page or reconnecting the WebSocket session. Clicking the green control again, pressing `Esc`, or clicking the red control while maximized exits focus mode.

## Shared Practical VM vs Self-Service VM Terminal

Shared Practical VM:

- accessed by grant
- shared by many students
- uses monitored command mode
- blocks high-risk command patterns

Self-Service VM:

- owned by one student
- counts toward quota
- eligible for interactive PTY terminal
- still blocks expired/revoked/unauthorized sessions

## Session Expiration

Sessions have an expiration boundary. The application checks expiration during access and command execution so stale sessions close safely even without a scheduler.

## Session Revocation

Admins can revoke active terminal sessions from monitoring workflows. Revoked sessions cannot execute additional commands.

## Command Logging And Restriction

Each command is logged per authenticated user, VM, and terminal session. Monitored command mode can block destructive or inappropriate command patterns, especially for shared and protected targets.

The SOC monitoring dashboard lets admins filter command logs by student/user, VM, command status, terminal session status, and date range. This helps teachers or admins verify whether a selected student is actively working during practical sessions while keeping command visibility scoped to the admin-only monitoring route. Teacher/guru scoping can be extended later if a teacher role and allowed-student authorization model are added.

## Fallback Behavior

If PTY mode is unavailable or a VM is not eligible, the UI should fall back to monitored command mode where supported. If WebSocket or SSH readiness is unavailable, the application returns a safe error instead of exposing secrets or bypassing authorization.
