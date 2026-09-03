<?php

namespace App\Support\Filtres;

use App\Support\Period\PeriodFactory;
use App\Support\Period\PeriodInterface;

/**
 * Le cadrage d'un rapport de détail.
 *
 * Les quatre premiers états du portail tiennent en une ligne par établissement :
 * quatre lignes pour un groupe de quatre écoles, aucun filtre nécessaire. Les
 * états de DÉTAIL n'ont pas ce luxe. Abidjan et Yakro dépassent deux mille
 * inscriptions chacun ; le détail des paiements d'un groupe sur une année se
 * compte en dizaines de milliers de lignes, quand DomPDF en refuse mille.
 *
 * Le filtre n'est donc pas un confort ajouté à une fonctionnalité qui marcherait
 * sans lui : c'est lui qui la rend possible. Sans cadrage, un état de détail
 * serait refusé à chaque clic — ce qui ressemblerait à une panne.
 *
 * Cet objet existe pour que ce cadrage voyage d'un seul tenant : de l'écran au
 * fournisseur qui interroge les bases, puis au bandeau du document qui doit
 * dire au lecteur ce qu'il regarde. Un rapport filtré qui tait son cadrage est
 * un piège : on croit lire tout le groupe et on lit une classe.
 */
final class FiltresRapport
{
    /**
     * @param  array<int, string>  $etablissements  Codes des écoles retenues. Vide = tout le périmètre.
     * @param  ?string  $statutPaiement    'en_attente' | 'validé' | 'rejeté'
     * @param  ?string  $modePaiement      Libellé libre côté école (espèces, virement…).
     * @param  ?string  $statutInscription 'en_attente' | 'active' | 'annulée' | 'terminée'
     */
    public function __construct(
        public readonly array $etablissements = [],
        public readonly ?PeriodInterface $periode = null,
        public readonly ?string $statutPaiement = null,
        public readonly ?string $modePaiement = null,
        public readonly ?string $statutInscription = null,
    ) {
    }

    /**
     * Le cadrage par défaut d'un état de paiements.
     *
     * Volontairement SERRÉ, pas ouvert. Un défaut « tout, sur l'année » se
     * ferait refuser par le garde-fou de volume au premier clic, et le
     * directeur en conclurait que la fonctionnalité ne marche pas. Le mois en
     * cours et les paiements validés tiennent, et se desserrent d'un geste.
     */
    public static function paiementsParDefaut(): self
    {
        return new self(
            periode: PeriodFactory::make(PeriodFactory::TYPE_CURRENT_MONTH),
            statutPaiement: 'validé',
        );
    }

    /** Le cadrage par défaut d'un état d'étudiants : l'année en cours, inscriptions actives. */
    public static function etudiantsParDefaut(): self
    {
        return new self(
            periode: PeriodFactory::make(PeriodFactory::TYPE_CURRENT_YEAR),
            statutInscription: 'active',
        );
    }

    public function periode(): PeriodInterface
    {
        return $this->periode ?? PeriodFactory::default();
    }

    /** Cette école entre-t-elle dans le périmètre retenu ? */
    public function retient(string $codeEtablissement): bool
    {
        return $this->etablissements === []
            || in_array($codeEtablissement, $this->etablissements, true);
    }

    /**
     * Les filtres tels qu'ils s'écrivent dans le bandeau du document.
     *
     * On n'y met que ce qui RESTREINT. Afficher « Statut : tous » allongerait le
     * bandeau sans rien apprendre, et noierait la ligne qui, elle, compte.
     *
     * @param  array<int, string>  $nomsEtablissements  Noms lisibles, dans l'ordre du périmètre retenu.
     * @return array<string, string>
     */
    public function libelles(array $nomsEtablissements = []): array
    {
        $libelles = ['Période' => $this->periode()->label()];

        if ($nomsEtablissements !== []) {
            $libelles['Établissements'] = implode(' · ', $nomsEtablissements);
        }

        if ($this->statutPaiement !== null) {
            $libelles['Statut du paiement'] = self::libelleStatutPaiement($this->statutPaiement);
        }

        if ($this->modePaiement !== null) {
            $libelles['Mode'] = $this->modePaiement;
        }

        if ($this->statutInscription !== null) {
            $libelles['Inscription'] = self::libelleStatutInscription($this->statutInscription);
        }

        return $libelles;
    }

    /** @return array<string, string> */
    public static function statutsPaiement(): array
    {
        return [
            'validé' => 'Validé',
            'en_attente' => 'En attente',
            'rejeté' => 'Rejeté',
        ];
    }

    /** @return array<string, string> */
    public static function statutsInscription(): array
    {
        return [
            'active' => 'Active',
            'en_attente' => 'En attente',
            'annulée' => 'Annulée',
            'terminée' => 'Terminée',
        ];
    }

    public static function libelleStatutPaiement(string $statut): string
    {
        return self::statutsPaiement()[$statut] ?? $statut;
    }

    public static function libelleStatutInscription(string $statut): string
    {
        return self::statutsInscription()[$statut] ?? $statut;
    }
}
