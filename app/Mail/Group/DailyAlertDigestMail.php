<?php

namespace App\Mail\Group;

use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class DailyAlertDigestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public Group $group,
        public GroupMember $member,
        public array $alerts,
    ) {
    }

    public function build(): self
    {
        $count = count($this->alerts);
        $unsubscribeUrl = URL::signedRoute('groupe.notifications.unsubscribe', [
            'member' => $this->member->id,
            'type' => 'digest',
        ]);

        return $this->subject("[KLASSCI] Récapitulatif quotidien — {$count} alerte" . ($count > 1 ? 's' : ''))
            ->view('emails.group.daily-digest')
            ->with([
                'group' => $this->group,
                'member' => $this->member,
                'alerts' => $this->alerts,
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);
    }
}
