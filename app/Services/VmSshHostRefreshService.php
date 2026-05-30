<?php

namespace App\Services;

use App\Models\TerminalSession;
use App\Models\Vm;
use Illuminate\Support\Facades\Log;
use Throwable;

class VmSshHostRefreshService
{
    public function __construct(
        private readonly ProxmoxService $proxmox,
    ) {
    }

    public function refreshVm(Vm $vm): ?string
    {
        $vmid = $vm->proxmoxVmid();
        $existingHost = $vm->getResolvedSshHost();

        if ($vmid === null) {
            $this->logFallback($vm, null, $existingHost, 'VM has no Proxmox VMID metadata.');

            return $existingHost;
        }

        try {
            $detectedIp = $this->proxmox->detectGuestIpv4($vm->node, $vmid);
        } catch (Throwable $exception) {
            $this->logFallback($vm, $vmid, $existingHost, 'Proxmox guest agent IP refresh failed.', $exception);

            return $existingHost;
        }

        if (! is_string($detectedIp) || trim($detectedIp) === '') {
            $this->logFallback($vm, $vmid, $existingHost, 'Proxmox guest agent did not return an IPv4 address.');

            return $existingHost;
        }

        $detectedIp = trim($detectedIp);
        $metadata = $vm->metadata ?? [];
        $oldIp = $metadata['ssh_host'] ?? $metadata['ip_address'] ?? $existingHost;

        if ($oldIp !== $detectedIp) {
            Log::info('VM SSH host refreshed from Proxmox guest agent.', [
                'vm_id' => $vm->id,
                'proxmox_vmid' => $vmid,
                'old_ip' => $oldIp,
                'new_ip' => $detectedIp,
            ]);
        }

        if (($metadata['ssh_host'] ?? null) !== $detectedIp || ($metadata['ip_address'] ?? null) !== $detectedIp) {
            $vm->forceFill([
                'metadata' => [
                    ...$metadata,
                    'ssh_host' => $detectedIp,
                    'ip_address' => $detectedIp,
                ],
            ])->save();
        }

        return $detectedIp;
    }

    public function refreshSession(TerminalSession $terminalSession): ?string
    {
        $terminalSession->loadMissing('vm');

        if (! $terminalSession->vm) {
            return $terminalSession->ssh_host;
        }

        $host = $this->refreshVm($terminalSession->vm);

        if (is_string($host) && trim($host) !== '' && $terminalSession->ssh_host !== $host) {
            $terminalSession->forceFill([
                'ssh_host' => $host,
                'vmid' => $terminalSession->vm->proxmoxVmid() ?? $terminalSession->vmid,
            ])->save();
        }

        return $host;
    }

    private function logFallback(Vm $vm, ?int $vmid, ?string $existingHost, string $reason, ?Throwable $exception = null): void
    {
        Log::warning('VM SSH host refresh unavailable; falling back to existing metadata.', [
            'vm_id' => $vm->id,
            'proxmox_vmid' => $vmid,
            'existing_ssh_host' => $existingHost,
            'reason' => $reason,
            'exception_class' => $exception ? $exception::class : null,
            'exception_message' => $exception?->getMessage(),
        ]);
    }
}
