<?php

use App\Models\Group;
use App\Models\Tenant;
use App\Services\TenantAggregationService;
use App\Support\EtatMesure;

/**
 * Ce que le portail affiche quand la base d'un établissement ne répond pas.
 *
 * Les établissements de ce test portent des identifiants de connexion
 * volontairement injoignables : c'est la seule façon d'éprouver le chemin qui
 * compte. Un portail qui n'est honnête que quand tout répond ne sert à rien —
 * c'est précisément quand une base tombe qu'un fondateur a besoin de savoir ce
 * qu'il regarde.
 */
function tenantInjoignable(Group $group, string $code, string $nom, array $extra = []): Tenant
{
    return Tenant::create(array_merge([
        'group_id' => $group->id,
        'code' => $code,
        'name' => $nom,
        'subdomain' => $code,
        'database_name' => "klassci_{$code}",
        'database_credentials' => [
            'host' => '127.0.0.1',
            'port' => 1,          // aucun serveur n'écoute ici
            'username' => 'nobody',
            'password' => 'nothing',
        ],
        'git_branch' => 'main',
        'status' => 'active',
        'plan' => 'elite',
    ], $extra));
}

beforeEach(function () {
    $this->group = Group::create([
        'name' => 'Groupe Test Mesure',
        'code' => 'grp-mesure',
        'status' => 'active',
    ]);
});

it('déclare chaque famille non mesurée quand aucune base ne répond', function () {
    tenantInjoignable($this->group, 'ecole-a', 'École A');
    tenantInjoignable($this->group, 'ecole-b', 'École B');

    $kpis = app(TenantAggregationService::class)->getGroupKpis($this->group->fresh());

    foreach (['effectifs', 'personnel', 'finances', 'assiduite'] as $famille) {
        expect($kpis['perimetre'][$famille]['etat'])
            ->toBe(EtatMesure::NON_MESURE, "la famille {$famille} devrait être non mesurée");
        expect($kpis['perimetre'][$famille]['repondu'])->toBe(0);
        expect($kpis['perimetre'][$famille]['total'])->toBe(2);
    }
});

it('ne présente jamais un compteur de la maîtresse comme un effectif mesuré', function () {
    // klassci_master tient `current_students` (620, 2140…), et il serait tentant
    // de l'afficher plutôt qu'un tiret. Mais cette colonne compte les étudiants
    // AYANT UN COMPTE plateforme, pas les inscriptions actives de l'année :
    // TenantConnectionManager avertit lui-même que les deux divergent fortement.
    // La substituer sous le libellé « Étudiants inscrits » remplacerait un zéro
    // visible par un chiffre faux et invisible.
    tenantInjoignable($this->group, 'ecole-a', 'École A', [
        'current_students' => 1236,
        'current_staff' => 58,
        'stats_measured_at' => now()->subMinutes(47),
    ]);

    $kpis = app(TenantAggregationService::class)->getGroupKpis($this->group->fresh());
    $etablissement = $kpis['establishments']['ecole-a'];

    expect($etablissement['students'])->toBe(0);
    expect($etablissement['etat_effectifs'])->toBe(EtatMesure::NON_MESURE);
    expect($kpis['perimetre']['effectifs']['etat'])->toBe(EtatMesure::NON_MESURE);
    expect($kpis['perimetre']['effectifs']['releves'])->toBe(0);

    // La date du dernier passage reste disponible pour l'infobulle — elle dit
    // depuis quand la maîtresse est sans nouvelles, elle ne date aucun chiffre.
    expect($etablissement['derniere_nouvelle_at'])->not->toBeNull();
});

