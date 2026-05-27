<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\VmTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VmTemplateController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $this->authorizedTemplateManager($request);
        $data = $this->validatedData($request);

        $template = VmTemplate::create($data);

        $this->audit($user, $template, 'vm_template.created', 'VM template dibuat.');

        return back()->with('status', 'VM template berhasil dibuat.');
    }

    public function update(Request $request, VmTemplate $vmTemplate): RedirectResponse
    {
        $user = $this->authorizedTemplateManager($request);
        $data = $this->validatedData($request, updating: true);

        if (! $request->filled('ssh_password')) {
            unset($data['ssh_password']);
        }

        $vmTemplate->update($data);

        $this->audit($user, $vmTemplate, 'vm_template.updated', 'VM template diperbarui.', [
            'updated_keys' => array_keys($data),
        ]);

        return back()->with('status', 'VM template berhasil diperbarui.');
    }

    public function destroy(Request $request, VmTemplate $vmTemplate): RedirectResponse
    {
        $user = $this->authorizedTemplateManager($request);

        if ($vmTemplate->vms()->exists()) {
            $vmTemplate->update(['enabled' => false]);
            $this->audit($user, $vmTemplate, 'vm_template.disabled', 'VM template dinonaktifkan karena sudah dipakai VM.');

            return back()->with('status', 'VM template sudah dipakai VM, jadi dinonaktifkan.');
        }

        $templateId = $vmTemplate->id;
        $vmTemplate->delete();

        AuditLog::create([
            'user_id' => $user->id,
            'vm_id' => null,
            'action' => 'vm_template.deleted',
            'description' => 'VM template dihapus.',
            'metadata' => [
                'source' => 'vm-template-management',
                'vm_template_id' => $templateId,
            ],
        ]);

        return back()->with('status', 'VM template berhasil dihapus.');
    }

    private function validatedData(Request $request, bool $updating = false): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'proxmox_template_id' => ['required', 'integer', 'min:1'],
            'proxmox_node' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/'],
            'cpu' => ['required', 'integer', 'min:1', 'max:256'],
            'ram' => ['required', 'integer', 'min:128', 'max:1048576'],
            'disk' => ['required', 'integer', 'min:1', 'max:1048576'],
            'ssh_username' => ['required', 'string', 'max:255'],
            'ssh_password' => [$updating ? 'nullable' : 'nullable', 'string', 'max:4096'],
            'enabled' => ['nullable', 'boolean'],
        ];

        $data = $request->validate($rules);
        $data['enabled'] = $request->boolean('enabled');

        return $data;
    }

    private function authorizedTemplateManager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->role, ['admin', 'guru', 'teacher'], true), 403, 'Anda tidak memiliki akses template VM.');

        return $user;
    }

    private function audit(User $user, VmTemplate $template, string $action, string $description, array $metadata = []): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'vm_id' => null,
            'action' => $action,
            'description' => $description,
            'metadata' => [
                'source' => 'vm-template-management',
                'vm_template_id' => $template->id,
                'enabled' => $template->enabled,
                ...$metadata,
            ],
        ]);
    }
}
