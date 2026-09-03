<?php

namespace App\Enums;

/**
 * L'état d'exploitation d'un établissement — et ce qu'on en déduit.
 *
 * Ces quatre valeurs sont celles de la colonne `tenants.status`
 * (`enum('active','suspended','maintenance','cancelled')`). Elles vivaient
 * jusqu'ici recopiées dans huit `match` répartis dans autant de fichiers, et
 * les copies avaient déjà divergé de la source :
 *
 *   — le formulaire d'administration proposait `inactive`, qui n'existe dans
 *     aucune énumération : l'enregistrer était refusé par MySQL en mode strict
 *     et silencieusement accepté ailleurs ;
 *   — la fiche du portail groupe nommait `archived`, qui n'existe pas non plus,
 *     et n'avait donc jamais pu s'afficher ;
 *   — `cancelled`, lui, existe bel et bien mais n'était nommé nulle part : le
 *     hero d'une interface entièrement française affichait « Cancelled » sur
 *     une pastille ROUGE, alors qu'une résiliation est une décision du groupe,
 *     pas une alarme.
 *
 * La distinction qui compte le plus est `mesurable()`. Le portail interroge la
 * base de chaque école ; il ne doit pas le faire pour une école suspendue ou
 * résiliée — dont la base ne répond souvent plus, ce qui ferait accuser une
 * panne technique là où il y a une décision administrative. Mais il DOIT le
 * faire pour une école en maintenance : la maintenance est un état transitoire
 * d'exploitation (le temps d'un déploiement, deux à cinq minutes), la base
 * répond parfaitement, et couper la mesure ferait disparaître deux mille
 * étudiants de l'écran de leur directeur.
 */
enum TenantStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Maintenance = 'maintenance';
    case Cancelled = 'cancelled';

    /** Ce que lit le directeur. */
    public function libelle(): string
    {
        return match ($this) {
            self::Active => 'Actif',
            self::Suspended => 'Suspendu',
            self::Maintenance => 'Maintenance',
            self::Cancelled => 'Résilié',
        };
    }

    /**
     * La couleur.
     *
     * Une résiliation est une décision, pas un incident : elle est grise. Le
     * rouge reste à ce qui appelle une action, et il n'y a rien à faire d'une
     * école qu'on a soi-même résiliée.
     */
    public function ton(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Suspended => 'warning',
            self::Maintenance => 'info',
            self::Cancelled => 'gray',
        };
    }

    /**
     * Faut-il interroger la base de cette école ?
     *
     * Voir le docblock de classe : la maintenance est mesurable, la suspension
     * et la résiliation ne le sont pas.
     */
    public function mesurable(): bool
    {
        return match ($this) {
            self::Active, self::Maintenance => true,
            self::Suspended, self::Cancelled => false,
        };
    }

    /**
     * Le libellé d'un statut reçu en chaîne, sans lever.
     *
     * Les statuts voyagent en tableau (KPI mis en cache, payload JSON) et une
     * base peut porter une valeur qu'aucune version du code ne connaît. Un
     * statut inconnu se dégrade en texte lisible plutôt qu'en 500.
     */
    public static function libelleDe(?string $valeur): string
    {
        if ($valeur === null || $valeur === '') {
            return 'Inconnu';
        }

        return self::tryFrom($valeur)?->libelle() ?? ucfirst(str_replace('_', ' ', $valeur));
    }

    /**
     * Le ton d'un statut reçu en chaîne.
     *
     * Un statut qu'on ne sait pas nommer n'est pas une alarme : il est gris.
     * Le défaut était `danger`, ce qui peignait en rouge tout mot que le
     * `match` avait oublié — y compris `cancelled`, pourtant légal.
     */
    public static function tonDe(?string $valeur): string
    {
        return self::tryFrom((string) $valeur)?->ton() ?? 'gray';
    }

    /**
     * Un statut inconnu est traité comme NON mesurable.
     *
     * C'est le sens prudent : on ne va pas ouvrir une connexion vers une base
     * dont on ne sait pas dans quel état est l'établissement.
     */
    public static function mesurableDe(?string $valeur): bool
    {
        return self::tryFrom((string) $valeur)?->mesurable() ?? false;
    }

    /** @return array<string,string> valeur => libellé, pour peupler un select */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $cas) {
            $options[$cas->value] = $cas->libelle();
        }

        return $options;
    }
}
