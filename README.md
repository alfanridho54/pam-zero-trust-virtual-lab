# PAM Zero Trust Virtual Lab

PAM Zero Trust Virtual Lab is a Laravel-based Privileged Access Management dashboard for securing remote student access to a Proxmox VE virtual lab. The system places Cloudflare Zero Trust in front of Laravel, keeps students away from direct Proxmox access, and routes terminal activity through monitored PAM workflows on an Ubuntu jump server.

## Research Context

This project supports a virtual laboratory research implementation where remote access must be authenticated, authorized, monitored, and auditable. The thesis/MVP goal is to demonstrate that a student can access lab VMs through a Zero Trust PAM layer without receiving direct Proxmox VE credentials, direct SSH exposure, or broad infrastructure privileges.

## Final Architecture

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

Cloudflare Access authenticates users before they reach Laravel. Laravel applies RBAC, VM ownership validation, access grants, quota checks, audit logging, and terminal session controls. The Ubuntu jump server hosts the Laravel app, WebSocket terminal process, and outbound SSH path to lab VMs. Proxmox VE remains internal and is managed only by the Laravel backend through API tokens.

## Key Features

- Cloudflare Zero Trust authentication and Cloudflare Tunnel integration.
- Laravel PAM dashboard for admins and student workspace views.
- Proxmox VE API integration for monitoring, lifecycle actions, and template cloning.
- Real-time VM inventory display with protected/system VM filtering.
- Two active roles: `admin` and `student`.
- Shared Practical VM access through `practical_vm_accesses` grants.
- Self-Service VM provisioning from Proxmox templates.
- VM quota enforcement and VMID-safe allocation.
- QEMU Guest Agent IP detection and SSH metadata refresh.
- PAM terminal session lifecycle with expiration and admin revocation.
- WebSocket terminal service with monitored command mode.
- Interactive PTY terminal for Self-Service VMs.
- Command logging, command restriction, audit logging, and SOC monitoring.
- Controlled outbound internet path through Squid Proxy where deployed.

## Tech Stack

- PHP 8.2+ and Laravel
- Blade, Vite, Tailwind CSS
- SQLite/MySQL/MariaDB/PostgreSQL supported by Laravel migrations
- Proxmox VE API
- Cloudflare Zero Trust and Cloudflare Tunnel
- Ubuntu jump server
- WebSocket terminal command service
- SSH and PTY terminal process handling
- systemd and Nginx for production hosting

## Installation

```bash
git clone <repository-url>
cd pam-zero-trust-virtual-lab
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
```

For local development:

```bash
php artisan serve
npm run dev
```

## Environment Configuration

Set normal Laravel variables first:

```env
APP_NAME="PAM Zero Trust Virtual Lab"
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://pam.example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pam_virtual_lab
DB_USERNAME=pam_user
DB_PASSWORD=change-me
```

Configure Proxmox and terminal settings with placeholders only in committed examples:

```env
PROXMOX_HOST=https://pve.example.internal:8006
PROXMOX_NODE=pve
PROXMOX_TOKEN_ID=laravel@pve!pam-lab
PROXMOX_TOKEN_SECRET=store-real-secret-only-in-env
PROXMOX_STUDENT_TEMPLATE_VMID=9000
PROXMOX_VMID_ALLOCATION_ATTEMPTS=25

TERMINAL_WEBSOCKET_URL=wss://pam.example.com/ws/terminal
PRACTICE_VM_SSH_HOST=
PRACTICE_VM_SSH_PORT=22
PRACTICE_VM_SSH_USERNAME=student
PRACTICE_VM_SSH_PASSWORD=
```

Never commit real Proxmox tokens, Cloudflare credentials, SSH passwords, or private keys.

## Database Migration And Seeding

```bash
php artisan migrate
php artisan db:seed
```

Or run both together:

```bash
php artisan migrate --seed
```

The normal seeder is intentionally non-destructive for existing infrastructure data. Outside the `testing` environment it creates demo users only and does not modify `vms`, `vm_templates`, `lab_templates`, Proxmox nodes, VMIDs, or SSH metadata.

Optional disabled placeholder data can be enabled only when explicitly requested:

```env
ENABLE_DEMO_SEED_DATA=true
```

When enabled, placeholder rows use obvious `[DEMO DISABLED]` names and are disabled by default so they do not appear in student template selection.

The demo user seed data is:

