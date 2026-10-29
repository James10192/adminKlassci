<?php

namespace App\Domain\Care\Tickets\Actions;

use App\Domain\Care\Tickets\Models\SupportTicketIntervention;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Annule uniquement une intervention encore en attente de routage dans le registre Master.
 * Aucun message, livraison ou accusé côté école n'est prétendu ici.
 */
final class AnnulerIntervention
{
    public function executer(int $interventionId, User $operateur, string $motif): SupportTicketIntervention
    {
        abort_unless($operateur->is_active && Gate::forUser($operateur)->allows('support.tickets.manage'), 403);
        $motif = trim($motif);

        if (mb_strlen($motif) < 10 || mb_strlen($motif) > 1000) {
            throw ValidationException::withMessages(['motif' => 'Un motif de 10 à 1000 caractères est requis.']);
        }

        return DB::transaction(function () use ($interventionId, $operateur, $motif) {
            $intervention = SupportTicketIntervention::query()
                ->lockForUpdate()
                ->findOrFail($interventionId);

            $ticket = $intervention->ticket;
            // Ne jamais retourner un objet d'un dossier devenu sensible.
            abort_if($ticket === null || $ticket->tenant_id !== $intervention->tenant_id
                || $ticket->is_security_restricted, 404);

            // Rejeu sans deuxième événement : le CANCELLED est terminal.
            if ($intervention->status === SupportTicketIntervention::CANCELLED) {
                return $intervention;
            }

            // Les interventions exécutées ne sont pas annulables : passer par
            // la vérification support / réouverture du ticket.
            abort_unless(in_array($intervention->status, [
                SupportTicketIntervention::PENDING_ROUTING,
             ], true), 409);

            $precedent = $intervention->status;
            $intervention->forceFill(['status' => SupportTicketIntervention::CANCELLED])->save();

            DB::table('support_ticket_intervention_events')->insert([
                'intervention_id' => $intervention->getKey(),
                'event' => 'CANCELLED',
                'actor_type' => 'SUPPORT',
                'actor_ref' => (string) $operateur->getKey(),
                'details' => json_encode([
                    'from' => $precedent,
                    'reason' => $motif,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return $intervention;
        }, 3);
    }
}
