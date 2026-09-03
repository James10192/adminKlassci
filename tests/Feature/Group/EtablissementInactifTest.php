<?php

use App\Models\Tenant;
use App\Services\Group\GroupKpiProvider;
use App\Support\EtatMesure;

/**
 * Une école suspendue n'est pas une école en panne.
 *
 * La liste des établissements du portail ne filtre pas sur le statut : elle
 * affiche aussi les écoles suspendues ou archivées. On allait donc interroger
 * une base qui, le plus souvent, n'existe plus — et le portail concluait « la
 * base de l'établissement n'a pas répondu ». Le fondateur lisait une panne
 * technique là où son groupe avait pris une décision administrative, et
 * pouvait légitimement appeler son hébergeur pour un incident qui n'existe pas.
 */
function tenantAvecStatut(string $code, string $statut): Tenant
{
    return Tenant::create([
        'code' => $code,
        'name' => strtoupper($code),
        'subdomain' => $code,
        'database_name' => "klassci_{$code}",
        // Port 1 : toute tentative de connexion échouerait immédiatement. Si
        // le court-circuit disparaissait, le motif retomberait sur
        // « injoignable » et le test le verrait.
        'database_credentials' => ['host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'y'],
        'git_branch' => 'main',
        'status' => $statut,
        'plan' => 'elite',
    ]);
}

it('nomme la suspension plutôt que d\'accuser la base de l\'établissement', function () {
    $kpis = app(GroupKpiProvider::class)->computeTenantKpis(tenantAvecStatut('suspendu', 'suspended'));

    expect($kpis['motif'])->toBe(EtatMesure::MOTIF_INACTIF);
    expect($kpis['error'])->toBeTrue();

    // La phrase montrée au fondateur ne doit pas parler de la base.
    expect(EtatMesure::libelleMotif($kpis['motif']))->toBe("l'établissement n'est pas actif");
    expect(EtatMesure::libelleMotif($kpis['motif']))->not->toContain('répondu');

    // Le badge de la tuile le dit aussi, sans le mot « Erreur ».
    expect(EtatMesure::badge($kpis['etat_finances'], $kpis['motif']))->toBe('Hors service');
});

it('vaut aussi pour une école résiliée', function () {
    // Le test utilisait « archived », un mot qui n'appartient à AUCUN
    // vocabulaire du dépôt : l'énumération SQL est
    // active|suspended|maintenance|cancelled. Il ne passait que parce que la
    // suite tourne sur SQLite, qui n'applique pas les énumérations — et il
    // laissait donc les deux seuls autres statuts réels sans couverture. C'est
    // exactement ce trou qui a laissé passer la régression ci-dessous.
    $kpis = app(GroupKpiProvider::class)->computeTenantKpis(tenantAvecStatut('resilie', 'cancelled'));

    expect($kpis['motif'])->toBe(EtatMesure::MOTIF_INACTIF);
});

it('MESURE une école en maintenance — la maintenance n\'est pas une suspension', function () {
    // La maintenance est un état d'exploitation TRANSITOIRE : le temps d'un
    // déploiement, deux à cinq minutes. La base MySQL de l'école répond
    // parfaitement pendant ce temps.
    //
    // Une première version du court-circuit testait « tout sauf actif » et
    // coupait donc aussi la maintenance : pendant chaque déploiement, la ligne
    // de l'école passait en tirets gris avec le badge « Hors service », et ses
    // deux mille étudiants disparaissaient de l'écran de leur directeur. C'est
    // le miroir exact du défaut que tout ce chantier corrige — présenter une
    // mesure disponible comme une absence, et l'attribuer à une décision
    // administrative qui n'a pas été prise.
    //
    // La base est injoignable (port 1) : le motif de PANNE prouve qu'on a bien
    // TENTÉ la connexion au lieu de court-circuiter.
    $kpis = app(GroupKpiProvider::class)->computeTenantKpis(tenantAvecStatut('en-maintenance', 'maintenance'));

    expect($kpis['motif'])->toBe(EtatMesure::MOTIF_INJOIGNABLE);
    expect($kpis['motif'])->not->toBe(EtatMesure::MOTIF_INACTIF);
});

it('couvre les quatre statuts réels du schéma, et eux seuls', function () {
    // Le garde-fou contre la régression precedente : si un statut apparait ou
    // disparait de l'enumeration SQL, ce test le dit avant qu'un ecran ne
    // l'apprenne au directeur.
    $statuts = array_map(fn ($c) => $c->value, \App\Enums\TenantStatus::cases());

    expect($statuts)->toEqualCanonicalizing(['active', 'suspended', 'maintenance', 'cancelled']);

    // Et la seule question qui compte pour le portail : qui se mesure ?
    expect(\App\Enums\TenantStatus::Active->mesurable())->toBeTrue();
    expect(\App\Enums\TenantStatus::Maintenance->mesurable())->toBeTrue();
    expect(\App\Enums\TenantStatus::Suspended->mesurable())->toBeFalse();
    expect(\App\Enums\TenantStatus::Cancelled->mesurable())->toBeFalse();

    // Un statut inconnu ne s'interroge pas : on n'ouvre pas de connexion vers
    // une base dont on ignore l'etat de l'etablissement.
    expect(\App\Enums\TenantStatus::mesurableDe('mot-inconnu'))->toBeFalse();
    expect(\App\Enums\TenantStatus::mesurableDe(null))->toBeFalse();
});

it('ne présente aucune de ses grandeurs comme mesurée', function () {
    $kpis = app(GroupKpiProvider::class)->computeTenantKpis(tenantAvecStatut('suspendu2', 'suspended'));

    // Le fond du chantier : zéro n'est pas une mesure. Les quatre familles
    // doivent refuser d'afficher leurs zéros.
    foreach (['etat_effectifs', 'etat_personnel', 'etat_finances', 'etat_assiduite'] as $famille) {
        expect(EtatMesure::aUneValeur($kpis[$famille]))->toBeFalse("famille {$famille}");
    }
});

it('laisse passer une école active vers l\'interrogation de sa base', function () {
    // Contre-épreuve : le court-circuit ne doit pas avaler le chemin nominal.
    // La base est injoignable (port 1), donc on attend le motif de panne — la
    // preuve qu'on a bien TENTÉ la connexion.
    $kpis = app(GroupKpiProvider::class)->computeTenantKpis(tenantAvecStatut('actif', 'active'));

    expect($kpis['motif'])->toBe(EtatMesure::MOTIF_INJOIGNABLE);
});

it('la puce de fraîcheur du tableau de bord suit le périmètre, pas l\'horloge', function () {
    $ageMesure = new ReflectionMethod(\App\Filament\Group\Pages\GroupDashboard::class, 'ageMesure');
    $ageMesure->setAccessible(true);

    // Aucune école n'a répondu : le calcul vient d'avoir lieu, mais il n'a rien
    // mesuré. Pas d'horodatage.
    expect($ageMesure->invoke(null, [
        'computed_at' => now()->toIso8601String(),
        'perimetre' => [
            'effectifs' => ['total' => 4, 'repondu' => 0],
            'finances' => ['total' => 4, 'repondu' => 0],
        ],
    ]))->toBeNull();

    // Une seule famille mesurée suffit : le fondateur voit un chiffre réel.
    expect($ageMesure->invoke(null, [
        'computed_at' => now()->toIso8601String(),
        'perimetre' => [
            'effectifs' => ['total' => 4, 'repondu' => 2],
            'finances' => ['total' => 4, 'repondu' => 0],
        ],
    ]))->not->toBeNull();
});
