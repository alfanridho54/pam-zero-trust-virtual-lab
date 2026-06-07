# Self-Service VM

## Concept

A Self-Service VM is a student-owned VM provisioned from an enabled template. It gives students isolated lab resources without direct access to Proxmox VE.

## Ownership

The VM has `user_id` set to the student owner. Students can view and manage only their own Self-Service VMs, subject to quota and protected VM rules.

## Template Selection

Admins manage VM templates. Students see enabled templates and can request a new VM from one of them.

## Provisioning Flow

```text
student request
-> Laravel PAM
-> Proxmox API
-> nextid / safe VMID allocation
-> clone template
-> provisioning task UPID
-> VM boot
-> QEMU Guest Agent IP detection
-> SSH metadata auto-fill
-> PAM terminal ready
```

Laravel records the local VM, ownership, template source, Proxmox VMID, task metadata, status, and SSH metadata needed for terminal readiness.

## Quota Enforcement

Self-Service VMs count toward quota. Quota can limit:

- number of VMs
- CPU cores
- memory
- storage

Managed assignments and Shared Practical VMs are excluded from Self-Service quota calculations.

## Delete Safety

Students can delete only owned Self-Service VMs where allowed. Protected/system/critical VMs are blocked from unsafe deletion. Local records use soft delete behavior so audit history can remain available.

## No Direct Proxmox Access

Students do not receive Proxmox credentials. Laravel performs provisioning and lifecycle actions through its backend API integration after checking authorization.
