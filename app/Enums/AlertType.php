<?php

namespace App\Enums;

enum AlertType: string
{
    case QuotaExceeded = 'quota_exceeded';
    case QuotaCritical = 'quota_critical';
    case SubscriptionExpired = 'subscription_expired';
    case SubscriptionExpiring = 'subscription_expiring';
    case HighAttrition = 'high_attrition';
    case ActiveReliquats = 'active_reliquats';
    case PlanMismatch = 'plan_mismatch';
    case StaleTenant = 'stale_tenant';
    case SslExpiring = 'ssl_expiring';
    case EnrollmentDecline = 'enrollment_decline';
    case UnpaidInvoices = 'unpaid_invoices';
    case TeacherOverload = 'teacher_overload';

    /**
     * Ce que lit le fondateur.
     *
     * Ces libellés vivaient dans une table `$typeLabels` en tête d'UNE vue,
     * `alerts-index`. La tuile d'alertes du tableau de bord, elle, affichait
     * `{{ $alert['type'] }}` brut — le CSS le passait en capitales et le
     * directeur général lisait « QUOTA_CRITICAL » et « SUBSCRIPTION_EXPIRING »
     * sur son propre portail. Un identifiant de base de données n'est pas une
     * phrase.
     *
     * Le libellé appartient au type, pas à la vue qui l'affiche : c'est la
     * seule façon qu'un troisième écran ne reparte pas de zéro.
     */
    public function libelle(): string
    {
        return match ($this) {
            self::QuotaExceeded => 'Quota dépassé',
            self::QuotaCritical => 'Quota critique',
            self::SubscriptionExpired => 'Abonnement expiré',
            self::SubscriptionExpiring => 'Abonnement expirant',
            self::HighAttrition => 'Attrition élevée',
            self::ActiveReliquats => 'Reliquats actifs',
            self::PlanMismatch => 'Plan dépassé',
            // « Tenant » est le mot du code, pas celui d'un directeur d'école.
            self::StaleTenant => 'Établissement sans activité',
            self::SslExpiring => 'Certificat expirant',
            self::EnrollmentDecline => 'Inscriptions en baisse',
            self::UnpaidInvoices => 'Factures impayées',
            self::TeacherOverload => 'Surcharge enseignante',
        };
    }

    /**
     * Le libellé d'un type reçu sous forme de chaîne, sans lever d'exception.
     *
     * Les alertes voyagent en tableau (cache, JSON, payload de mail) : une vue
     * reçoit `'quota_critical'`, pas un cas d'énumération. Un type inconnu —
     * alerte mise en cache avant un déploiement qui l'a retiré — se dégrade en
     * texte lisible plutôt qu'en 500.
     */
    public static function libelleDe(?string $valeur): string
    {
        if ($valeur === null || $valeur === '') {
            return 'Alerte';
        }

        return self::tryFrom($valeur)?->libelle()
            ?? ucfirst(str_replace('_', ' ', $valeur));
    }

    /** @return array<string,string> valeur => libellé, pour peupler un filtre */
    public static function libelles(): array
    {
        $libelles = [];
        foreach (self::cases() as $cas) {
            $libelles[$cas->value] = $cas->libelle();
        }

        return $libelles;
    }
}
