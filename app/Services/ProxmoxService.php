<?php

namespace App\Services;

use App\Models\LabTemplate;
use App\Models\Vm;
use Illuminate\Support\Str;

class ProxmoxService
{
    public function createVm(array $payload): array
    {
        return [
            'proxmox_id' => $payload['proxmox_id'] ?? 'vm-'.Str::lower(Str::random(10)),
            'node' => $payload['node'] ?? 'pve-mock',
            'status' => 'running',
        ];
    }

    public function updateVm(Vm $vm, array $payload): array
    {
        return [
            'proxmox_id' => $vm->proxmox_id,
            'node' => $payload['node'] ?? $vm->node,
            'status' => $payload['status'] ?? $vm->status,
        ];
    }

    public function deleteVm(Vm $vm): array
    {
        return [
            'proxmox_id' => $vm->proxmox_id,
            'deleted' => true,
        ];
    }

    public function cloneFromTemplate(LabTemplate $template, string $vmName): array
    {
        return [
            'proxmox_id' => 'lab-'.Str::lower(Str::random(10)),
            'name' => $vmName,
            'node' => $template->node,
            'status' => 'running',
            'source_template_id' => $template->proxmox_template_id,
        ];
    }
}
