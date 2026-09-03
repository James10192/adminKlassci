<?php

namespace App\Domain\Exports\Reports;

use App\Domain\Exports\TableauReport;
use App\Support\EtatMesure;

/**
 * L'état des établissements : la vue que le directeur général adjoint
 * demandait — effectifs, personnel, assiduité, recouvrement, sur une page.
 */
class EtatEtablissementsReport extends TableauReport
{
    /**
     * @param  array<string, mixed>  $kpis  Sortie de GroupKpiProvider::computeGroupKpis().
     */
    public function __construct(
        private readonly array $kpis,
        private readonly string $nomGroupe,
        private readonly string $periode,
    ) {
    }

    public function title(): string
    {
        return 'État des établissements';
    }

    public function subtitle(): ?string
    {
        return $this->nomGroupe;
    }

    public function filters(): array
    {
        return [
            'Période' => $this->periode,
            'Effectifs' => 'année universitaire en cours',
        ];
    }

    public function orientation(): string
    {
        return 'landscape';
    }

    public function colonnes(): array
    {
        return [
            ['label' => 'Établissement', 'format' => self::TEXTE],
            ['label' => 'Année', 'format' => self::TEXTE],
            ['label' => 'Étudiants', 'format' => self::NOMBRE],
            ['label' => 'Inscriptions', 'format' => self::NOMBRE],
            ['label' => 'Personnel', 'format' => self::NOMBRE],
            ['label' => 'Assiduité', 'format' => self::POURCENT],
            ['label' => 'Recouvrement', 'format' => self::POURCENT],
            ['label' => 'Offre', 'format' => self::TEXTE],
        ];
    }

    public function lignes(): array
    {
        $lignes = [];

        foreach ($this->kpis['establishments'] ?? [] as $etablissement) {
            // « (non consolidé) » disait autre chose que ce qu'on voulait dire :
            // en SYSCOHADA comme en IFRS, c'est « retraitements de consolidation
            // non effectués », pas « manquant ». Suivi sur la même ligne de
            // « 0 étudiant, 0 % de recouvrement », l'état affirmait devant un
            // banquier un fait qui n'était pas le nôtre.
            //
            // Et les zéros eux-mêmes partaient : une colonne vide dit qu'on ne
            // sait pas ; un zéro affirme qu'on sait, et qu'il n'y a rien. La vue
            // PDF rend `null` par un tiret, Excel le laisse vide.
            $effectifs = $etablissement['etat_effectifs'] ?? EtatMesure::MESURE;
            $finances = $etablissement['etat_finances'] ?? EtatMesure::MESURE;
            $assiduite = $etablissement['etat_assiduite'] ?? EtatMesure::MESURE;

            $nom = $etablissement['tenant_name'] ?? EtatMesure::TIRET;
            if (! EtatMesure::estMesure($finances) || ! EtatMesure::estMesure($effectifs)) {
                $nom .= ' (' . mb_strtolower(EtatMesure::badge(
                    EtatMesure::estMesure($effectifs) ? $finances : $effectifs,
                    $etablissement['motif'] ?? null,
                )) . ')';
            }

            $lignes[] = [
                $nom,
                $etablissement['academic_year'] ?? null,
                EtatMesure::aUneValeur($effectifs) ? (int) ($etablissement['students'] ?? 0) : null,
                EtatMesure::aUneValeur($effectifs) ? (int) ($etablissement['inscriptions'] ?? 0) : null,
                EtatMesure::aUneValeur($etablissement['etat_personnel'] ?? EtatMesure::MESURE)
                    ? (int) ($etablissement['staff'] ?? 0)
                    : null,
                EtatMesure::estMesure($assiduite) ? (float) ($etablissement['attendance_rate'] ?? 0) : null,
                EtatMesure::estMesure($finances) ? (float) ($etablissement['collection_rate'] ?? 0) : null,
                $etablissement['plan'] ?? EtatMesure::TIRET,
            ];
        }

        return $lignes;
    }

    public function totaux(): ?array
    {
        if (empty($this->kpis['establishments'] ?? [])) {
            return null;
        }

        $perimetre = $this->kpis['perimetre'] ?? [];

        $mesure = fn (string $famille): bool => EtatMesure::aUneValeur(
            $perimetre[$famille]['etat'] ?? EtatMesure::MESURE
        );

        // Le total nomme son perimetre. « TOTAL GROUPE » sur trois ecoles
        // mesurees parmi quatre est un total du groupe qui n'existe pas.
        //
        // La mention se construit sur TOUTES les familles amputees, pas sur les
        // seules finances : un total d'effectifs partiel alors que les finances
        // sont completes ne disait rien.
        // Les QUATRE familles : `totaux()` imprime aussi `avg_attendance_rate`,
        // et l'assiduite manquait a cette boucle — le seul chiffre du tableau
        // dont le perimetre ampute n'etait jamais dit.
        $mentions = [];
        foreach (['effectifs', 'personnel', 'finances', 'assiduite'] as $famille) {
            $m = EtatMesure::mentionPerimetre(
                $perimetre[$famille]['repondu'] ?? 0,
                $perimetre[$famille]['total'] ?? 0,
            );
            if ($m !== null) {
                $mentions[$m] = $m;
            }
        }
        $mention = $mentions === [] ? null : implode(' · ', $mentions);

        // Les lignes par etablissement renvoient `null` quand rien n'est mesure
        // — le formateur PDF en fait un tiret, le tableur une case vide. Le
        // TOTAL, lui, imprimait encore de vrais zeros : deux conventions dans le
        // meme tableau, et « TOTAL GROUPE · 0 etudiant · 0,0 % » sous des lignes
        // vides pour un groupe d'une seule ecole injoignable — cas ou
        // `mentionPerimetre()` se tait, puisqu'elle ne commente pas un groupe
        // d'un seul etablissement.
        return [
            'TOTAL GROUPE' . ($mention ? ' — ' . $mention : ''),
            '',
            $mesure('effectifs') ? (int) ($this->kpis['total_students'] ?? 0) : null,
            $mesure('effectifs') ? (int) ($this->kpis['total_inscriptions'] ?? 0) : null,
            $mesure('personnel') ? (int) ($this->kpis['total_staff'] ?? 0) : null,
            // Assiduité et recouvrement sont déjà pondérés par le fournisseur :
            // une moyenne simple donnerait le même poids à toutes les écoles.
            $mesure('assiduite') ? (float) ($this->kpis['avg_attendance_rate'] ?? 0) : null,
            $mesure('finances') ? (float) ($this->kpis['collection_rate'] ?? 0) : null,
            '',
        ];
    }
}