- `admin@example.com` / `password`
- `user@example.com` / `password`
- `student@example.com` / `password`
- lab-local demo accounts from `MockLabSeeder`

The seeders do not contain real SSH passwords, Proxmox secrets, Cloudflare secrets, or production credentials.

## Cloudflare Zero Trust And Tunnel Notes

Use Cloudflare Access to protect the public hostname before traffic reaches Laravel. Use Cloudflare Tunnel so the jump server does not need to expose public inbound ports directly. Laravel can resolve the authenticated user from normal Laravel auth or trusted identity headers forwarded by the Zero Trust layer, depending on deployment.

See [docs/cloudflare.md](docs/cloudflare.md).

## Proxmox VE API Configuration

Create a Proxmox API token with the minimum permissions required for inventory, lifecycle actions, template cloning, task status checks, and QEMU Guest Agent reads. Store token values in `.env`.

Important variables:

- `PROXMOX_HOST`
- `PROXMOX_NODE`
- `PROXMOX_TOKEN_ID`
- `PROXMOX_TOKEN_SECRET`
- `PROXMOX_STUDENT_TEMPLATE_VMID`
- `PROXMOX_VMID_ALLOCATION_ATTEMPTS`

See [docs/proxmox.md](docs/proxmox.md).

## WebSocket Terminal Service

The WebSocket terminal server is started through Artisan:

```bash
php artisan terminal:websocket-server
```

For production, run it under systemd rather than `nohup`.

## systemd Service Example

Create `/etc/systemd/system/pam-terminal-websocket.service`:

```ini
[Unit]
Description=PAM Terminal WebSocket Server
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/pam-zero-trust-virtual-lab
ExecStart=/usr/bin/php artisan terminal:websocket-server
Restart=always
RestartSec=5
Environment=APP_ENV=production

[Install]
WantedBy=multi-user.target
```

Enable it:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now pam-terminal-websocket
sudo systemctl status pam-terminal-websocket
```

## Nginx Reverse Proxy Example

This is a placeholder example. Adjust paths, PHP-FPM version, domains, and TLS for the target server.

```nginx
server {
    listen 80;
    server_name pam.example.com;
    root /var/www/pam-zero-trust-virtual-lab/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location /index.php {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
    }

    location /ws/terminal {
        proxy_pass http://127.0.0.1:8090;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
        proxy_read_timeout 3600;
    }
}
```

When using Cloudflare Tunnel, the tunnel can route the public hostname to this local Nginx service without opening public ports.

## Scheduler Setup

If scheduled tasks are added or enabled for cleanup, configure Laravel's scheduler:

```bash
* * * * * cd /var/www/pam-zero-trust-virtual-lab && php artisan schedule:run >> /dev/null 2>&1
```

Session expiration is also enforced by application checks, but scheduled cleanup may be useful for production hygiene.

## Basic Workflows

1. User logs in through Cloudflare Zero Trust.
2. Student opens the PAM dashboard.
3. Student accesses a Shared Practical VM through PAM Terminal.
4. Student creates a Self-Service VM from an enabled template.
5. Laravel provisions the VM through the Proxmox API.
6. QEMU Guest Agent detects the VM IP address.
7. SSH metadata is refreshed in Laravel.
8. Student opens the terminal.
9. Command and session activity is logged.
10. Admin monitors activity from the SOC dashboard.

## Security Notes

- Zero Trust access is required before reaching Laravel.
- Students do not receive direct Proxmox VE access.
- RBAC is enforced with `admin` as the only manager role and `student` as the regular role.
- VM ownership validation is enforced for student views and actions.
- Shared Practical VMs require explicit access grants.
- Critical/system/protected VMs are blocked from unsafe actions.
- Terminal sessions can expire and can be revoked by admins.
- Monitored command mode restricts unsafe commands.
- Interactive PTY terminal is limited to Self-Service VMs.
- No real SSH secrets are stored in this repository or seeders.
- Sensitive credentials must be stored in `.env` or encrypted metadata only.

## More Documentation

- [Architecture](docs/architecture.md)
- [Cloudflare](docs/cloudflare.md)
- [Proxmox](docs/proxmox.md)
- [Terminal](docs/terminal.md)
- [Shared Practical VM](docs/shared-practical-vm.md)
- [Self-Service VM](docs/self-service-vm.md)
- [Security](docs/security.md)
- [Debug Checklist](docs/debug-checklist.md)
