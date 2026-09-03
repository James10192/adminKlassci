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

it('vaut pour toute école non active, pas seulement pour les suspendues', function () {
    $kpis = app(GroupKpiProvider::class)->computeTenantKpis(tenantAvecStatut('archive', 'archived'));

    expect($kpis['motif'])->toBe(EtatMesure::MOTIF_INACTIF);
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
