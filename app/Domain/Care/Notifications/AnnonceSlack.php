<?php

namespace App\Domain\Care\Notifications;

use App\Domain\Care\Tickets\Enums\Severite;
use App\Domain\Care\Tickets\Enums\StatutTicket;
use App\Domain\Care\Tickets\Enums\TypeActeur;
use App\Domain\Care\Tickets\Enums\TypeEvenement;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Domain\Care\Tickets\Models\SupportTicketEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Annonce dans un canal Slack ce qui arrive aux demandes des écoles.
 *
 * Le service technique y suit sa file, et la direction voit la progression
 * sans ouvrir le panneau. Trois règles :
 *
 * - jamais bloquant : l'envoi part après la validation de la transaction et
 *   après la réponse à l'école ; un Slack lent ou en panne ne retarde ni ne
 *   fait échouer une demande, il laisse une ligne au journal ;
 * - rien de sensible ne sort : ni titre, ni description, ni message, ni pièce jointe
 *   (ils peuvent nommer un élève), et aucune trace d'une demande restreinte
 *   pour raison de sécurité ;
 * - rien ne part tant que CARE_SLACK_WEBHOOK n'est pas posé.
 */
class AnnonceSlack
{
    public function programmer(SupportTicketEvent $evenement): void
    {
        if (! $this->estActive() || ! in_array($evenement->type, $this->evenementsAnnonces(), true)) {
            return;
        }

        // Le passage automatique « Nouvelle → À trier » suit chaque création :
        // l'annoncer doublerait chaque nouvelle demande dans le canal.
        // Filtré sur l'acteur système : une sortie manuelle de « Nouvelle »,
        // si elle apparaît un jour, reste annoncée.
        if ($evenement->type === TypeEvenement::StatutChange
            && $evenement->from_value === StatutTicket::New->value
            && $evenement->actor_type === TypeActeur::Systeme) {
            return;
        }

        // Après commit : une transaction annulée n'annonce rien. Après la
        // réponse : l'école n'attend pas Slack.
        DB::afterCommit(fn () => app()->terminating(fn () => $this->envoyer($evenement)));
    }

    public function envoyer(SupportTicketEvent $evenement): void
    {
        $ticket = SupportTicket::with(['tenant:id,code,name'])->find($evenement->ticket_id);

        if ($ticket === null || $ticket->is_security_restricted) {
            return;
        }

        try {
            $reponse = Http::timeout((int) config('care.slack.delai_secondes', 3))
                ->post((string) config('care.slack.webhook'), $this->message($ticket, $evenement));

            if (! $reponse->successful()) {
                Log::warning('Care : Slack a refusé une annonce', [
                    'ticket' => $ticket->reference,
                    'statut_http' => $reponse->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Care : annonce Slack impossible', [
                'ticket' => $ticket->reference,
                'erreur' => $e->getMessage(),
            ]);
        }
    }

    /** @return array{text: string, blocks: array<int, array<string, mixed>>} */
    public function message(SupportTicket $ticket, SupportTicketEvent $evenement): array
    {
        $ecole = $ticket->tenant?->name ?? 'École inconnue';
        $quoi = $this->phrase($evenement);
        $lien = route('filament.admin.resources.support-tickets.view', $ticket);
        // Pas de titre : sans titre fourni par l'école, il est tiré de la
        // description, et ferait sortir ce qu'on refuse d'envoyer.

        $contexte = array_filter([
            $ticket->customer_category?->libelle(),
            $ticket->severity instanceof Severite ? $ticket->severity->libelle() : null,
            $ticket->status instanceof StatutTicket ? 'Statut : ' . $ticket->status->libelle() : null,
        ]);

        return [
            // Repli des notifications mobiles et des clients sans blocs.
            'text' => $this->echapper("{$ticket->reference} · {$ecole} · {$quoi}"),
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*<{$lien}|{$ticket->reference}>* · {$this->echapper($ecole)}\n{$this->echapper($quoi)}",
                    ],
                ],
                [
                    'type' => 'context',
                    'elements' => [['type' => 'mrkdwn', 'text' => $this->echapper(implode(' · ', $contexte) ?: '—')]],
                ],
            ],
        ];
    }

    private function phrase(SupportTicketEvent $evenement): string
    {
        $par = $evenement->actor_type === TypeActeur::Personnel && $evenement->actor_ref
            ? User::find((int) $evenement->actor_ref)?->name
            : null;
        $suffixe = $par ? " (par {$par})" : '';

        return match ($evenement->type) {
            TypeEvenement::TicketCree => 'Nouvelle demande',
            TypeEvenement::StatutChange => 'Statut : ' . $this->statut($evenement->from_value) . ' → ' . $this->statut($evenement->to_value) . $suffixe,
            TypeEvenement::SeveriteChangee => 'Sévérité : ' . $this->severite($evenement->from_value) . ' → ' . $this->severite($evenement->to_value) . $suffixe,
            TypeEvenement::Assigne => $evenement->to_value
                ? 'Assignée à ' . (User::find((int) $evenement->to_value)?->name ?? 'un membre de l\'équipe') . $suffixe
                : 'Plus assignée' . $suffixe,
            TypeEvenement::ReponseSupport => 'Le support a répondu à l\'école' . $suffixe,
            TypeEvenement::ReponseClient => 'L\'école a répondu',
            TypeEvenement::PieceJointeClient => 'L\'école a joint un fichier',
            // Pas de « default » : un type ajouté à la config sans phrase ici
            // doit échouer, pas envoyer son code brut dans le canal.
        };
    }

    private function statut(?string $valeur): string
    {
        return $valeur ? (StatutTicket::tryFrom($valeur)?->libelle() ?? $valeur) : '—';
    }

    private function severite(?string $valeur): string
    {
        return $valeur ? (Severite::tryFrom($valeur)?->libelle() ?? $valeur) : 'non posée';
    }

    /** Slack lit &, < et > comme de la mise en forme : un nom d'école ne doit pas fabriquer de lien. */
    private function echapper(string $texte): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $texte);
    }

    private function estActive(): bool
    {
        return filled(config('care.slack.webhook'));
    }

    /** @return array<int, TypeEvenement> */
    private function evenementsAnnonces(): array
    {
        return (array) config('care.slack.evenements', []);
    }
}
