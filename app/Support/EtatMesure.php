<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * D'où vient un chiffre du portail groupe — et comment le dire.
 *
 * Le portail interroge la base de chaque établissement à chaque affichage.
 * Quand une base ne répond pas, le code retournait jusqu'ici une ligne de
 * zéros que rien ne distinguait d'une mesure : une école injoignable
 * affichait « 0 étudiant, 0 % de recouvrement », et le total du groupe
 * additionnait cet échec de connexion comme s'il s'agissait d'une recette
 * nulle. Le fondateur lisait une école vide là où il n'y avait qu'une panne.
 *
 * Quatre états, trois rendus. `NON_APPLICABLE` se dessine comme
 * `NON_MESURE` — un tiret gris — et n'en diffère que par la phrase qui
 * l'accompagne.
 *
 *   MESURE          la base a répondu. `0` veut alors dire zéro.
 *   RELEVE          la base n'a pas répondu, mais on détient un relevé daté de
 *                   LA MÊME grandeur. On l'affiche avec son âge.
 *   NON_MESURE      pas de réponse, aucun relevé pour cette grandeur : « — ».
 *   NON_APPLICABLE  le module n'existe pas chez cette école : « — » aussi,
 *                   mais ce n'est pas une panne et il ne faut pas l'alerter.
 *
 * `RELEVE` n'a aujourd'hui **aucun producteur**, et c'est délibéré : les
 * colonnes `current_*` de `klassci_master` ne mesurent pas les mêmes
 * populations que les indicateurs du portail (voir le docblock de
 * `GroupKpiProvider::emptyKpis()`, qui détaille les trois divergences). Les y
 * faire passer pour un relevé remplacerait un zéro visible par un chiffre faux
 * et invisible. L'état reste déclaré parce qu'il est juste et que le jour où un
 * relevé mesurera la même chose il aura sa place — pas parce qu'il sert
 * aujourd'hui.
 *
 * L'état n'appartient pas à l'établissement, il appartient à **chaque famille
 * d'indicateur** : une école peut être mesurée sur ses effectifs et muette sur
 * sa paie. Un seul état par école serait déjà un mensonge d'un cran.
 *
 * Tout ce qui nomme ou colore un état passe ici. Une vue qui réécrit
 * « Erreur » en dur dérive au premier changement de mot, et dérive en
 * silence — c'est exactement ce qui est arrivé à « non consolidé », resté
 * dans deux états exportés alors qu'il n'y voulait pas dire ce qu'il dit en
 * comptabilité.
 */
final class EtatMesure
{
    public const MESURE = 'mesure';
    public const RELEVE = 'releve';
    public const NON_MESURE = 'non_mesure';
    public const NON_APPLICABLE = 'non_applicable';

    /** Ce qu'on affiche à la place d'un chiffre qu'on n'a pas. Jamais « 0 ». */
    public const TIRET = '—';

    /** La base n'a pas répondu. */
    public const MOTIF_INJOIGNABLE = 'injoignable';

    /** La base a répondu, mais aucune année universitaire n'y est courante. */
    public const MOTIF_SANS_ANNEE = 'sans_annee';

    /** L'école n'utilise pas ce module — aucune paie, aucun bulletin, jamais. */
    public const MOTIF_SANS_MODULE = 'sans_module';

    /** L'établissement n'est pas actif : suspendu ou résilié. */
    public const MOTIF_INACTIF = 'inactif';

    /**
     * La base a répondu, mais aucun montant n'est attendu sur la période.
     *
     * Ce n'est ni une panne ni un module absent : l'école n'a simplement pas
     * encore configuré ses frais. Sans ce motif, son taux de recouvrement
     * valait `0 %` — affiché en ROUGE avec la mention « critique » — le jour
     * même où elle ouvrait son année.
     */
    public const MOTIF_SANS_FRAIS = 'sans_frais';

    /** Un chiffre est-il le résultat d'une mesure présente ? */
    public static function estMesure(?string $etat): bool
    {
        return ($etat ?? self::MESURE) === self::MESURE;
    }

    /**
     * Peut-on afficher une valeur, même ancienne ?
     *
     * Vrai pour une mesure et pour un relevé. Faux pour le reste : la vue
     * affiche alors le tiret, pas un zéro.
     */
    public static function aUneValeur(?string $etat): bool
    {
        return in_array($etat ?? self::MESURE, [self::MESURE, self::RELEVE], true);
    }

    /**
     * Le badge de la tuile établissement.
     *
     * Il disait « Erreur », en rouge. Il accusait l'école d'une faute qu'elle
     * n'a pas commise et ne disait rien d'utile au fondateur.
     */
    public static function badge(?string $etat, ?string $motif = null): string
    {
        if ($motif === self::MOTIF_SANS_ANNEE) {
            return 'Année non configurée';
        }

        if ($motif === self::MOTIF_INACTIF) {
            return 'Hors service';
        }

        if ($motif === self::MOTIF_SANS_FRAIS) {
            return 'Frais non configurés';
        }

        return match ($etat) {
            self::RELEVE => 'Dernier relevé',
            self::NON_MESURE => 'Non mesuré',
            self::NON_APPLICABLE => 'Non applicable',
            default => 'Mesuré',
        };
    }

