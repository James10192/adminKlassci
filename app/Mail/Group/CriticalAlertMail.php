<?php

namespace App\Mail\Group;

use App\Models\Group;
use App\Models\GroupMember;
use App\Support\Alerts\AlertPayload;

class CriticalAlertMail extends AbstractGroupAlertMail
{
    public function __construct(
        Group $group,
        GroupMember $member,
        public AlertPayload $alert,
    ) {
        parent::__construct($group, $member);
    }

    public function build(): self
    {
        $tenant = $this->alert->tenantName !== ''
            ? $this->alert->tenantName
            : ($this->alert->tenantCode ?? $this->group->name);

        return $this->subject("[KLASSCI] Alerte critique — {$tenant}")
            ->view('emails.group.critical-alert')
            ->with([
                'group' => $this->group,
                'member' => $this->member,
                'alert' => $this->alert,
                'unsubscribeUrl' => $this->buildUnsubscribeUrl($this->alert->type->value),
            ]);
    }
}
