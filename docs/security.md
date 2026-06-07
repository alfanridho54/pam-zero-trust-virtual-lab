# Security Notes

## Zero Trust Access

Cloudflare Access authenticates users before they reach Laravel. Cloudflare Tunnel avoids opening public inbound ports directly on the jump server.

## RBAC

The final role model has two active roles:

- `admin`: lab administrator, teacher, and manager role
- `student`: regular student user

Admins can manage lab resources and monitor activity. Students are restricted to owned resources and explicit Shared Practical VM grants.

## Ownership Validation

Student VM access is validated by local ownership and access grants. Proxmox inventory is correlated with local records so raw VM visibility does not become an authorization bypass.

## Shared Practical VM Access Grant

Shared Practical VMs require an explicit `practical_vm_accesses` record. The VM can be shared by many students, but each terminal session and command remains tied to the authenticated user.

## Critical/System VM Protection

System, critical, protected, infrastructure, and soft-deleted VMs are blocked from unsafe student and normal UI actions. Admin visibility does not automatically mean unsafe lifecycle control is allowed.

## Command Restriction Policy

Monitored command mode blocks high-risk commands and records blocked attempts. Shared Practical VM command execution is intentionally more constrained than Self-Service interactive PTY access.

## Session Expiration And Revocation

Terminal sessions can expire. Admins can revoke active sessions. Closed, expired, or revoked sessions cannot execute commands.

## Audit Logging And SOC Monitoring

Laravel writes audit logs for VM, dashboard, provisioning, SSH metadata, terminal, and access-grant actions. Admins can review command logs, blocked commands, active sessions, and timelines in the SOC monitoring dashboard.

## Credentials

Do not commit real credentials:

- no real SSH passwords
- no Proxmox token secrets
- no Cloudflare tunnel credentials
- no private keys
- no production passwords

Use `.env` or encrypted/safe metadata storage only.

## MVP Limitations

This thesis/MVP implementation demonstrates the target access pattern and controls, but production deployments should still review:

- Cloudflare Access policy hardening
- Proxmox token least privilege
- encrypted secret storage
- centralized log retention
- backup and restore
- rate limiting
- network segmentation
- formal incident response process
- additional automated cleanup jobs
