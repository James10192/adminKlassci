<?php

use App\Models\Group;
use App\Models\Tenant;
use App\Services\Group\GroupKpiProvider;
use App\Support\EtatMesure;

/**
 * Le taux de recouvrement du groupe se pondère par les montants.
 *
 * Le Benchmarking faisait la moyenne arithmétique des taux de chaque école, là
 * où le tableau de bord divise l'encaissé du groupe par son attendu. Les deux
 * écrans affichaient donc deux taux différents pour le même groupe, le même
 * jour — et c'est l'écran de comparaison, celui qu'on ouvre justement pour
 * arbitrer, qui donnait le chiffre flatteur.
 *
 * Une première version de ces tests lisait le fichier Blade et cherchait des
 * noms de variables. Elle passait au vert sur une régression volontaire (la
 * chaîne attendue survivait dans un commentaire) et au rouge sur un simple
 * renommage. On teste ici le producteur du chiffre, pas son texte.
 */
function tenantMesure(Group $groupe, string $code, float $attendu, float $encaisse): Tenant
{
    return Tenant::create([
        'group_id' => $groupe->id,
        'code' => $code,
        'name' => strtoupper($code),
        'subdomain' => $code,
        'database_name' => "klassci_{$code}",
        'database_credentials' => ['host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'y'],
        'git_branch' => 'main',
        'status' => 'active',
        'plan' => 'elite',
    ]);
}

/**
 * Rejoue l'agrégation du groupe sur des mesures fournies, sans base d'école.
 *
 * `computeGroupKpis()` boucle sur `activeTenants` puis agrège ; on lui donne
 * ici directement des KPI d'établissement mesurés, ce qu'aucune base MySQL
 * n'est disponible pour produire dans cet environnement.
 *
 * @param  array<string,array{attendu: float, encaisse: float}>  $ecoles
 * @return array<string,mixed>
 */
function totauxGroupe(array $ecoles): array
{
    $groupe = Group::create(['name' => 'G', 'code' => 'g' . count($ecoles), 'status' => 'active']);

    $etablissements = [];
    foreach ($ecoles as $code => $montants) {
        tenantMesure($groupe, $code, $montants['attendu'], $montants['encaisse']);
        $etablissements[$code] = [
            'tenant_name' => strtoupper($code),
            'students' => 100,
            'inscriptions' => 100,
            'staff' => 10,
            'revenue_expected' => $montants['attendu'],
            'revenue_collected' => $montants['encaisse'],
            'collection_rate' => $montants['attendu'] > 0
                ? round(($montants['encaisse'] / $montants['attendu']) * 100, 1)
                : null,
            'attendance_rate' => 90,
            'etat_effectifs' => EtatMesure::MESURE,
            'etat_personnel' => EtatMesure::MESURE,
            'etat_finances' => $montants['attendu'] > 0 ? EtatMesure::MESURE : EtatMesure::NON_APPLICABLE,
            'etat_assiduite' => EtatMesure::MESURE,
            'motif' => null,
            // Comme le chemin nominal : le motif propre aux finances distingue
            // « aucun frais configuré » de « module absent » et de « panne ».
            'motif_finances' => $montants['attendu'] > 0 ? null : EtatMesure::MOTIF_SANS_FRAIS,
        ];
    }

    // On rejoue l'agrégation RÉELLE en substituant l'agrégateur, qui est le
    // seul point où le provider ouvre les bases des écoles. Tout ce qui suit —
    // sommes, périmètre, taux — est le code de production.
    $agregateur = new class($etablissements) extends \App\Services\Group\TenantAggregator
    {
        /** @param array<string,mixed> $mesures */
        public function __construct(private readonly array $mesures)
        {
        }

        public function aggregate(
            \App\Models\Group $group,
            string $providerClass,
            string $methodName,
            string $label,
            ?\App\Support\Period\PeriodInterface $period = null,
        ): array {
            return $this->mesures;
        }
    };

    $provider = new GroupKpiProvider(
        app(\App\Services\TenantConnectionManager::class),
        $agregateur,
        app(\App\Services\Group\TenantBillingContext::class),
    );

    return $provider->computeGroupKpis($groupe->fresh());
}

it('pondère par les montants — une grosse école en difficulté pèse ce qu\'elle pèse', function () {
    // Une petite école à jour et une grosse en difficulté.
    $totaux = totauxGroupe([
        'petite' => ['attendu' => 100_000.0, 'encaisse' => 100_000.0],   // 100 %
        'grosse' => ['attendu' => 1_000_000.0, 'encaisse' => 100_000.0], //  10 %
    ]);

    // 200 000 / 1 100 000 = 18,2 %. La moyenne simple des deux taux donnerait
    // 55 % — 37 points d'écart, soit « critique » contre « à surveiller ».
    expect($totaux['collection_rate'])->toBe(18.2);
    expect($totaux['finances_mesurables'])->toBeTrue();
});

it('ne fabrique aucun taux quand rien n\'est attendu', function () {
    // Deux écoles qui répondent, dont aucun frais n'est encore configuré : le
    // taux valait `0` et s'affichait en ROUGE, mention « critique », le jour
    // même où le groupe ouvrait son année.
    $totaux = totauxGroupe([
        'a' => ['attendu' => 0.0, 'encaisse' => 0.0],
        'b' => ['attendu' => 0.0, 'encaisse' => 0.0],
    ]);

    expect($totaux['collection_rate'])->toBeNull();
    expect($totaux['finances_mesurables'])->toBeFalse();

    // Et la raison donnée n'accuse pas la base : elle a répondu.
    $manquants = $totaux['perimetre']['finances']['manquants'];
    expect(EtatMesure::raisonCommune($manquants))->toBe("aucun frais n'est configuré pour cette période");
});

it('additionne bien quand tout est mesuré', function () {
    $totaux = totauxGroupe([
        'a' => ['attendu' => 400_000.0, 'encaisse' => 300_000.0],
        'b' => ['attendu' => 600_000.0, 'encaisse' => 300_000.0],
    ]);

    expect($totaux['total_revenue_expected'])->toBe(1_000_000.0);
    expect($totaux['total_revenue_collected'])->toBe(600_000.0);
    expect($totaux['collection_rate'])->toBe(60.0);
});
