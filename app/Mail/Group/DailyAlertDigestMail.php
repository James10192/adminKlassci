<?php

namespace App\Mail\Group;

use App\Models\Group;
use App\Models\GroupMember;
use App\Support\Alerts\AlertPayload;

class DailyAlertDigestMail extends AbstractGroupAlertMail
{
    /**
     * @param  array<int, AlertPayload>  $alerts
     */
    public function __construct(
        Group $group,
        GroupMember $member,
        public array $alerts,
    ) {
        parent::__construct($group, $member);
    }

    public function build(): self
    {
        $count = count($this->alerts);

        return $this->subject("[KLASSCI] Récapitulatif quotidien — {$count} alerte" . ($count > 1 ? 's' : ''))
            ->view('emails.group.daily-digest')
            ->with([
                'group' => $this->group,
                'member' => $this->member,
                'alerts' => $this->alerts,
                'unsubscribeUrl' => $this->buildUnsubscribeUrl('digest'),
            ]);
    }
}
