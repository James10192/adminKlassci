<?php

namespace App\Mail\Group;

use App\Models\Group;
use App\Models\GroupMember;

class CriticalAlertMail extends AbstractGroupAlertMail
{
    public function __construct(
        Group $group,
        GroupMember $member,
        public array $alert,
    ) {
        parent::__construct($group, $member);
    }

    public function build(): self
    {
        $tenant = $this->alert['tenant_name'] ?? $this->alert['tenant_code'] ?? $this->group->name;
        $type = $this->alert['type'] ?? 'alerte';

        return $this->subject("[KLASSCI] Alerte critique — {$tenant}")
            ->view('emails.group.critical-alert')
            ->with([
                'group' => $this->group,
                'member' => $this->member,
                'alert' => $this->alert,
                'unsubscribeUrl' => $this->buildUnsubscribeUrl($type),
            ]);
    }
}
