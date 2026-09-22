<?php

namespace App\Domain\Care\Tickets\Enums;

/**
 * Le cycle de vie interne d'une demande.
 *
 * Les transitions permises ne vivent pas ici mais dans
 * App\Domain\Care\Tickets\Services\TicketStateMachine : un statut ne se pose
 * jamais directement, il se franchit, et chaque franchissement laisse une
 * trace dans le journal.
 */
enum StatutTicket: string
{
    case New = 'NEW';
    case TriagePending = 'TRIAGE_PENDING';
    case Triaged = 'TRIAGED';
    case WaitingSupport = 'WAITING_SUPPORT';
    case WaitingCustomer = 'WAITING_CUSTOMER';
    case Confirmed = 'CONFIRMED';
    case LinkedToKnownIssue = 'LINKED_TO_KNOWN_ISSUE';
    case EscalatedProduct = 'ESCALATED_PRODUCT';
    case EscalatedEngineering = 'ESCALATED_ENGINEERING';
    case InProgress = 'IN_PROGRESS';
    case InReview = 'IN_REVIEW';
    case FixReady = 'FIX_READY';
    case Deployed = 'DEPLOYED';
    case Verifying = 'VERIFYING';
    case Resolved = 'RESOLVED';
    case Closed = 'CLOSED';
    case Rejected = 'REJECTED';
    case Duplicate = 'DUPLICATE';

    public function libelle(): string
    {
        return match ($this) {
            self::New => 'Nouvelle',
            self::TriagePending => 'À trier',
            self::Triaged => 'Triée',
            self::WaitingSupport => 'Attente support',
            self::WaitingCustomer => 'Attente client',
            self::Confirmed => 'Confirmée',
            self::LinkedToKnownIssue => 'Problème connu',
            self::EscalatedProduct => 'Escaladée produit',
            self::EscalatedEngineering => 'Escaladée technique',
            self::InProgress => 'En cours',
            self::InReview => 'En revue',
            self::FixReady => 'Correctif prêt',
            self::Deployed => 'Déployée',
            self::Verifying => 'En vérification',
            self::Resolved => 'Résolue',
            self::Closed => 'Fermée',
            self::Rejected => 'Rejetée',
            self::Duplicate => 'Doublon',
        };
    }

    /** Couleur Filament. Le rouge est reserve a ce qui attend une action. */
    public function ton(): string
    {
        return match ($this) {
            self::New, self::TriagePending, self::WaitingSupport => 'warning',
            self::WaitingCustomer => 'info',
            self::Resolved, self::Closed => 'success',
            self::Rejected, self::Duplicate => 'gray',
            default => 'primary',
        };
    }

    /**
     * La projection vers l'ecole. Exhaustive par construction : un statut
     * ajoute sans y etre range fait echouer le `match`, pas l'affichage.
     */
    public function statutClient(): StatutClient
    {
        return match ($this) {
            self::New, self::TriagePending => StatutClient::Recu,
            self::Triaged, self::WaitingSupport, self::Confirmed, self::LinkedToKnownIssue,
            self::EscalatedProduct, self::EscalatedEngineering => StatutClient::EnAnalyse,
            self::WaitingCustomer => StatutClient::ActionRequise,
            self::InProgress, self::InReview, self::FixReady => StatutClient::EnResolution,
            self::Deployed, self::Verifying => StatutClient::CorrectionDeployee,
            self::Resolved => StatutClient::Resolu,
            self::Closed, self::Rejected, self::Duplicate => StatutClient::Ferme,
        };
    }

    /** Une demande « ouverte » est une demande dont quelqu'un doit encore s'occuper. */
    public function estOuvert(): bool
    {
        return ! in_array($this, [self::Resolved, self::Closed, self::Rejected, self::Duplicate], true);
    }

    /** @return list<string> */
    public static function valeursOuvertes(): array
    {
        return array_values(array_map(
            fn (self $s) => $s->value,
            array_filter(self::cases(), fn (self $s) => $s->estOuvert()),
        ));
    }
}
