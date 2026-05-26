<?php

namespace App\Services;

use App\Models\LabTemplate;
use App\Models\Vm;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ProxmoxService
{
    protected string $host;
    protected string $node;
    protected string $tokenId;
    protected string $tokenSecret;
    protected bool $verifySsl;

    public function __construct()
    {
        $this->host = rtrim((string) config('services.proxmox.host'), '/');
        $this->node = (string) config('services.proxmox.node');
        $this->tokenId = (string) config('services.proxmox.token_id');
        $this->tokenSecret = (string) config('services.proxmox.token_secret');
        $this->verifySsl = filter_var(config('services.proxmox.verify_ssl'), FILTER_VALIDATE_BOOLEAN);
    }

    protected function request()
    {
        // Semua request Proxmox memakai token API terpusat agar kredensial tidak tersebar di controller.
        return Http::timeout(15)
            ->acceptJson()
            ->withOptions([
                'verify' => $this->verifySsl,
            ])
            ->withHeaders([
                'Authorization' => "PVEAPIToken={$this->tokenId}={$this->tokenSecret}",
            ]);
    }

    protected function sendProxmoxRequest(string $method, string $endpoint, array $data = []): array
    {
        // Dipakai oleh action lifecycle VM nyata dari dashboard setelah ownership/protection lolos.
        if (! $this->isConfigured()) {
            return [
                'success' => true,
                'status' => 202,
                'message' => 'OK (mock Proxmox lifecycle action)',
                'data' => ['mock' => true, 'endpoint' => $endpoint],
            ];
        }

        try {
            $response = $this->request()
                ->asForm()
                ->{$method}($this->url($endpoint), $data);
        } catch (ConnectionException $exception) {
            return $this->failure('Tidak dapat terhubung ke Proxmox API.', 0, [
                'error' => $exception->getMessage(),
            ]);
        }

        return $this->normalizeResponse($response);
    }

    protected function sendProxmoxDeleteRequest(string $endpoint, array $query = []): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => true,
                'status' => 202,
                'message' => 'OK (mock Proxmox delete action)',
                'data' => ['mock' => true, 'endpoint' => $endpoint],
            ];
        }

        $url = $this->url($endpoint);

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        try {
            $response = $this->request()->delete($url);
        } catch (ConnectionException $exception) {
            return $this->failure('Tidak dapat terhubung ke Proxmox API.', 0, [
                'error' => $exception->getMessage(),
            ]);
        }

        return $this->normalizeResponse($response);
    }

    public function version(): array
    {
        return $this->apiRequest('get', '/version');
    }

    public function nodes(): array
    {
        return $this->apiRequest('get', '/nodes');
    }

    public function listVms(): array
    {
        return $this->apiRequest('get', '/cluster/resources', [
            'type' => 'vm',
        ]);
    }

    public function getVm(string $node, int|string $vmid): array
    {
        return $this->apiRequest('get', sprintf('/nodes/%s/qemu/%d/status/current', rawurlencode($node), (int) $vmid));
    }

    public function startVmById(string $node, int $vmid): array
    {
        return $this->sendProxmoxRequest('post', $this->qemuPath($node, $vmid, '/status/start'), []);
    }

    public function stopVmById(string $node, int $vmid): array
    {
        return $this->sendProxmoxRequest('post', $this->qemuPath($node, $vmid, '/status/stop'), []);
    }

    public function shutdownVmById(string $node, int $vmid): array
    {
        return $this->sendProxmoxRequest('post', $this->qemuPath($node, $vmid, '/status/shutdown'), []);
    }

    public function deleteVmById(string $node, int $vmid): array
    {
        return $this->sendProxmoxDeleteRequest($this->qemuPath($node, $vmid), [
            'purge' => 1,
        ]);
    }

    protected function vmPowerAction(string $node, int|string $vmid, string $action): array
    {
        return $this->apiRequest(
            'post',
            sprintf('/nodes/%s/qemu/%d/status/%s', rawurlencode($node), (int) $vmid, $action),
        );
    }

    protected function apiRequest(string $method, string $path, array $query = []): array
    {
        if (! $this->isConfigured()) {
            // Mode lab tetap aman saat kredensial Proxmox belum disiapkan.
            return $this->failure('Konfigurasi Proxmox belum lengkap.', 0);
        }

        try {
            /** @var Response $response */
            $response = $this->request()->{$method}($this->url($path), $query);
        } catch (ConnectionException $exception) {
            return $this->failure('Tidak dapat terhubung ke Proxmox API.', 0, [
                'error' => $exception->getMessage(),
            ]);
        }

        $json = $this->safeJson($response);
        $data = is_array($json) && array_key_exists('data', $json) ? $json['data'] : $json;

        if ($response->failed()) {
            return $this->failure($this->messageFromResponse($response, $json), $response->status(), $data);
        }

        return [
            'success' => true,
            'message' => 'OK',
            'status' => $response->status(),
            'data' => $data,
        ];
    }

    protected function url(string $path): string
    {
        return $this->host.'/api2/json/'.ltrim($path, '/');
    }

    protected function qemuPath(string $node, int|string $vmid, string $suffix = ''): string
    {
        return sprintf('/nodes/%s/qemu/%d%s', rawurlencode($node), (int) $vmid, $suffix);
    }

    protected function isConfigured(): bool
    {
        return $this->host !== '' && $this->tokenId !== '' && $this->tokenSecret !== '';
    }

    protected function messageFromResponse(Response $response, mixed $json): string
    {
        if (is_array($json)) {
            $message = $json['message'] ?? $json['error'] ?? null;

            if (is_string($message)) {
                return $message;
            }

            if (isset($json['errors'])) {
                return 'Proxmox API request gagal: '.json_encode($json['errors']);
            }

            return 'Proxmox API request gagal.';
        }

        return $response->body() ?: 'Proxmox API request gagal.';
    }

    protected function normalizeResponse(Response $response): array
    {
        $json = $this->safeJson($response);
        $data = is_array($json) && array_key_exists('data', $json) ? $json['data'] : $json;

        if ($data === null && trim($response->body()) !== '') {
            $data = $response->body();
        }

        if ($response->failed()) {
            return $this->failure($this->messageFromResponse($response, $json), $response->status(), $data);
        }

        return [
            'success' => true,
            'status' => $response->status(),
            'message' => 'OK',
            'data' => $data,
        ];
    }

    protected function safeJson(Response $response): mixed
    {
        try {
            return $response->json();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function failure(string $message, int $status = 0, mixed $data = null): array
    {
        return [
            'success' => false,
            'message' => $message,
            'status' => $status,
            'data' => $data,
        ];
    }

    public function createVm(array $payload): array
    {
        // Implementasi mock menjaga flow ownership/audit dapat diuji tanpa membuat VM Proxmox nyata.
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
        $vmid = $vm->proxmoxVmid();

        if ($vmid !== null && $this->isConfigured()) {
            return $this->deleteVmById($vm->node, $vmid);
        }

        return [
            'proxmox_id' => $vm->proxmox_id,
            'deleted' => true,
        ];
    }

    public function cloneStudentVmFromTemplate(string $vmName): array
    {
        if (! $this->isConfigured()) {
            $templateVmid = (int) config('services.proxmox.student_template_vmid', 9000);
            $node = $this->node !== '' ? $this->node : 'pve-mock';
            $newVmid = $this->mockAvailableVmid();

            return [
                'success' => true,
                'message' => 'OK (mock Proxmox template clone)',
                'status' => 202,
                'data' => ['mock' => true],
                'proxmox_id' => (string) $newVmid,
                'node' => $node,
                'vmid' => $newVmid,
                'name' => $vmName,
                'source_template_vmid' => $templateVmid,
            ];
        }

        $templateVmid = (int) config('services.proxmox.student_template_vmid');
        $node = trim($this->node);

        if ($node === '') {
            return $this->failure('Konfigurasi PROXMOX_NODE belum diisi.', 0);
        }

        if ($templateVmid < 1) {
            return $this->failure('Konfigurasi PROXMOX_STUDENT_TEMPLATE_VMID tidak valid.', 0);
        }

        $template = $this->apiRequest('get', $this->qemuPath($node, $templateVmid, '/config'));

        if (! ($template['success'] ?? false)) {
            return $this->failure(
                'Template Proxmox VMID '.$templateVmid.' pada node '.$node.' tidak dapat diakses: '.($template['message'] ?? 'Proxmox API request gagal.'),
                $template['status'] ?? 0,
                $template['data'] ?? null,
            );
        }

        if (! filter_var($template['data']['template'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return $this->failure('VMID '.$templateVmid.' pada node '.$node.' bukan Proxmox template.', 422, $template['data'] ?? null);
        }

        $allocation = $this->allocateStudentVmid();

        if (! ($allocation['success'] ?? false)) {
            return $allocation;
        }

        $newVmid = (int) $allocation['vmid'];
        $payload = [
            'newid' => $newVmid,
            'name' => $vmName,
            'full' => filter_var(config('services.proxmox.student_clone_full', true), FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        ];

        $storage = config('services.proxmox.student_storage');

        if (is_string($storage) && $storage !== '') {
            $payload['storage'] = $storage;
        }

        $clone = $this->sendProxmoxRequest('post', $this->qemuPath($node, $templateVmid, '/clone'), $payload);

        if (! ($clone['success'] ?? false)) {
            $clone['message'] = $this->cloneFailureMessage($clone, $templateVmid, $node);
        } elseif (filter_var(config('services.proxmox.student_wait_for_clone', false), FILTER_VALIDATE_BOOLEAN)) {
            $task = $this->waitForTask($node, $clone['data'] ?? null);

            if (! ($task['success'] ?? false)) {
                return [
                    ...$task,
                    'proxmox_id' => (string) $newVmid,
                    'node' => $node,
                    'vmid' => $newVmid,
                    'name' => $vmName,
                    'source_template_vmid' => $templateVmid,
                    'request' => $payload,
                    'task_upid' => $clone['data'] ?? null,
                ];
            }

            $nameUpdate = $this->setVmConfig($node, $newVmid, [
                'name' => $vmName,
            ]);

            if (! ($nameUpdate['success'] ?? false)) {
                $clone['name_update'] = $nameUpdate;
                $clone['message'] = 'OK (clone selesai, tetapi update nama VM di Proxmox gagal: '
                    .($nameUpdate['message'] ?? 'Proxmox API request gagal.').')';
            }
        }

        return [
            ...$clone,
            'proxmox_id' => (string) $newVmid,
            'node' => $node,
            'vmid' => $newVmid,
            'name' => $vmName,
            'source_template_vmid' => $templateVmid,
            'request' => $payload,
            'vmid_allocation' => $allocation['attempts'] ?? [],
            'task_upid' => $clone['data'] ?? null,
            'local_status' => filter_var(config('services.proxmox.student_wait_for_clone', false), FILTER_VALIDATE_BOOLEAN)
                ? 'stopped'
                : 'provisioning',
        ];
    }

    public function setVmConfig(string $node, int $vmid, array $payload): array
    {
        return $this->sendProxmoxRequest('post', $this->qemuPath($node, $vmid, '/config'), $payload);
    }

    public function getTaskStatus(string $node, string $upid): array
    {
        return $this->apiRequest('get', sprintf(
            '/nodes/%s/tasks/%s/status',
            rawurlencode($node),
            rawurlencode($upid),
        ));
    }

    private function waitForTask(string $node, mixed $upid): array
    {
        if (! is_string($upid) || $upid === '') {
            return [
                'success' => true,
                'message' => 'OK (tidak ada UPID task untuk dipoll)',
                'status' => 200,
                'data' => null,
            ];
        }

        $timeoutAt = time() + max(1, (int) config('services.proxmox.task_timeout', 60));
        $lastResponse = null;

        do {
            $lastResponse = $this->getTaskStatus($node, $upid);

            if (! ($lastResponse['success'] ?? false)) {
                return $lastResponse;
            }

            $task = $lastResponse['data'] ?? [];

            if (($task['status'] ?? null) === 'stopped') {
                if (($task['exitstatus'] ?? null) === 'OK') {
                    return [
                        'success' => true,
                        'message' => 'OK',
                        'status' => $lastResponse['status'] ?? 200,
                        'data' => $task,
                    ];
                }

                return $this->failure('Task Proxmox clone gagal: '.($task['exitstatus'] ?? 'unknown'), $lastResponse['status'] ?? 0, $task);
            }

            sleep(1);
        } while (time() < $timeoutAt);

        return $this->failure('Timeout menunggu task Proxmox clone selesai.', $lastResponse['status'] ?? 0, $lastResponse['data'] ?? null);
    }

    private function cloneFailureMessage(array $clone, int $templateVmid, string $node): string
    {
        $message = $clone['message'] ?? 'Proxmox clone request gagal.';

        if (in_array((int) ($clone['status'] ?? 0), [401, 403], true)) {
            $message .= ' Pastikan token Proxmox memiliki permission VM.Clone pada template VMID '
                .$templateVmid.', VM.Allocate pada target, serta Datastore.AllocateSpace pada storage tujuan di node '
                .$node.'.';
        }

        return $message;
    }

    private function allocateStudentVmid(): array
    {
        $attempts = [];
        $candidate = null;
        $maxAttempts = max(1, (int) config('services.proxmox.vmid_allocation_attempts', 25));

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $candidate = $candidate === null
                ? $this->nextProxmoxVmid()
                : $this->nextSequentialVmid($candidate);

            if (! ($candidate['success'] ?? false)) {
                return $candidate;
            }

            $vmid = (int) $candidate['vmid'];
            $reservedLocally = $this->localVmidExists($vmid);
            $existsInProxmox = $this->proxmoxVmidExists($vmid);

            $attempts[] = [
                'vmid' => $vmid,
                'local_reserved' => $reservedLocally,
                'proxmox_exists' => $existsInProxmox,
            ];

            if (! $reservedLocally && ! $existsInProxmox) {
                return [
                    'success' => true,
                    'vmid' => $vmid,
                    'attempts' => $attempts,
                ];
            }
        }

        return $this->failure('Tidak dapat menemukan VMID Proxmox yang aman untuk lokal dan cluster.', 409, [
            'attempts' => $attempts,
        ]);
    }

    private function mockAvailableVmid(): int
    {
        do {
            $vmid = random_int(200000, 299999);
        } while ($this->localVmidExists($vmid));

        return $vmid;
    }

    private function nextProxmoxVmid(): array
    {
        $nextId = $this->apiRequest('get', '/cluster/nextid');

        if (! ($nextId['success'] ?? false) || ! is_numeric($nextId['data'] ?? null)) {
            return $this->failure($nextId['message'] ?? 'Tidak dapat mengambil VMID baru dari Proxmox.', $nextId['status'] ?? 0, $nextId['data'] ?? null);
        }

        return [
            'success' => true,
            'vmid' => (int) $nextId['data'],
        ];
    }

    private function nextSequentialVmid(array $candidate): array
    {
        return [
            'success' => true,
            'vmid' => ((int) $candidate['vmid']) + 1,
        ];
    }

    private function localVmidExists(int $vmid): bool
    {
        return Vm::withTrashed()
            ->get()
            ->contains(fn (Vm $vm) => $vm->proxmoxVmid() === $vmid);
    }

    private function proxmoxVmidExists(int $vmid): bool
    {
        if ($this->node === '') {
            return false;
        }

        $response = $this->getVm($this->node, $vmid);

        return (bool) ($response['success'] ?? false);
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
