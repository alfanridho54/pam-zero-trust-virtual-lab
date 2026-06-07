# Proxmox VE Integration

## API Integration

Laravel communicates with Proxmox VE through the Proxmox API. The API is used for VM inventory, lifecycle actions, template cloning, task status checks, VMID allocation, and QEMU Guest Agent IP detection.

Students do not receive Proxmox UI access, Proxmox API tokens, or direct hypervisor permissions.

## Required Environment Variables

```env
PROXMOX_HOST=https://pve.example.internal:8006
PROXMOX_NODE=pve
PROXMOX_TOKEN_ID=laravel@pve!pam-lab
PROXMOX_TOKEN_SECRET=store-real-secret-only-in-env
PROXMOX_STUDENT_TEMPLATE_VMID=9000
PROXMOX_VMID_ALLOCATION_ATTEMPTS=25
```

Additional optional variables may control SSL verification, clone behavior, storage, task timeout, and waiting behavior.

## VM Monitoring

The dashboard can list real Proxmox VMs and correlate them with local VM records. Local records remain important because they hold ownership, metadata, grants, and audit relationships.

## Lifecycle Operations

Allowed lifecycle actions are mediated by Laravel. Admins can perform dashboard lifecycle actions where allowed. Students can start, shutdown, or delete only owned Self-Service VMs through student flows. Protected/system/critical VMs remain blocked from unsafe actions.

## Template Cloning

Self-Service VM provisioning clones from a configured Proxmox template VMID. Laravel stores local VM metadata such as source template VMID, task UPID, detected IP address, SSH host, and provisioning status.

## VMID Allocation Safety

The allocator uses Proxmox next-id behavior and local collision checks. `PROXMOX_VMID_ALLOCATION_ATTEMPTS` controls how many allocation attempts can be made before failing safely.

## QEMU Guest Agent IP Detection

After provisioning or refresh, Laravel can ask the QEMU Guest Agent for network interfaces and choose a usable IPv4 address. Loopback, link-local, and unusable addresses should be ignored.

## SSH Metadata Refresh

When the guest IP is detected, Laravel can update VM metadata:

- `ssh_host`
- `ip_address`
- `ssh_port`
- optional username from template metadata

Real SSH secrets must not be committed. Use `.env` fallback for lab-wide placeholders or encrypted/safe metadata for production.

## Protected/System VM Handling

Infrastructure VMIDs and metadata flags such as `system_vm`, `critical`, and `protected` prevent unsafe actions. These protections apply even if a VM is visible to admins for monitoring.
