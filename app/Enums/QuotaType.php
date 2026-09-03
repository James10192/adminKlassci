<?php

namespace App\Enums;

/**
 * Les quotas d'un abonnement — et leur nom en français.
 *
 * Ces cinq clés sont les racines des colonnes `max_*` / `current_*` de la table
 * `tenants`. Elles servaient telles quelles dans le texte des alertes : le
 * directeur d'un groupe ivoirien lisait « Quota users à 93,3 % » et
 * « Quota students dépassé » sur un portail par ailleurs entièrement français.
 *
 * Un nom de colonne n'est pas un mot destiné à être lu. Il vit ici, avec sa
 * traduction, plutôt que recopié dans chaque message.
 */
enum QuotaType: string
{
    case Users = 'users';
    case Staff = 'staff';
    case Students = 'students';
    case Inscriptions = 'inscriptions';
    case Storage = 'storage';

    public function libelle(): string
    {
        return match ($this) {
            self::Users => 'comptes',
            self::Staff => 'personnel',
            self::Students => 'étudiants',
            self::Inscriptions => 'inscriptions',
            self::Storage => 'stockage',
        };
    }

    /**
     * Le libellé d'une clé reçue en chaîne, sans lever.
     *
     * Une clé inconnue se dégrade en elle-même plutôt qu'en 500 : mieux vaut un
     * mot anglais dans une alerte qu'une alerte qui ne s'affiche pas.
     */
    public static function libelleDe(?string $valeur): string
    {
        if ($valeur === null || $valeur === '') {
            return 'inconnu';
        }

        return self::tryFrom($valeur)?->libelle() ?? $valeur;
    }
}
