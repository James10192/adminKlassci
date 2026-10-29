<?php

namespace App\Domain\Care\Tickets\Actions;

use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Domain\Care\Tickets\Models\SupportTicketIntervention;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use App\Models\User;

/**
 * Stage an actionable request without pretending it was delivered.
 * Routing, recipient authorization and completion require the school-side
 * protocol. Until implemented and acknowledged this stays PENDING_ROUTING.
 */
final class PreparerIntervention
{
    public function executer(
        SupportTicket $ticket,
        User $operateur,
        string $cle,
        string $titre,
        string $instructions,
        string $permission,
        ?int $destinataire = null,
    ): SupportTicketIntervention {
        abort_unless($operateur->is_active && Gate::forUser($operateur)->allows('support.tickets.manage'), 403);
        $cle = trim($cle);
        $titre = trim($titre);
        $instructions = trim($instructions);
        $permission = trim($permission);

        if (! preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $cle)
            || mb_strlen($titre) < 3 || mb_strlen($titre) > 160
            || mb_strlen($instructions) < 10 || mb_strlen($instructions) > 5000
            || ! preg_match('/^[a-zA-Z][a-zA-Z0-9._:-]{2,159}$/D', $permission)
            || ($destinataire !== null && $destinataire < 1)) {
            throw ValidationException::withMessages(['intervention' => 'Demande d’intervention invalide : vérifiez les champs et la permission requise.']);
        }

        $empreinte = hash('sha256', json_encode([
            $titre, $instructions, $permission, $destinataire,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $ticket, $operateur, $cle, $titre, $instructions, $permission,
            $destinataire, $empreinte
        ) {
            $courant = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->getKey());

            // A privileged internal ticket must not get a task whose details
            // might later be routed to ordinary school accounts.
            abort_if($courant->is_security_restricted, 409, 'Intervention impossible sur un dossier restreint.');

            // Une tentative déjà validée reste idempotente même si le ticket
            // a depuis été résolu : l'appelant récupère le même résultat.
            // La restriction de sécurité reste prioritaire et interdit toute
            // lecture de la tâche lorsqu'elle a été activée entre-temps.
            $existante = SupportTicketIntervention::query()
                ->where('ticket_id', $courant->getKey())
                ->where('idempotency_key', $cle)
                ->first();
            if ($existante !== null) {
                if (! hash_equals($existante->request_hash, $empreinte)) {
                    throw ValidationException::withMessages(['intervention' => 'Cette clé a déjà été utilisée pour une demande différente.']);
                }

                return $existante;
            }

            abort_unless($courant->status->estOuvert(), 409, 'Intervention impossible sur un dossier résolu ou fermé.');

            $intervention = SupportTicketIntervention::create([
                'tenant_id' => $courant->tenant_id,
                'ticket_id' => $courant->getKey(),
                'idempotency_key' => $cle,
                'request_hash' => $empreinte,
                'title' => $titre,
                'instructions' => $instructions,
                'required_permission' => $permission,
                'target_external_user_id' => $destinataire,
                'status' => SupportTicketIntervention::PENDING_ROUTING,
                'created_by_admin_id' => $operateur->getKey(),
            ]);

            DB::table('support_ticket_intervention_events')->insert([
                'intervention_id' => $intervention->getKey(),
                'event' => 'CREATED_PENDING_ROUTING',
                'actor_type' => 'SUPPORT',
                'actor_ref' => (string) $operateur->getKey(),
                'details' => json_encode(['required_permission' => $permission, 'targeted' => $destinataire !== null], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return $intervention;
        }, 3);
    }
}
