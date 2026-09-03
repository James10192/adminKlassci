<?php

namespace App\Enums;

/**
 * Les offres commerciales KLASSCI — et comment on les nomme et les colore.
 *
 * Ces quatre valeurs sont celles de la colonne `tenants.plan`
 * (`enum('free','essentiel','professional','elite')`). Leur libellé et leur
 * couleur vivaient recopiés dans cinq fichiers, et les copies avaient diverge :
 *
 *   — le tableau du portail groupe n'en donnait AUCUN libellé : il affichait la
 *     valeur brute, « elite », « free », en minuscules, juste à côté d'une
 *     colonne « Statut » correctement traduite en « Actif » ;
 *   — la fiche établissement passait par `ucfirst()`, qui rend « Elite » sans
 *     accent alors que l'offre s'écrit « Élite » ;
 *   — la couleur se contredisait d'un écran à l'autre : « essentiel » était
 *     `warning` sur le portail et `primary` dans le panneau d'administration,
 *     « elite » `success` ici et `warning` là.
 *
 * La couleur suit l'échelle des offres (gris → info → bleu), elle ne juge pas.
 * Un `warning` sur « Élite » dirait « attention » à propos du client qui paie
 * le plus ; un `success` sur une offre dirait qu'une autre est un échec.
 */
enum TenantPlan: string
{
    case Free = 'free';
    case Essentiel = 'essentiel';
    case Professional = 'professional';
    case Elite = 'elite';

    /** Le nom commercial, tel que KLASSCI l'ecrit. */
    public function libelle(): string
    {
        return match ($this) {
            self::Free => 'Free',
            self::Essentiel => 'Essentiel',
            self::Professional => 'Professional',
            self::Elite => 'Élite',
        };
    }

    /** L'echelle des offres. Aucune n'est une alarme, aucune n'est un succes. */
    public function ton(): string
    {
        return match ($this) {
            self::Free => 'gray',
            self::Essentiel => 'info',
            self::Professional => 'primary',
            self::Elite => 'primary',
        };
    }

    public static function libelleDe(?string $valeur): string
    {
        if ($valeur === null || $valeur === '') {
            return 'Sans offre';
        }

        return self::tryFrom($valeur)?->libelle() ?? ucfirst($valeur);
    }

    public static function tonDe(?string $valeur): string
    {
        return self::tryFrom((string) $valeur)?->ton() ?? 'gray';
    }

    /** @return array<string,string> valeur => libellé, pour peupler un select */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $offre) {
            $options[$offre->value] = $offre->libelle();
        }

        return $options;
    }
}
