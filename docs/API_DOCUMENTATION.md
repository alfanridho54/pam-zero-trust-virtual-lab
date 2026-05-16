# Mock Proxmox PAM API Documentation

Base URL: `/api`

Mock mode memakai `owner_id` atau `user_id` pada request untuk menentukan user aktif. Data seed utama:

- `owner_id=1` admin: `admin@lab.test`
- `owner_id=2` guru: `teacher@lab.test`
- `owner_id=3` siswa: `siswa1@lab.test`
- `owner_id=4` siswa: `siswa2@lab.test`

## Lab Templates

### GET `/lab-templates`

Mengambil daftar template praktikum aktif.

Example response:

```json
{
  "data": {
    "data": [
      {
        "id": 1,
        "name": "Linux Basic",
        "description": "Template dasar Linux untuk latihan terminal dan administrasi sistem.",
        "proxmox_template_id": "tpl-linux-basic",
        "node": "pve-mock",
        "cpu_cores": 1,
        "memory_mb": 1024,
        "disk_gb": 10,
        "is_active": true
      }
    ],
    "total": 4
  }
}
```

## VM Pribadi

### GET `/vms`

Mengambil semua VM yang belum dihapus.

Query opsional:

- `owner_id`: filter VM berdasarkan pemilik.
- `per_page`: jumlah data per halaman.

Example request:

```http
GET /api/vms?owner_id=3
```

Example response:

```json
{
  "data": {
    "data": [
      {
        "id": 1,
        "user_id": 3,
        "name": "VM Siswa 1",
        "proxmox_id": "vm-abc123",
        "node": "pve-mock",
        "status": "running",
        "cpu_cores": 1,
        "memory_mb": 1024,
        "disk_gb": 10
      }
    ],
    "total": 1
  }
}
```

### POST `/vms`

Membuat VM pribadi milik student/user tertentu.

Example request:

```json
{
  "owner_id": 3,
  "name": "VM Siswa 1",
  "cpu_cores": 1,
  "memory_mb": 1024,
  "disk_gb": 10
}
```

Example success response:

```json
{
  "data": {
    "id": 1,
    "user_id": 3,
    "name": "VM Siswa 1",
    "status": "running",
    "cpu_cores": 1,
    "memory_mb": 1024,
    "disk_gb": 10
  }
}
```

Example quota error response:

```json
{
  "message": "Kuota VM sudah penuh."
}
```

### GET `/vms/{id}`

Mengambil detail VM. Kirim `owner_id` agar mock mode dapat memvalidasi pemilik.

Example request:

```http
GET /api/vms/1?owner_id=3
```

### PUT `/vms/{id}`

Mengubah VM milik user yang sama. Student tidak bisa mengubah VM milik student lain.

Example request:

```json
{
  "owner_id": 3,
  "cpu_cores": 2,
  "memory_mb": 2048,
  "disk_gb": 20
}
```

Example response:

```json
{
  "data": {
    "id": 1,
    "user_id": 3,
    "name": "VM Siswa 1",
    "status": "running",
    "cpu_cores": 2,
    "memory_mb": 2048,
    "disk_gb": 20
  }
}
```

Forbidden response jika bukan pemilik:

```json
{
  "message": "This action is unauthorized."
}
```

### DELETE `/vms/{id}`

Melakukan soft delete VM milik user yang sama. Data tetap ada di database dengan `deleted_at` terisi.

Example request:

```json
{
  "owner_id": 3
}
```

Example response:

```json
{
  "message": "VM berhasil dihapus."
}
```

## Lab Access

### GET `/lab-access`

Mengambil VM praktikum milik user.

Example request:

```http
GET /api/lab-access?owner_id=3
```

### POST `/lab-access/{labTemplate}`

Membuat atau mengambil VM praktikum dari template tertentu.

Example request:

```json
{
  "owner_id": 3
}
```

Example response:

```json
{
  "data": {
    "id": 2,
    "user_id": 3,
    "lab_template_id": 1,
    "name": "Linux Basic - Siswa 1",
    "status": "running"
  }
}
```

## Audit Logs

### GET `/audit-logs`

Mengambil audit log. Dalam mock mode, tanpa `owner_id` akan memakai admin seed sebagai viewer default.

Query opsional:

- `user_id`: filter berdasarkan user.
- `vm_id`: filter berdasarkan VM.
- `action`: filter berdasarkan action.

Example request:

```http
GET /api/audit-logs?action=vm.created
```

Example response:

```json
{
  "data": {
    "data": [
      {
        "id": 1,
        "user_id": 3,
        "vm_id": 1,
        "action": "vm.created",
        "description": "User membuat VM pribadi.",
        "metadata": {
          "request": {
            "name": "VM Siswa 1",
            "cpu_cores": 1,
            "memory_mb": 1024,
            "disk_gb": 10
          }
        }
      }
    ],
    "total": 1
  }
}
```

### GET `/audit-logs/{id}`

Mengambil detail audit log.

Example request:

```http
GET /api/audit-logs/1
```
