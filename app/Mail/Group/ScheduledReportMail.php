<?php

namespace App\Mail\Group;

use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Mail\Mailables\Attachment;

/**
 * Envoi programmé d'un état du portail, PDF en pièce jointe.
 *
 * Le PDF arrive en octets déjà rendus plutôt que sous forme de rapport à
 * produire : la consolidation traverse toutes les bases établissement, et un
 * envoi à cinq destinataires ne doit pas la refaire cinq fois.
 */
class ScheduledReportMail extends AbstractGroupAlertMail
{
    public function __construct(
        Group $group,
        GroupMember $member,
        public string $titreRapport,
        public string $nomFichier,
        public string $pdf,
        public string $periode,
        public string $cadence,
    ) {
        parent::__construct($group, $member);
    }

    public function build(): self
    {
        return $this->subject("[KLASSCI] {$this->titreRapport} — {$this->group->name}")
            ->view('emails.group.scheduled-report')
            ->with([
                'group' => $this->group,
                'member' => $this->member,
                'titreRapport' => $this->titreRapport,
                'periode' => $this->periode,
                'cadence' => $this->cadence,
                // Le lien de désabonnement du gabarit commun coupe tous les
                // e-mails du portail : un envoi programmé n'est pas un type
                // d'alerte, il n'a pas d'opt-out plus fin.
                'unsubscribeUrl' => $this->buildUnsubscribeUrl('rapports-programmes'),
            ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdf, $this->nomFichier)
                ->withMime('application/pdf'),
        ];
    }
}
