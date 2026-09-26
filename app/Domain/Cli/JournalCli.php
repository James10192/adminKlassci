<?php

namespace App\Domain\Cli;

use App\Models\Tenant;
use App\Models\TenantActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Seul point d'écriture des traces du CLI admin.
 *
 * Une action sur une école va dans son journal d'activité, visible dans le
 * panneau. Une action sans école (jeton émis, requête SQL, scan des
 * dossiers) va dans le journal applicatif : le journal d'activité exige un
 * établissement.
 */
final class JournalCli
{
    public static function consigner(
        string $action,
        string $description,
        array $details = [],
        ?Tenant $ecole = null,
        ?User $membre = null,
        ?Request $request = null,
    ): void {
        $membre ??= $request?->user();

        if ($ecole === null) {
            Log::info("CLI : {$description}", ['action' => $action, 'membre' => $membre?->email] + $details);

            return;
        }

        TenantActivityLog::create([
            'tenant_id' => $ecole->id,
            'action' => $action,
            'description' => $description,
            'ip_address' => $request?->ip(),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 255),
            'performed_by_user_id' => $membre?->id,
            'metadata' => $details + ['source' => 'cli'],
            'performed_at' => now(),
        ]);
    }
}
