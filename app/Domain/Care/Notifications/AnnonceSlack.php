<?php

namespace App\Domain\Care\Notifications;

use App\Domain\Care\Retours\Enums\AvisAssistant;
use App\Domain\Care\Retours\Models\RetourAssistant;
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
 *
 * Les retours 👍 / 👎 sur Nanan suivent les mêmes règles : ni la question, ni
 * la réponse, ni le commentaire ne sortent. L'annonce dit qu'il y a quelque
 * chose à lire, et le bouton mène au panneau où on le lit.
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

        $this->poster($this->message($ticket, $evenement), ['ticket' => $ticket->reference]);
    }

    /**
     * Un 👎 (ou un 👍 accompagné d'un commentaire) vient d'arriver d'une école.
     * Même discipline que les demandes : après commit, après la réponse.
     */
    public function programmerRetour(RetourAssistant $retour): void
    {
        if (! $this->estActive() || ! $this->retourAnnonce($retour)) {
            return;
        }

        DB::afterCommit(fn () => app()->terminating(fn () => $this->envoyerRetour($retour->getKey())));
    }

    public function envoyerRetour(int $id): void
    {
        $retour = RetourAssistant::with('tenant:id,code,name')->find($id);
        if ($retour === null || ! $this->retourAnnonce($retour)) {
            return;
        }

        $this->poster($this->messageRetour($retour), ['retour_assistant' => $retour->id]);
    }

    /** @return array{text: string, blocks: array<int, array<string, mixed>>} */
    public function messageRetour(RetourAssistant $retour): array
    {
        $ecole = $retour->tenant?->name ?? 'École inconnue';
        $quoi = $retour->avis === AvisAssistant::PasUtile
            ? 'Réponse de Nanan jugée pas utile'
            : 'Réponse de Nanan jugée utile, avec un commentaire';
        $lien = route('filament.admin.resources.retours-assistant.view', $retour);

        $contexte = array_filter([
            $retour->raisonLibelle(),
            $retour->utilisateur_role ? 'Rôle : '.$retour->utilisateur_role : null,
            $retour->commentaire ? 'Commentaire à lire' : null,
            $retour->modele ? 'Modèle : '.$retour->modele : null,
        ]);

        return [
            'text' => $this->echapper("{$retour->avis->pictogramme()} {$ecole} · {$quoi}"),
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "{$retour->avis->pictogramme()} *{$this->echapper($ecole)}*\n{$this->echapper($quoi)}",
                    ],
                ],
                [
                    'type' => 'context',
                    'elements' => [['type' => 'mrkdwn', 'text' => $this->echapper(implode(' · ', $contexte) ?: '—')]],
                ],
                $this->boutons($lien, 'Lire dans adminKlassci'),
            ],
        ];
    }

    private function retourAnnonce(RetourAssistant $retour): bool
    {
        return $retour->avis === AvisAssistant::PasUtile
            ? (bool) config('care.slack.retours_assistant.pas_utile', true)
            : filled($retour->commentaire) && (bool) config('care.slack.retours_assistant.utile_avec_commentaire', true);
    }

    /** @param array<string, mixed> $repere ce qui permet de retrouver l'objet dans le journal */
    private function poster(array $message, array $repere): void
    {
        try {
            $reponse = Http::timeout((int) config('care.slack.delai_secondes', 3))
                ->post((string) config('care.slack.webhook'), $message);

            if (! $reponse->successful()) {
                Log::warning('Care : Slack a refusé une annonce', $repere + ['statut_http' => $reponse->status()]);
            }
        } catch (\Throwable $e) {
            Log::warning('Care : annonce Slack impossible', $repere + ['erreur' => $e->getMessage()]);
        }
    }

    /** @return array<string, mixed> */
    private function boutons(string $lien, string $libelle): array
    {
        return [
            'type' => 'actions',
            'elements' => [[
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => $libelle],
                'url' => $lien,
                'style' => 'primary',
            ]],
        ];
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
                $this->boutons($lien, 'Ouvrir dans adminKlassci'),
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
