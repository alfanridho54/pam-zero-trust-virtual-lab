# Debug Checklist

## WebSocket Terminal

```bash
sudo systemctl restart pam-terminal-websocket
sudo systemctl status pam-terminal-websocket
sudo journalctl -u pam-terminal-websocket -f
ss -ltnp | grep 8090
```

Avoid running a separate `nohup php artisan terminal:websocket-server` process if the systemd service is active.

## Laravel Cache And Routes

```bash
php artisan optimize:clear
php artisan route:list
php artisan view:cache
```

## Database

```bash
php artisan migrate
php artisan db:seed
php artisan test
```

Check Shared Practical VM grants:

```sql
select * from practical_vm_accesses where vm_id = <vm_id>;
```

Verify VM metadata includes expected flags:

```json
{
  "shared_practical": true,
  "managed_assignment": true
}
```

## SSH From Jump Server

Manually test SSH reachability from the jump server:

```bash
ssh student@<vm-ip>
```

Verify VM metadata:

- `ssh_host`
- `ssh_username`
- `ssh_password` only if safely configured outside the repository
- `ssh_port`

## QEMU Guest Agent

In Proxmox, confirm the VM has QEMU Guest Agent enabled and running. If IP detection fails:

- check guest agent installation inside the VM
- check VM network configuration
- check Proxmox API token permissions
- retry SSH metadata refresh from the dashboard

## Shared Practical VM

Confirm:

- VM has `metadata.shared_practical = true`
- VM has `metadata.managed_assignment = true`
- `practical_vm_accesses` exists for the student
- VM is not deleted, critical, system, or protected

## Common Recovery Commands

```bash
php artisan optimize:clear
php artisan migrate
php artisan test
sudo systemctl restart php8.2-fpm
sudo systemctl restart nginx
sudo systemctl restart pam-terminal-websocket
```
