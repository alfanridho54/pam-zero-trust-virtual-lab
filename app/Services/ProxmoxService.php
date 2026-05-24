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
    $response = $this->request()
        ->asForm()
        ->{$method}("{$this->host}/api2/json{$endpoint}", $data);

    if ($response->failed()) {
        return [
            'success' => false,
            'status' => $response->status(),
            'message' => $response->body(),
            'data' => $response->json(),
        ];
    }

    return [
        'success' => true,
        'status' => $response->status(),
        'message' => 'OK',
        'data' => $response->json(),
    ];
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
        return $this->sendProxmoxRequest('post', "/nodes/{$node}/qemu/{$vmid}/status/start", []);
    }

    public function stopVmById(string $node, int $vmid): array
    {
        return $this->sendProxmoxRequest('post', "/nodes/{$node}/qemu/{$vmid}/status/stop", []);
    }

    public function shutdownVmById(string $node, int $vmid): array
    {
        return $this->sendProxmoxRequest('post', "/nodes/{$node}/qemu/{$vmid}/status/shutdown", []);
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

        $json = $response->json();
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
