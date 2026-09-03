<?php

namespace App\Services\Group\Detail;

use App\Models\Group;
use App\Support\Filtres\FiltresRapport;
use Illuminate\Support\Facades\DB;

/**
 * Qui est inscrit, où, et depuis quand.
 *
 * SCOLARITÉ SEULE, par choix explicite du fondateur : matricule, identité,
 * sexe, classe, filière, niveau, date et statut d'inscription. Ni téléphone,
 * ni adresse électronique, ni date de naissance.
 *
 * Ce n'est pas un oubli à combler un jour où l'on aura le temps. Un état
 * consolidé fait franchir à des données personnelles la frontière de l'école
 * qui les a collectées pour rejoindre un document de groupe ; les colonnes
 * qu'on n'ajoute pas sont celles qui ne franchiront pas cette frontière. Le
 * pilotage se fait sans elles — la relance individuelle, qui en aurait besoin,
 * se fait dans l'école, avec le consentement recueilli là.
 *
 * Qui voudra les ajouter devra donc le décider, pas simplement le coder.
 */
class FournisseurEffectifs extends FournisseurDetail
{
    /**
     * @return array{lignes: array<int, array<string, mixed>>, total: int, repondants: int, manquants: array<string, array{nom: string, motif: string}>}
     */
    public function pourGroupe(Group $groupe, FiltresRapport $filtres): array
    {
        return $this->parcourir($groupe, $filtres, function (string $conn, $etablissement, object $annee) use ($filtres): array {
            $requete = DB::connection($conn)
                ->table('esbtp_inscriptions as i')
                ->join('esbtp_etudiants as e', 'e.id', '=', 'i.etudiant_id')
                ->leftJoin('esbtp_classes as c', 'c.id', '=', 'i.classe_id')
                ->leftJoin('esbtp_filieres as f', 'f.id', '=', 'i.filiere_id')
                ->leftJoin('esbtp_niveau_etudes as n', 'n.id', '=', 'i.niveau_id')
                ->where('i.annee_universitaire_id', $annee->id)
                // Une inscription supprimée n'est plus un effectif.
                ->whereNull('i.deleted_at');

            if ($filtres->statutInscription !== null) {
                $requete->where('i.status', $filtres->statutInscription);
            }

            return $requete
                ->orderBy('e.nom')
                ->orderBy('e.prenoms')
                ->get([
                    'e.matricule', 'e.nom', 'e.prenoms', 'e.sexe',
                    'c.name as classe', 'f.name as filiere', 'n.name as niveau',
                    'i.date_inscription', 'i.status', 'i.type_inscription',
                ])
                ->map(fn ($l): array => [
                    'matricule' => $l->matricule,
                    'nom' => $l->nom,
                    'prenoms' => $l->prenoms,
                    'sexe' => $l->sexe,
                    'classe' => $l->classe,
                    'filiere' => $l->filiere,
                    'niveau' => $l->niveau,
                    'date_inscription' => $l->date_inscription,
                    'type' => $l->type_inscription,
                    'statut' => $l->status,
                ])
                ->all();
        });
    }

    /**
     * Par école, puis par classe, puis par nom.
     *
     * L'ordre d'une liste d'appel : c'est ainsi qu'une directrice la lit, et
     * c'est ainsi qu'elle la compare à celle de sa secrétaire.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     * @return array<int, array<string, mixed>>
     */
    protected function ordonner(array $lignes): array
    {
        usort($lignes, static function (array $a, array $b): int {
            return [
                (string) $a['etablissement'],
                (string) ($a['classe'] ?? ''),
                (string) ($a['nom'] ?? ''),
                (string) ($a['prenoms'] ?? ''),
            ] <=> [
                (string) $b['etablissement'],
                (string) ($b['classe'] ?? ''),
                (string) ($b['nom'] ?? ''),
                (string) ($b['prenoms'] ?? ''),
            ];
        });

        return $lignes;
    }
}