    /**
     * La couleur.
     *
     * Gris, jamais rouge, jamais vert. Une école injoignable n'est pas une
     * école en faute : c'est une école dont on ne sait rien. Le rouge reste au
     * risque mesuré, le vert à la santé mesurée.
     */
    public static function ton(?string $etat): string
    {
        return self::estMesure($etat) ? 'mesure' : 'inconnu';
    }

    /** La phrase qui explique pourquoi il n'y a pas de chiffre. */
    public static function libelleMotif(?string $motif): string
    {
        return match ($motif) {
            self::MOTIF_SANS_ANNEE => "aucune année universitaire n'est en cours",
            self::MOTIF_SANS_MODULE => "l'établissement n'utilise pas ce module",
            self::MOTIF_SANS_FRAIS => "aucun frais n'est configuré pour cette période",
            self::MOTIF_INACTIF => "l'établissement n'est pas actif",
            default => "la base de l'établissement n'a pas répondu",
        };
    }

    /**
     * L'âge d'un relevé, en français, sans le « il y a » de Carbon qu'on
     * veut parfois placer soi-même.
     */
    public static function age(?CarbonInterface $mesureA): ?string
    {
        if ($mesureA === null) {
            return null;
        }

        return $mesureA->locale('fr')->diffForHumans(['short' => false]);
    }

    /**
     * Ce qu'on écrit sous un TOTAL DE GROUPE qu'aucune école n'a alimenté.
     *
     * Cette phrase était retapée dans chaque vue, et elle avait déjà dérivé :
     * dans la même rangée du hero, « Étudiants inscrits » disait « non mesuré »
     * pendant que « Recouvrement », juste à côté, disait « aucun établissement
     * mesuré » — pour exactement le même état. Deux formulations d'une même
     * absence, à deux centimètres l'une de l'autre.
     *
     * Pour l'absence d'UN établissement, c'est `libelleMotif()` qu'il faut :
     * elle nomme la cause, ce qu'un total de groupe ne peut pas faire.
     */
    public static function absenceGroupe(): string
    {
        return 'aucun établissement mesuré';
    }

    /**
     * La raison commune d'un périmètre non mesuré, ou une formule qui n'invente
     * aucune cause quand les écoles manquent pour des raisons différentes.
     *
     * `libelleMotif(null)` retombe sur « la base n'a pas répondu ». Appelée
     * sans motif réel sous un graphique de groupe, elle AFFIRMAIT une panne
     * réseau : le fondateur partait appeler son hébergeur alors que ses écoles
     * avaient répondu — sans avoir ouvert leur année universitaire.
     *
     * @param  array<string, array{nom?: string, motif?: string}>  $manquants
     */
    public static function raisonCommune(array $manquants): ?string
    {
        // Aucun etablissement concerne : il n'y a aucune raison a donner.
        // Sans ce garde, la methode retournait « les etablissements concernes
        // n'ont pas tous la meme raison » pour un groupe SANS etablissement —
        // une phrase qui parle d'etablissements qui n'existent pas.
        if ($manquants === []) {
            return null;
        }

        $motifs = array_unique(array_map(
            static fn (array $m): string => $m['motif'] ?? self::MOTIF_INJOIGNABLE,
            $manquants,
        ));

        if (count($motifs) === 1) {
            return self::libelleMotif(reset($motifs));
        }

        // Plusieurs causes distinctes : on ne choisit pas pour le lecteur.
        return 'les établissements concernés n\'ont pas tous la même raison';
    }

    /**
     * La mention de périmètre à coller sous un total amputé.
     *
     * Retourne null quand le total est complet — c'est la seule situation où
     * le portail se tait — et null aussi pour un groupe d'une seule école,
     * où « sur 1 des 1 établissements » serait grotesque : l'état seul suffit.
     */
    public static function mentionPerimetre(int $repondu, int $total): ?string
    {
        if ($total <= 1 || $repondu >= $total) {
            return null;
        }

        return sprintf(
            'sur %d des %d établissements',
            $repondu,
            $total,
        );
    }

    /**
     * La mention d'un total qui contient des relevés.
     *
     * Un total additionnant un relevé n'est pas une mesure : il bascule
     * entièrement en état RELEVE, et le dit.
     */
    public static function mentionReleves(int $nbReleves): ?string
    {
        if ($nbReleves < 1) {
            return null;
        }

        return sprintf(
            'dont %d établissement%s au dernier relevé',
            $nbReleves,
            $nbReleves > 1 ? 's' : '',
        );
    }
}
