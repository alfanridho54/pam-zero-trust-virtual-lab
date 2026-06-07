# Architecture

## Final Infrastructure

The final PAM Virtual Lab architecture is:

```text
Cloudflare Zero Trust
-> Cloudflare Tunnel
-> Laravel PAM Dashboard
-> Ubuntu Jump Server
-> PAM WebSocket Terminal
-> SSH Monitoring / PTY Terminal
-> Shared Practical VM / Self-Service Student VM
-> Proxmox VE
```

The design keeps public access, application authorization, terminal execution, and virtualization management in separate layers. Users reach the system through Cloudflare, but VM control and terminal access are mediated by Laravel and the jump server.

## Cloudflare Zero Trust Layer

Cloudflare Access authenticates users before they can reach the Laravel hostname. Access policies can restrict login by email, group, identity provider, or device posture. Cloudflare Tunnel then forwards approved traffic to the private jump server without requiring direct public inbound ports on the server.

## Ubuntu Jump Server Role

The Ubuntu jump server is the controlled execution point. It runs:

- Laravel PHP application runtime
- Nginx and PHP-FPM
- PAM WebSocket terminal service
- outbound SSH client path to lab VMs
- optional Squid Proxy for controlled outbound internet

Students never SSH directly to the jump server. Their terminal activity is mediated by PAM sessions.

## Laravel PAM Dashboard Role

Laravel is the policy and audit layer. It handles:

- authenticated user resolution
- two-role RBAC with `admin` and `student`
- VM ownership and access grant checks
- Proxmox API calls
- template and quota management
- terminal session lifecycle
- command and audit logging
- SOC monitoring dashboard for admins

## Proxmox VE Role

Proxmox VE hosts the virtual lab infrastructure. Laravel talks to Proxmox through API tokens for VM inventory, lifecycle operations, template cloning, task tracking, and QEMU Guest Agent IP detection. Students do not receive Proxmox credentials or direct Proxmox UI access.

## Shared Practical VM vs Self-Service VM

Shared Practical VM:

- one VM can be accessed by many students
- access is controlled by `practical_vm_accesses`
- terminal uses monitored command mode
- does not count toward Self-Service VM quota

Self-Service VM:

- owned by one student
- provisioned from an enabled Proxmox template
- counts toward student quota
- terminal can use interactive PTY mode when eligible

## PAM Terminal Access Flow

1. User passes Cloudflare Access.
2. Laravel resolves the authenticated user.
3. User selects an allowed VM.
4. Laravel validates role, ownership, grant, VM visibility, and protected status.
5. Laravel creates or reuses a terminal session.
6. WebSocket terminal service executes through the jump server.
7. Command mode or PTY mode is selected based on VM type.
8. Logs are written per authenticated user and session.

## Audit And Monitoring Flow

Operational actions create audit logs. Terminal commands create command logs. Terminal sessions track status, last activity, expiration, close, and revocation events. Admins use the SOC dashboard to review recent commands, blocked commands, active sessions, and activity timelines.
