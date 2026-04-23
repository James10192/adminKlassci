<?php

namespace App\Console\Commands;

use App\Models\GroupMember;
use App\Services\Group\TemporaryPasswordGenerator;
use Illuminate\Console\Command;

/**
 * Fallback for members without email — the Filament password-reset link
 * goes to an inbox they don't have. Admins run this on the server, read
 * the temp password from stdout, and pass it to the member out-of-band.
 *
 * On success the member's `password_changed_at` is flipped to null so the
 * EnsurePasswordChanged middleware forces a rotation on next login.
 */
class ResetGroupMemberPassword extends Command
{
    protected $signature = 'group-portal:reset-password
                            {identifier : Member id, email, or username}';

    protected $description = 'Generate + print a temporary password for a group member. Forces them to rotate on next login. Use when a username-only member needs password recovery.';

    public function handle(TemporaryPasswordGenerator $generator): int
    {
        $identifier = $this->argument('identifier');

        $member = $this->resolveMember($identifier);

        if (! $member) {
            $this->error("No GroupMember matches {$identifier} (checked id, email, username).");

            return self::FAILURE;
        }

        $tempPassword = $generator->generate();
        $member->resetToTemporaryPassword($tempPassword);

        $this->newLine();
        $this->info("Password reset for {$member->name} ({$member->email} / @{$member->username}).");
        $this->newLine();
        $this->line('  Mot de passe temporaire :');
        $this->newLine();
        $this->line("    <fg=yellow;options=bold>{$tempPassword}</>");
        $this->newLine();
        $this->warn('  Transmettez ce mot de passe au membre par un canal sécurisé.');
        $this->warn('  Il sera invité à le changer dès la première connexion.');

        return self::SUCCESS;
    }

    private function resolveMember(string $identifier): ?GroupMember
    {
        if (is_numeric($identifier)) {
            return GroupMember::find((int) $identifier);
        }

        return GroupMember::where('email', $identifier)
            ->orWhere('username', $identifier)
            ->first();
    }
}
