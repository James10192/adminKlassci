<?php

namespace App\Domain\Care\Tickets\Enums;

enum CategorieInterne: string
{
    case Bug = 'BUG';
    case Incident = 'INCIDENT';
    case Configuration = 'CONFIGURATION';
    case Permission = 'PERMISSION';
    case Data = 'DATA';
    case HowTo = 'HOW_TO';
    case Training = 'TRAINING';
    case ProductRequest = 'PRODUCT_REQUEST';
    case Billing = 'BILLING';
    case Security = 'SECURITY';
    case Performance = 'PERFORMANCE';
    case Integration = 'INTEGRATION';
    case Unknown = 'UNKNOWN';

    public function libelle(): string
    {
        return match ($this) {
            self::Bug => 'Défaut logiciel',
            self::Incident => 'Incident',
            self::Configuration => 'Configuration',
            self::Permission => 'Permission',
            self::Data => 'Données',
            self::HowTo => "Question d'usage",
            self::Training => 'Formation',
            self::ProductRequest => 'Demande produit',
            self::Billing => 'Facturation',
            self::Security => 'Sécurité',
            self::Performance => 'Performance',
            self::Integration => 'Intégration',
            self::Unknown => 'Indéterminé',
        };
    }
}
