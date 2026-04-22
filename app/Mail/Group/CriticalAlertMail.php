<?php

namespace App\Mail\Group;

use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class CriticalAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public Group $group,
        public GroupMember $member,
        public array $alert,
    ) {
    }

    public function build(): self
    {
        $typeLabel = $this->alert['type'] ?? 'alerte';
        $tenant = $this->alert['tenant_name'] ?? $this->alert['tenant_code'] ?? $this->group->name;

        $unsubscribeUrl = URL::signedRoute('groupe.notifications.unsubscribe', [
            'member' => $this->member->id,
            'type' => $typeLabel,
        ]);

        return $this->subject("[KLASSCI] Alerte critique — {$tenant}")
            ->view('emails.group.critical-alert')
            ->with([
                'group' => $this->group,
                'member' => $this->member,
                'alert' => $this->alert,
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);
    }
}
