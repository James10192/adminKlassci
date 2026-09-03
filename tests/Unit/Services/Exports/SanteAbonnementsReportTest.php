<?php

use App\Domain\Exports\Reports\SanteAbonnementsReport;
use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Models\Tenant;
use App\Support\Alerts\AlertPayload;
use App\Support\EtatMesure;

/**
 * L'état de santé et l'échéance des abonnements — les deux choses qu'un
 * fondateur regarde avant un conseil, et qui n'existaient qu'à l'écran.
 */
function etablissement(array $attributs = []): Tenant
{
    return new Tenant(array_merge([
        'name' => 'ISLG Rostan',
        'code' => 'islg-rostan',
        'plan' => 'elite',
        'status' => 'active',
        'max_users' => 100, 'current_users' => 50,
        'max_staff' => 0, 'current_staff' => 0,
        'max_students' => 1000, 'current_students' => 900,
        'max_inscriptions_per_year' => 0, 'current_inscriptions_per_year' => 0,
        'max_storage_mb' => 0, 'current_storage_mb' => 0,
    ], $attributs));
}

function rapportSante(array $etablissements, array $sante = []): SanteAbonnementsReport
{
    return new SanteAbonnementsReport(collect($etablissements), $sante, 'Groupe ROSTAN', 'Année 2026');
}

it('écrit l échéance en toutes lettres plutôt qu en nombre à interpréter', function () {
    // Une colonne « jours restants » aurait imprimé « -12 » pour un abonnement
    // expiré depuis douze jours. Sur un document qui circule, un nombre négatif
    // se lit mal, et parfois se lit à l'envers.
    $lignes = rapportSante([
        etablissement(['name' => 'Expiré', 'subscription_end_date' => now()->subDays(12)]),
        etablissement(['name' => 'Aujourd hui', 'subscription_end_date' => now()]),
        etablissement(['name' => 'Demain', 'subscription_end_date' => now()->addDay()]),
        etablissement(['name' => 'Plus tard', 'subscription_end_date' => now()->addDays(40)]),
    ])->lignes();

    expect($lignes[0][4])->toBe('Expiré depuis 12 jours')
        ->and($lignes[1][4])->toBe("Expire aujourd'hui")
        // Le singulier : « Dans 1 jours » sur l'alerte la plus urgente qu'un
        // fondateur verra fait douter du reste du tableau.
        ->and($lignes[2][4])->toBe('Dans 1 jour')
        ->and($lignes[3][4])->toBe('Dans 40 jours');
});

it('ne transforme pas une absence de date en échéance imminente', function () {
    // Sans date de fin (offre gratuite, donnée absente), `daysRemaining()`
    // rend null. Un `(int)` silencieux en aurait fait 0, donc « expire
    // aujourd'hui » — une alarme pour un établissement qui n'a rien demandé.
    $lignes = rapportSante([etablissement(['subscription_end_date' => null])])->lignes();

    expect($lignes[0][3])->toBe(EtatMesure::TIRET)
        ->and($lignes[0][4])->toBe(EtatMesure::TIRET);
});

it('traduit l offre et le statut', function () {
    $lignes = rapportSante([etablissement(['plan' => 'elite', 'status' => 'active'])])->lignes();

    expect($lignes[0][1])->toBe('Élite')
        ->and($lignes[0][2])->toBe('Actif');
});

it('montre le quota le plus tendu, et rien quand aucun plafond n est fixé', function () {
    $lignes = rapportSante([
        // 50/100 = 50 %, 900/1000 = 90 % : c'est 90 % qui compte.
        etablissement(),
        etablissement(['name' => 'Sans plafond', 'max_users' => 0, 'max_students' => 0]),
    ])->lignes();

    expect($lignes[0][5])->toBe(90.0)
        // Un établissement sans plafond n'est pas un établissement à 0 %.
        ->and($lignes[1][5])->toBeNull();
});

it('rattache chaque alerte à son établissement et affiche la pire gravité', function () {
    $bouake = etablissement(['name' => 'Rostan Bouaké', 'code' => 'rostan-bouake']);
    $daloa = etablissement(['name' => 'Rostan Daloa', 'code' => 'rostan-daloa']);

    $rapport = rapportSante([$bouake, $daloa], [
        'alerts' => [
            AlertPayload::make($bouake, AlertSeverity::Warning, AlertType::QuotaCritical, 'Quota étudiants à 98,6 %'),
            AlertPayload::make($bouake, AlertSeverity::Critical, AlertType::SubscriptionExpiring, 'Abonnement expire dans 3 jours'),
        ],
    ]);

    $lignes = $rapport->lignes();

    expect($lignes[0][6])->toBe('Critique')
        ->and($lignes[0][7])->toContain('Quota étudiants')
        ->and($lignes[0][7])->toContain('Abonnement expire')
        // L'établissement sans alerte ne doit pas hériter de celles du voisin.
        ->and($lignes[1][6])->toBe(EtatMesure::TIRET)
        ->and($lignes[1][7])->toBe(EtatMesure::TIRET);
});

it('donne aux compteurs de tête leur dénominateur', function () {
    // « 2 » seul ne dit pas si c'est deux sur trois ou deux sur quarante.
    $a = etablissement(['name' => 'A', 'code' => 'a']);

    $filtres = rapportSante([$a, etablissement(['name' => 'B', 'code' => 'b'])], [
        'subscription_expiring_total_count' => 1,
        'alerts' => [AlertPayload::make($a, AlertSeverity::Warning, AlertType::QuotaCritical, 'x')],
    ])->filters();

    expect($filtres['Abonnements à échéance'])->toBe('1 sur 2')
        ->and($filtres['Établissements en alerte'])->toBe('1 sur 2');
});

it('se tait sur les compteurs quand il n y a rien à signaler', function () {
    $filtres = rapportSante([etablissement()])->filters();

    expect($filtres)->not->toHaveKey('Abonnements à échéance')
        ->and($filtres)->not->toHaveKey('Établissements en alerte');
});

it('déclare la largeur de ses colonnes', function () {
    // Huit colonnes sur une page : sans largeurs declarees, DomPDF coupait
    // « Dans 547 jours » en deux lignes.
    $largeurs = array_map(fn (array $c) => $c['largeur'] ?? null, rapportSante([])->colonnes());

    expect($largeurs)->not->toContain(null)
        ->and(array_sum($largeurs))->toBe(100);
});
