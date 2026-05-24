<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard PAM Proxmox</title>
    <style>
        :root {
            --bg: #f4f7fb;
            --panel: #ffffff;
            --line: #e5e7eb;
            --muted: #64748b;
            --text: #0f172a;
            --blue: #2563eb;
            --indigo: #4f46e5;
            --green: #16a34a;
            --amber: #d97706;
            --red: #dc2626;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        a { color: inherit; text-decoration: none; }
        button { font: inherit; }
        .shell { display: flex; min-height: 100vh; }
        .sidebar {
            width: 260px;
            background: #0f172a;
            color: #e2e8f0;
            padding: 24px 18px;
            position: sticky;
            top: 0;
            height: 100vh;
        }
        .brand {
            display: flex;
            gap: 12px;
            align-items: center;
            padding: 4px 8px 28px;
        }
        .brand-mark {
            display: grid;
            place-items: center;
            width: 42px;
            height: 42px;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--blue), var(--indigo));
            color: white;
            font-weight: 800;
        }
        .brand-title { font-size: 15px; font-weight: 800; }
        .brand-subtitle { color: #94a3b8; font-size: 12px; margin-top: 2px; }
        .nav { display: grid; gap: 8px; }
        .nav a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 12px;
            border-radius: 8px;
            color: #cbd5e1;
            font-size: 14px;
            font-weight: 650;
        }
        .nav a.active, .nav a:hover { background: #1e293b; color: white; }
        .main { flex: 1; min-width: 0; }
        .header {
            height: 76px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 32px;
            background: rgba(255,255,255,.9);
            border-bottom: 1px solid var(--line);
            backdrop-filter: blur(10px);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .page-title { margin: 0; font-size: 22px; font-weight: 800; letter-spacing: 0; }
        .page-subtitle { margin-top: 4px; color: var(--muted); font-size: 13px; }
        .content { padding: 28px 32px 48px; }
        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }
        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .06);
        }
        .stat { padding: 18px; }
        .stat-label { color: var(--muted); font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .stat-value { margin-top: 8px; font-size: 30px; font-weight: 850; }
        .grid-two { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .section { margin-top: 18px; overflow: hidden; }
        .section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 20px;
            border-bottom: 1px solid var(--line);
        }
        .section-title { margin: 0; font-size: 16px; font-weight: 800; }
        .section-note { margin-top: 3px; color: var(--muted); font-size: 13px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 760px; }
        th {
            color: #475569;
            background: #f8fafc;
            font-size: 12px;
            text-align: left;
            text-transform: uppercase;
            padding: 12px 16px;
            border-bottom: 1px solid var(--line);
        }
        td {
            padding: 14px 16px;
            border-bottom: 1px solid #eef2f7;
            vertical-align: middle;
            font-size: 14px;
        }
        tr:last-child td { border-bottom: 0; }
        .muted { color: var(--muted); }
        .badge {
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            text-transform: capitalize;
        }
        .badge-running { background: #dcfce7; color: #166534; }
        .badge-stopped { background: #fef3c7; color: #92400e; }
        .badge-deleted { background: #fee2e2; color: #991b1b; }
        .badge-other { background: #e0e7ff; color: #3730a3; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 0 13px;
            border: 1px solid transparent;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 800;
            white-space: nowrap;
        }
        .btn-primary { background: var(--blue); color: white; }
        .btn-soft { background: #eef2ff; color: #3730a3; border-color: #c7d2fe; }
        .btn-danger { background: #fff1f2; color: #be123c; border-color: #fecdd3; }
        .btn:disabled { cursor: not-allowed; opacity: .45; }
        .flash {
            margin-bottom: 18px;
            padding: 12px 14px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1d4ed8;
            border-radius: 8px;
            font-weight: 700;
            font-size: 14px;
        }
        .flash-error {
            background: #fff1f2;
            border-color: #fecdd3;
            color: #be123c;
        }
        .feature-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; margin-bottom: 22px; }
        .feature { padding: 22px; border-left: 4px solid var(--blue); }
        .feature:nth-child(2) { border-left-color: var(--indigo); }
        .feature h3 { margin: 0 0 8px; font-size: 18px; }
        .feature p { margin: 0 0 18px; color: var(--muted); line-height: 1.55; }

        @media (max-width: 980px) {
            .shell { display: block; }
            .sidebar { width: auto; height: auto; position: relative; }
            .stats, .grid-two, .feature-grid { grid-template-columns: 1fr; }
            .header { position: relative; padding: 18px; height: auto; align-items: flex-start; gap: 14px; }
            .content { padding: 18px; }
        }
    </style>
</head>
<body>
@php
    $title = match ($section) {
        'templates' => 'Akses VM Praktikum',
        'vms' => 'Kelola Lab Pribadi',
        'audit-logs' => 'Audit Log',
        default => 'Dashboard PAM Proxmox',
    };

    $navItems = [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'section' => 'overview'],
        ['label' => 'Akses VM Praktikum', 'route' => 'dashboard.templates', 'section' => 'templates'],
        ['label' => 'Kelola Lab Pribadi', 'route' => 'dashboard.vms', 'section' => 'vms'],
        ['label' => 'Audit Logs', 'route' => 'dashboard.audit-logs', 'section' => 'audit-logs'],
    ];

    $statusClass = fn ($vm) => $vm->trashed() ? 'badge-deleted' : ($vm->status === 'running' ? 'badge-running' : ($vm->status === 'stopped' ? 'badge-stopped' : 'badge-other'));
    $statusLabel = fn ($vm) => $vm->trashed() ? 'deleted' : $vm->status;
    $realStatusClass = fn ($status) => $status === 'running' ? 'badge-running' : ($status === 'stopped' ? 'badge-stopped' : 'badge-other');
@endphp
<div class="shell">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-mark">PAM</div>
            <div>
                <div class="brand-title">Mock Proxmox Lab</div>
                <div class="brand-subtitle">Zero Trust Dashboard</div>
            </div>
        </div>
        <nav class="nav">
            @foreach ($navItems as $item)
                <a href="{{ route($item['route']) }}" class="{{ $section === $item['section'] ? 'active' : '' }}">{{ $item['label'] }}</a>
            @endforeach
        </nav>
    </aside>

    <main class="main">
        <header class="header">
            <div>
                <h1 class="page-title">{{ $title }}</h1>
                <div class="page-subtitle">Monitoring lab, VM praktikum, dan aktivitas pengguna Proxmox.</div>
            </div>
            <form method="POST" action="{{ route('dashboard.simulate.docker-lab') }}">
                @csrf
                <button class="btn btn-primary" type="submit">Create Docker Lab untuk owner_id=3</button>
            </form>
        </header>

        <div class="content">
            @if (session('status'))
                <div class="flash">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="flash flash-error">{{ session('error') }}</div>
            @endif

            <section class="stats">
                <div class="card stat">
                    <div class="stat-label">Total Template</div>
                    <div class="stat-value">{{ $stats['templates'] }}</div>
                </div>
                <div class="card stat">
                    <div class="stat-label">Total VM</div>
                    <div class="stat-value">{{ $stats['vms'] }}</div>
                </div>
                <div class="card stat">
                    <div class="stat-label">Total Audit Log</div>
                    <div class="stat-value">{{ $stats['auditLogs'] }}</div>
                </div>
                <div class="card stat">
                    <div class="stat-label">Total User</div>
                    <div class="stat-value">{{ $stats['users'] }}</div>
                </div>
            </section>

            @if ($section === 'overview')
                <section class="feature-grid">
                    <div class="card feature">
                        <h3>Akses VM Praktikum</h3>
                        <p>Daftar template lab yang disediakan guru untuk simulasi akses praktikum siswa.</p>
                        <a class="btn btn-soft" href="{{ route('dashboard.templates') }}">Buka Template</a>
                    </div>
                    <div class="card feature">
                        <h3>Kelola Lab Pribadi</h3>
                        <p>Kelola VM milik user, simulasi perubahan resource, dan soft delete untuk kebutuhan demo.</p>
                        <a class="btn btn-soft" href="{{ route('dashboard.vms') }}">Buka VM</a>
                    </div>
                </section>
            @endif

            @if (in_array($section, ['overview', 'templates'], true))
                <section class="card section">
                    <div class="section-head">
                        <div>
                            <h2 class="section-title">Template Lab</h2>
                            <div class="section-note">Template praktikum yang tersedia untuk siswa.</div>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>Nama</th>
                                <th>Proxmox Template</th>
                                <th>CPU</th>
                                <th>RAM</th>
                                <th>Disk</th>
                                <th>Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($templates as $template)
                                <tr>
                                    <td><strong>{{ $template->name }}</strong><div class="muted">{{ $template->description }}</div></td>
                                    <td>{{ $template->proxmox_template_id }}</td>
                                    <td>{{ $template->cpu_cores }} core</td>
                                    <td>{{ $template->memory_mb }} MB</td>
                                    <td>{{ $template->disk_gb }} GB</td>
                                    <td><span class="badge {{ $template->is_active ? 'badge-running' : 'badge-stopped' }}">{{ $template->is_active ? 'active' : 'inactive' }}</span></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            @if (in_array($section, ['overview', 'vms'], true))
                <section class="card section">
                    <div class="section-head">
                        <div>
                            <h2 class="section-title">Virtual Machine Proxmox</h2>
                            <div class="section-note">Daftar VM real dari Proxmox beserta status resource dan action power.</div>
                        </div>
                    </div>
                    @if (! ($realVmResponse['success'] ?? false))
                        <div class="flash" style="margin: 18px 20px;">
                            Proxmox API gagal dibaca: {{ $realVmResponse['message'] ?? 'Unknown error' }}
                        </div>
                    @endif
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>VMID</th>
                                <th>Name</th>
                                <th>Node</th>
                                <th>Owner</th>
                                <th>Ownership</th>
                                <th>Status</th>
                                <th>CPU</th>
                                <th>Memory Usage</th>
                                <th>Max Memory</th>
                                <th>Disk</th>
                                <th>Uptime</th>
                                <th>Aksi</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($realVms as $vm)
                                <tr>
                                    <td><strong>{{ $vm['vmid'] }}</strong></td>
                                    <td>{{ $vm['name'] }}</td>
                                    <td>{{ $vm['node'] }}</td>
                                    <td>
                                        {{ $vm['owner_name'] ?? '-' }}
                                        @if ($vm['owner_user_id'] && ! $vm['is_system_vm'])
                                            <div class="muted">user_id={{ $vm['owner_user_id'] }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($vm['ownership_status'] === 'system')
                                            <span class="badge badge-deleted">Critical/System</span>
                                        @elseif ($vm['ownership_status'] === 'owned')
                                            <span class="badge badge-running">owned</span>
                                        @else
                                            <span class="badge badge-stopped">Belum diassign</span>
                                        @endif
                                        @if ($vm['is_critical'] && ! $vm['is_system_vm'])
                                            <div class="muted">critical</div>
                                        @endif
                                    </td>
                                    <td><span class="badge {{ $realStatusClass($vm['status']) }}">{{ $vm['status'] }}</span></td>
                                    <td>{{ $vm['cpu'] }}</td>
                                    <td>{{ $vm['memory_usage'] }}</td>
                                    <td>{{ $vm['max_memory'] }}</td>
                                    <td>{{ $vm['disk'] }}</td>
                                    <td>{{ $vm['uptime'] }}</td>
                                    <td>
                                        <div class="actions">
                                            <form method="POST" action="{{ route('dashboard.proxmox.vms.action', [$vm['node'], $vm['vmid'], 'start']) }}">
                                                @csrf
                                                <button class="btn btn-soft" type="submit" @disabled(! $vm['can_control'] || $vm['status'] === 'running')>Start</button>
                                            </form>
                                            <form method="POST" action="{{ route('dashboard.proxmox.vms.action', [$vm['node'], $vm['vmid'], 'stop']) }}">
                                                @csrf
                                                <button class="btn btn-danger" type="submit" @disabled(! $vm['can_control'] || $vm['status'] !== 'running')>Stop</button>
                                            </form>
                                            <form method="POST" action="{{ route('dashboard.proxmox.vms.action', [$vm['node'], $vm['vmid'], 'shutdown']) }}">
                                                @csrf
                                                <button class="btn btn-soft" type="submit" @disabled(! $vm['can_control'] || $vm['status'] !== 'running')>Shutdown</button>
                                            </form>
                                            @if ($vm['local_vm_id'])
                                                <form method="POST" action="{{ route('terminal-sessions.store', $vm['local_vm_id']) }}">
                                                    @csrf
                                                    <button class="btn btn-primary" type="submit" @disabled(! $vm['can_control'])>Access Terminal</button>
                                                </form>
                                            @else
                                                <button class="btn btn-soft" type="button" disabled>Access Terminal</button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="12" class="muted">Belum ada VM real yang bisa ditampilkan dari Proxmox.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="card section">
                    <div class="section-head">
                        <div>
                            <h2 class="section-title">VM Lokal Demo</h2>
                            <div class="section-note">Data lokal untuk simulasi ownership, quota, RBAC, dan audit lama.</div>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>VM</th>
                                <th>Owner</th>
                                <th>Template</th>
                                <th>CPU</th>
                                <th>RAM</th>
                                <th>Disk</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($vms as $vm)
                                @php
                                    $isCriticalVm = filter_var($vm->metadata['critical'] ?? false, FILTER_VALIDATE_BOOLEAN);
                                    $isSystemVm = filter_var($vm->metadata['system_vm'] ?? false, FILTER_VALIDATE_BOOLEAN);
                                    $isProtectedVm = $isCriticalVm || $isSystemVm;
                                    $terminalBlocked = $vm->trashed() || $isProtectedVm;
                                @endphp
                                <tr>
                                    <td><strong>{{ $vm->name }}</strong><div class="muted">{{ $vm->proxmox_id }}</div></td>
                                    <td>
                                        {{ $isSystemVm ? 'System VM' : ($vm->user?->name ?? '-') }}
                                        @if (! $isSystemVm)
                                            <div class="muted">owner_id={{ $vm->user_id }}</div>
                                        @endif
                                    </td>
                                    <td>{{ $vm->labTemplate?->name ?? 'Lab Pribadi' }}</td>
                                    <td>{{ $vm->cpu_cores }} core</td>
                                    <td>{{ $vm->memory_mb }} MB</td>
                                    <td>{{ $vm->disk_gb }} GB</td>
                                    <td>
                                        <span class="badge {{ $statusClass($vm) }}">{{ $statusLabel($vm) }}</span>
                                        @if ($isSystemVm)
                                            <div><span class="badge badge-deleted">System VM</span></div>
                                        @elseif ($isCriticalVm)
                                            <div><span class="badge badge-deleted">Critical</span></div>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <form method="POST" action="{{ route('dashboard.simulate.vm.resources', $vm) }}">
                                                @csrf
                                                <button class="btn btn-soft" type="submit" @disabled($vm->trashed() || $isProtectedVm)>Edit resource VM</button>
                                            </form>
                                            <form method="POST" action="{{ route('dashboard.simulate.vm.delete', $vm) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-danger" type="submit" @disabled($vm->trashed() || $isProtectedVm)>Soft delete VM</button>
                                            </form>
                                            <form method="POST" action="{{ route('terminal-sessions.store', $vm) }}">
                                                @csrf
                                                <button class="btn btn-primary" type="submit" @disabled($terminalBlocked)>Access Terminal</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="muted">Belum ada VM lokal. Gunakan tombol Create Docker Lab untuk owner_id=3.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            @if (in_array($section, ['overview', 'audit-logs'], true))
                <section class="card section">
                    <div class="section-head">
                        <div>
                            <h2 class="section-title">Audit Log</h2>
                            <div class="section-note">Aktivitas penting dari API dan dashboard mock.</div>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>User</th>
                                <th>Action</th>
                                <th>Description</th>
                                <th>VM</th>
                                <th>Created At</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($auditLogs as $log)
                                <tr>
                                    <td>{{ $log->user?->name ?? '-' }}<div class="muted">{{ $log->user?->email ?? '' }}</div></td>
                                    <td><span class="badge badge-other">{{ $log->action }}</span></td>
                                    <td>{{ $log->description }}</td>
                                    <td>{{ $log->vm?->name ?? '-' }}</td>
                                    <td>{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="muted">Belum ada audit log.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        </div>
    </main>
</div>
</body>
</html>
