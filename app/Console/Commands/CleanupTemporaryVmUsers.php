<?php

namespace App\Console\Commands;

use App\Enums\TerminalSessionStatus;
use App\Models\TerminalSession;
use App\Services\TemporaryVmCredentialService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleanupTemporaryVmUsers extends Command
{
    protected $signature = 'terminal:sessions:cleanup-temporary-users {--limit=50}';

    protected $description = 'Expire old terminal sessions and clean up Shared Practical VM temporary Linux users.';

    public function handle(TemporaryVmCredentialService $temporaryVmCredentialService): int
    {
        $expired = $this->expirePastDueSessions();
        $cleaned = 0;
        $failed = 0;
        $limit = max(1, (int) $this->option('limit'));

        $sessions = TerminalSession::with('vm')
            ->whereNotNull('metadata')
            ->get()
            ->filter(fn (TerminalSession $session): bool => in_array($session->temporaryCredentialStatus(), ['cleanup_pending', 'cleanup_failed'], true))
            ->take($limit);

        foreach ($sessions as $session) {
            try {
                $temporaryVmCredentialService->disableTemporaryUser($session->vm, $session);
                $session->refresh();

                if ($session->temporaryCredentialStatus() === 'cleanup_failed') {
                    $failed++;
                } else {
                    $cleaned++;
                }
            } catch (Throwable $exception) {
                $failed++;
                $session->refresh();
                $session->mergeTemporaryCredentialMetadata([
                    'status' => 'cleanup_failed',
                    'last_error' => str($exception->getMessage())->limit(1000)->toString(),
                    'cleanup_failed_at' => now()->toISOString(),
                ]);

                Log::warning('Temporary VM credential cleanup command failed for session.', [
                    'terminal_session_id' => $session->id,
                    'vm_id' => $session->vm_id,
                    'temporary_username' => $session->temporaryUsername(),
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                ]);
            }
        }

        $this->info("Expired sessions: {$expired}");
        $this->info("Cleanup attempted: ".$sessions->count());
        $this->info("Cleanup completed: {$cleaned}");
        $this->info("Cleanup failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function expirePastDueSessions(): int
    {
        $count = 0;

        TerminalSession::query()
            ->whereIn('status', [
                TerminalSessionStatus::Pending->value,
                TerminalSessionStatus::Active->value,
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get()
            ->each(function (TerminalSession $session) use (&$count): void {
                if ($session->expire(now())) {
                    $count++;
                }
            });

        return $count;
    }
}
