<?php

namespace App\Domain\Care\Tickets\Actions;

use App\Domain\Care\Tickets\DTO\ResultatCreation;
use App\Domain\Care\Tickets\DTO\SoumissionTicket;
use App\Domain\Care\Tickets\Enums\Canal;
use App\Domain\Care\Tickets\Enums\StatutTicket;
use App\Domain\Care\Tickets\Enums\TypeEvenement;
use App\Domain\Care\Tickets\Exceptions\CleIdempotenceReutilisee;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Domain\Care\Tickets\Services\Acteur;
use App\Domain\Care\Tickets\Services\Journal;
use App\Domain\Care\Tickets\Services\TicketStateMachine;
use App\Models\Tenant;
use App\Models\TenantDeployment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Cree une demande a partir de ce qu'une instance a transmis.
 *
 * Idempotente par (instance, cle) : un reseau mobile qui renvoie la meme
 * soumission retrouve la demande deja creee au lieu d'en creer une seconde.
 * Deux envois simultanes de la meme cle se departagent sur l'index unique.
 *
 * La version deployee est posee ICI, depuis ce que le Master a lui-meme
 * deploye. L'instance ne la connait pas ; et quand elle la connaitra, ce que
 * dit une instance de sa propre version restera une declaration, pas un fait.
 */
class CreerTicket
{
    public function __construct(
        private readonly Journal $journal,
        private readonly TicketStateMachine $etats,
    ) {
    }

    public function executer(Tenant $tenant, SoumissionTicket $soumission, string $cle): ResultatCreation
    {
        $empreinte = $soumission->empreinte();

        if ($existant = $this->retrouver($tenant, $cle, $empreinte)) {
            return new ResultatCreation($existant, true);
        }

        try {
            $ticket = DB::transaction(fn () => $this->creer($tenant, $soumission, $cle, $empreinte));
        } catch (UniqueConstraintViolationException) {
            // Un envoi concurrent de la meme cle a gagne la course.
            return new ResultatCreation($this->retrouver($tenant, $cle, $empreinte), true);
        }

        return new ResultatCreation($ticket, false);
    }

    private function retrouver(Tenant $tenant, string $cle, string $empreinte): ?SupportTicket
    {
        $ticket = SupportTicket::withTrashed()
            ->where('tenant_id', $tenant->id)
            ->where('idempotency_key', $cle)
            ->first();

        if ($ticket && ! hash_equals($ticket->request_hash, $empreinte)) {
            throw new CleIdempotenceReutilisee('Cette clé d\'idempotence a déjà servi pour une autre demande.');
        }

        return $ticket;
    }

    private function creer(Tenant $tenant, SoumissionTicket $s, string $cle, string $empreinte): SupportTicket
    {
        $ticket = new SupportTicket([
            'tenant_id' => $tenant->id,
            'idempotency_key' => $cle,
            'request_hash' => $empreinte,
            'reporter_external_id' => $s->rapporteurId,
            'reporter_name_snapshot' => $s->rapporteurNom,
            'reporter_email_snapshot' => $s->rapporteurEmail,
            'reporter_roles_snapshot' => $s->rolesRapporteur,
            'channel' => Canal::InApp,
            'customer_category' => $s->categorie,
            'title' => $s->titreEffectif(),
            'description' => $s->description,
        ]);
        $ticket->forceFill(['status' => StatutTicket::New])->save();

        // Lisible et unique sans compteur a verrouiller : l'identifiant suffit.
        $ticket->forceFill([
            'reference' => sprintf('KC-%s-%06d', $ticket->created_at->format('Y'), $ticket->id),
        ])->save();

        $acteur = Acteur::client($s->rapporteurId, $s->rapporteurNom);
        $this->journal->consigner($ticket, TypeEvenement::TicketCree, $acteur, vers: $s->categorie->value);

        $ticket->context()->create($this->contexte($tenant, $s->contexte));
        $this->journal->consigner($ticket, TypeEvenement::ContexteJoint, Acteur::systeme());

        $this->etats->franchir($ticket, StatutTicket::TriagePending, Acteur::systeme());

        return $ticket;
    }

    private function contexte(Tenant $tenant, array $c): array
    {
        $deploiement = TenantDeployment::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['success', 'completed'])
            ->latest('completed_at')
            ->first();

        return [
            'route_name' => $c['route_name'] ?? null,
            'url_path' => $c['url_path'] ?? null,
            'module' => $c['module'] ?? null,
            'page_title' => $c['page_title'] ?? null,
            'entity_type' => $c['entity']['type'] ?? null,
            'entity_id' => $c['entity']['id'] ?? null,
            'academic_year_id' => $c['academic_year_id'] ?? null,
            'class_id' => $c['class_id'] ?? null,
            'app_commit_sha' => $deploiement?->git_commit_hash ?? $tenant->git_commit_hash,
            'git_branch' => $deploiement?->git_branch ?? $tenant->git_branch,
            'deployment_id' => $deploiement?->id,
            'browser_family' => $c['browser']['family'] ?? null,
            'browser_version' => $c['browser']['version'] ?? null,
            'os_family' => $c['os'] ?? null,
            'device_type' => $c['device'] ?? null,
            'viewport' => $c['viewport'] ?? null,
            'locale' => $c['locale'] ?? null,
            'timezone' => $c['timezone'] ?? null,
            'request_ids' => $c['request_ids'] ?? null,
            'extras' => $c['extras'] ?? null,
            'captured_at' => now(),
        ];
    }
}
