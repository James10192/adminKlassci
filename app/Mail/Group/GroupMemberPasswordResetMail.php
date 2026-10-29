<?php

namespace App\Mail\Group;

use App\Models\GroupMember;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Réinitialisation sans mot de passe transmis par e-mail.
 * Seul le détenteur du lien signé et du token peut choisir un nouveau secret.
 */
final class GroupMemberPasswordResetMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];

    public function __construct(
        public GroupMember $member,
        public string $resetUrl,
    ) {
    }

    public function build(): self
    {
        return $this->subject('[KLASSCI] Réinitialiser votre accès au portail Groupe')
            ->view('emails.group.member-password-reset')
            ->with([
                'member' => $this->member,
                'resetUrl' => $this->resetUrl,
                'ttlHours' => (int) config('group_portal.invitation_ttl_hours', 24),
            ]);
    }
}
