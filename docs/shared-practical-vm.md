# Shared Practical VM

## Concept

A Shared Practical VM is one VM used by many students for a practical exercise. It is useful for demonstrations, shared lab tasks, and controlled command-mode access.

## Many Students, One VM

The VM can have `user_id = null` and still be available to selected students through access grants. Each student authenticates separately, and logs remain tied to that authenticated user.

## `practical_vm_accesses`

The `practical_vm_accesses` table links a VM to users who are allowed to access it. It also records the admin who assigned the access when available.

## Metadata

Important metadata flags:

- `shared_practical = true`
- `managed_assignment = true`

`shared_practical` means the VM can be accessed through grant validation. `managed_assignment` indicates the VM is managed by the lab/admin workflow rather than normal student Self-Service ownership.

## Quota Behavior

Shared Practical VMs do not count toward Self-Service VM quota. A student can use a shared practical VM and still keep their own Self-Service VM quota for individually owned labs.

## Access Validation Rule

A user can access a VM if:

```text
VM is owned by the user
OR
VM is shared_practical and the user has a practical_vm_accesses grant
```

The VM must also be student-visible and not blocked as system, critical, protected, deleted, or otherwise unsafe.

## Per-User Logs

Even though the VM is shared, audit logs, command logs, and terminal sessions remain associated with the authenticated user. This preserves accountability during shared exercises.
