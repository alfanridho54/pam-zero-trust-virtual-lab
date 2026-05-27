<?php

namespace App\Console\Commands;

use App\Enums\TerminalSessionStatus;
use App\Models\TerminalSession;
use App\Models\Vm;
use App\Services\SshCommandService;
use Illuminate\Console\Command;

class TestTerminalSsh extends Command
{
    protected $signature = 'terminal:test-ssh {proxmox_vmid}';

    protected $description = 'Test PAM terminal SSH credentials for a VM by Proxmox VMID.';

    public function handle(SshCommandService $sshCommandService): int
    {
        $vmid = (string) $this->argument('proxmox_vmid');
        $vm = $this->findVm($vmid);

        if (! $vm) {
            $this->error('VM not found for proxmox_id/proxmox_vmid '.$vmid.'.');

            return self::FAILURE;
        }

        $host = $vm->sshHost();

        if ($host === null) {
            $this->error('SSH host is not configured for VM '.$vm->id.'.');

            return self::FAILURE;
        }

        $terminalSession = new TerminalSession([
            'user_id' => $vm->user_id,
            'vm_id' => $vm->id,
            'node' => $vm->node,
            'proxmox_id' => $vm->proxmox_id,
            'vmid' => $vm->proxmoxVmid(),
            'ssh_host' => $host,
            'ssh_port' => $vm->sshPort(),
            'ssh_username' => $vm->sshUsername(),
            'status' => TerminalSessionStatus::Pending,
            'metadata' => ['source' => 'terminal-test-ssh'],
        ]);
        $terminalSession->setRelation('vm', $vm);

        $summary = $sshCommandService->safeConnectionSummary($terminalSession);

        $this->line('host: '.$summary['host']);
        $this->line('port: '.$summary['port']);
        $this->line('username: '.$summary['username']);
        $this->line('credential_source: '.$summary['credential_source']);

        $result = $sshCommandService->execute($terminalSession, 'whoami');

        if ($result->successful) {
            $this->info('auth: success');
            $this->line('whoami: '.trim($result->output));

            return self::SUCCESS;
        }

        $this->error('auth: failed');
        $this->line('error: '.($result->error ?: 'SSH command execution failed.'));

        return self::FAILURE;
    }

    private function findVm(string $vmid): ?Vm
    {
        return Vm::query()
            ->get()
            ->first(function (Vm $vm) use ($vmid): bool {
                if ((string) $vm->proxmox_id === $vmid) {
                    return true;
                }

                return is_numeric($vmid) && $vm->proxmoxVmid() === (int) $vmid;
            });
    }
}