it('garde l état RELEVE dans le vocabulaire sans qu aucun producteur ne l émette', function () {
    // L'état est juste, et le jour où un relevé mesurera la même grandeur que
    // le KPI il aura sa place. Aujourd'hui rien ne l'émet — et ce test le fige :
    // il échouera si quelqu'un rebranche un repli sans vérifier d'abord que les
    // deux mesures parlent bien de la même population.
    tenantInjoignable($this->group, 'ecole-a', 'École A', [
        'current_students' => 800,
        'stats_measured_at' => now()->subHour(),
    ]);

    $kpis = app(TenantAggregationService::class)->getGroupKpis($this->group->fresh());

    foreach ($kpis['perimetre'] as $famille => $p) {
        expect($p['releves'])->toBe(0, "aucun relevé ne devrait être émis pour {$famille}");
    }

    // Le vocabulaire, lui, sait toujours le dire.
    expect(EtatMesure::badge(EtatMesure::RELEVE))->toBe('Dernier relevé');
    expect(EtatMesure::aUneValeur(EtatMesure::RELEVE))->toBeTrue();
});

it('ne prête aucune assiduité à un établissement dont on n a que les effectifs', function () {
    // Le taux moyen était pondéré par les effectifs en excluant les seuls
    // `error`. Il lit désormais l'état de la famille assiduité : le jour où un
    // effectif proviendra d'un relevé, il n'entrera pas dans la pondération
    // avec un taux de 0 % qui tirerait la moyenne du groupe vers le bas.
    tenantInjoignable($this->group, 'ecole-a', 'École A', [
        'current_students' => 2000,
        'stats_measured_at' => now(),
    ]);

    $kpis = app(TenantAggregationService::class)->getGroupKpis($this->group->fresh());

    // Le taux est NUL, pas zéro : un `0` se serait affiché en rouge (barème
    // d'assiduité : sain à 85, à risque à 70), accusant d'un effondrement de
    // la présence une école dont on n'a justement pas relevé la présence.
    expect($kpis['avg_attendance_rate'])->toBeNull();
    expect($kpis['assiduite_mesurable'])->toBeFalse();
    expect($kpis['perimetre']['assiduite']['repondu'])->toBe(0);
});

it('garde un établissement injoignable dans les tranches d impayés au lieu de le faire disparaître', function () {
    // La boucle parcourait les réponses, pas les établissements : une école en
    // échec sortait du tableau sans laisser de trace, et les tranches tombaient
    // à zéro. La tuile « Impayés > 30 jours » passait alors au VERT et
    // annonçait zéro dossier à relancer — le seul endroit du portail où la
    // panne se déguisait en bonne nouvelle.
    tenantInjoignable($this->group, 'ecole-a', 'École A');

    $aging = app(TenantAggregationService::class)->getGroupOutstandingAging($this->group->fresh());

    expect($aging['perimetre']['etat'])->toBe(EtatMesure::NON_MESURE);
    expect($aging['perimetre']['repondu'])->toBe(0);
    expect($aging['perimetre']['manquants'])->toHaveKey('ecole-a');
    expect($aging['by_tenant'])->toHaveKey('ecole-a');
});

it('signale le périmètre des tendances sans taire le delta', function () {
    // Le delta compare `current` et `previous` issus du MÊME ensemble de
    // répondants : une école absente manque des deux fenêtres, le rapport
    // reste cohérent. C'est la valeur absolue qui est amputée, et c'est elle
    // qui porte la mention.
    tenantInjoignable($this->group, 'ecole-a', 'École A');

    $trends = app(TenantAggregationService::class)->getGroupTrends($this->group->fresh());

    expect($trends['perimetre']['etat'])->toBe(EtatMesure::NON_MESURE);
    expect($trends)->toHaveKey('revenue_mom');
    expect($trends['revenue_mom'])->toHaveKey('delta_pct');
});

it('horodate ses calculs pour que le bandeau dise leur âge réel', function () {
    // La puce annonçait « il y a moins de 15 min » alors que le cache des KPI
    // vit 300 secondes : le libellé était faux d'un facteur trois, et ne
    // dépendait même pas de l'heure du calcul.
    tenantInjoignable($this->group, 'ecole-a', 'École A');

    $kpis = app(TenantAggregationService::class)->getGroupKpis($this->group->fresh());

    expect($kpis)->toHaveKey('computed_at');
    expect($kpis['computed_at'])->not->toBeNull();
});

it('distingue une école injoignable d\'une école sans inscrit dans les filières', function () {
    // Trois situations tombaient sur le même `filieres: []`, donc sur le même
    // « Aucune donnée » à l'écran du benchmarking : base injoignable, base qui
    // répond sans année ouverte, et école réellement sans inscrit. Le fondateur
    // n'a pas la même action à mener dans les trois cas.
    tenantInjoignable($this->group, 'ecole-a', 'École A');

    $enrollment = app(TenantAggregationService::class)->getGroupEnrollment($this->group->fresh());

    expect($enrollment)->toHaveKey('ecole-a');
    expect($enrollment['ecole-a']['etat'])->toBe(EtatMesure::NON_MESURE);
    expect($enrollment['ecole-a']['motif'])->toBe(EtatMesure::MOTIF_INJOIGNABLE);
    expect($enrollment['ecole-a']['filieres'])->toBe([]);
});

it('ne laisse aucune famille du périmètre sans son état', function () {
    // Le hero des établissements lisait `perimetre.personnel`, qui n'était
    // jamais déclaré côté widget : la description du nombre d'établissements
    // testait l'état des EFFECTIFS pour décider d'afficher un effectif de
    // PERSONNEL. Une école mesurée sur ses étudiants et muette sur son
    // personnel aurait affiché un chiffre faux.
    tenantInjoignable($this->group, 'ecole-a', 'École A');

    $perimetre = app(TenantAggregationService::class)->getGroupKpis($this->group->fresh())['perimetre'];

    foreach (['effectifs', 'personnel', 'finances', 'assiduite'] as $famille) {
        expect($perimetre)->toHaveKey($famille);
        expect($perimetre[$famille])->toHaveKeys(['etat', 'repondu', 'total', 'mesures', 'releves', 'manquants', 'complet']);
    }
});

it('ne peint pas en rouge une assiduité que personne n\'a mesurée', function () {
    // `computeAttendanceRate()` renvoyait `0` pour TROIS situations que rien ne
    // distinguait : assiduité réellement nulle, aucune ligne d'appel sur la
    // période (l'école n'utilise pas le module), et requête en échec. L'appelant
    // posait pourtant `etat_assiduite = MESURE` sans condition — une école qui
    // ne fait pas l'appel affichait donc une tuile ROUGE « Taux de présence
    // 0 % ». C'est le défaut que ce chantier corrige, réintroduit en pire :
    // avant le refactor, la tuile était grise.
    tenantInjoignable($this->group, 'ecole-a', 'École A');

    $kpis = app(TenantAggregationService::class)->getGroupKpis($this->group->fresh());

    expect($kpis['establishments']['ecole-a']['etat_assiduite'])
        ->not->toBe(EtatMesure::MESURE);
    expect($kpis['perimetre']['assiduite']['etat'])->toBe(EtatMesure::NON_MESURE);
});

it('nomme la vraie raison d\'un périmètre absent, sans inventer une panne réseau', function () {
    // `libelleMotif(null)` retombe sur « la base n'a pas répondu ». Appelée sans
    // motif réel sous un graphique, elle AFFIRMAIT une panne : le fondateur
    // partait appeler son hébergeur alors que ses écoles avaient répondu, sans
    // avoir ouvert leur année universitaire.
    $manquants = [
        'a' => ['motif' => EtatMesure::MOTIF_SANS_ANNEE],
        'b' => ['motif' => EtatMesure::MOTIF_SANS_ANNEE],
    ];

    expect(EtatMesure::raisonCommune($manquants))
        ->toBe("aucune année universitaire n'est en cours");

    // Causes divergentes : on ne choisit pas pour le lecteur.
    $mixtes = [
        'a' => ['motif' => EtatMesure::MOTIF_SANS_ANNEE],
        'b' => ['motif' => EtatMesure::MOTIF_INJOIGNABLE],
    ];

    expect(EtatMesure::raisonCommune($mixtes))
        ->toBe("les établissements concernés n'ont pas tous la même raison");
});

it('ne reproche pas une panne de base à une école qui n\'utilise pas le module', function () {
    // Le motif porté par un établissement décrit la panne de SA base. Il ne dit
    // rien d'une famille NON_APPLICABLE, où la base a justement répondu.
    $perimetre = (new ReflectionClass(\App\Services\Group\GroupKpiProvider::class))
        ->getMethod('perimetre');
    $perimetre->setAccessible(true);

    $resultat = $perimetre->invoke(app(\App\Services\Group\GroupKpiProvider::class), [
        'ecole-a' => [
            'tenant_name' => 'École A',
            'motif' => null,
            'etat_effectifs' => EtatMesure::MESURE,
            'etat_personnel' => EtatMesure::MESURE,
            'etat_finances' => EtatMesure::MESURE,
            'etat_assiduite' => EtatMesure::NON_APPLICABLE,
        ],
    ]);

    expect($resultat['assiduite']['manquants']['ecole-a']['motif'])
        ->toBe(EtatMesure::MOTIF_SANS_MODULE);
    expect(EtatMesure::libelleMotif($resultat['assiduite']['manquants']['ecole-a']['motif']))
        ->toBe("l'établissement n'utilise pas ce module");
});

it('sur un groupe sans établissement, compte un zéro mais refuse d\'inventer un taux', function () {
    // « Zéro » est la bonne réponse pour un COMPTE : un groupe neuf a bien zéro
    // étudiant et zéro membre du personnel. Mais il n'existe pas de TAUX sans
    // dénominateur — et une première version de ce garde posait MESURE sur les
    // quatre familles, ce qui affichait « Recouvrement moyen 0,0 % — critique »
    // en ROUGE à un groupe dont toutes les écoles sont suspendues. Le zéro
    // fabriqué que corrige ce chantier, revenu par le bord.
    $kpis = app(TenantAggregationService::class)->getGroupKpis($this->group->fresh());

    foreach (['effectifs', 'personnel'] as $compte) {
        expect($kpis['perimetre'][$compte]['etat'])
            ->toBe(EtatMesure::MESURE, "un compte vaut zéro : {$compte}");
    }

    foreach (['finances', 'assiduite'] as $taux) {
        expect($kpis['perimetre'][$taux]['etat'])
            ->toBe(EtatMesure::NON_MESURE, "un taux sans dénominateur n'existe pas : {$taux}");
    }
});

it('ne peint pas en rouge le recouvrement d\'un groupe qui n\'a aucune école', function () {
    // Le bout de la chaîne : ce que le fondateur voit réellement.
    //
    // Ce test construisait son `context` À LA MAIN, contournant exactement la
    // méthode qui le produit en vrai (`ListEstablishments::buildHeroContext()`)
    // — une régression de cette méthode l'aurait laissé vert. On passe par
    // elle.
    $membre = \App\Models\GroupMember::create([
        'group_id' => $this->group->id,
        'email' => 'dg@test.local',
        'name' => 'Directeur',
        'role' => 'directeur_general',
        'password' => \Illuminate\Support\Facades\Hash::make('demo1234'),
        'is_active' => true,
    ]);
    $this->actingAs($membre, 'group');

    $page = new \App\Filament\Group\Resources\EstablishmentResource\Pages\ListEstablishments();
    $construire = new ReflectionMethod($page, 'buildHeroContext');
    $construire->setAccessible(true);

    $html = view('filament.group.partials.establishments-hero', [
        'context' => $construire->invoke($page),
    ])->render();

    expect($html)->not->toContain('data-tone="danger"');
    expect($html)->not->toContain('critique');

    // Et il dit l'absence plutôt que de la taire.
    expect($html)->toContain(\App\Support\EtatMesure::TIRET);
});
