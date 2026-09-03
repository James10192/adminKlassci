<?php

use App\Filament\Group\Pages\Rapports;
use App\Mail\Group\ScheduledReportMail;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupReportSchedule;
use App\Services\Group\ReportRegistry;
use App\Services\Group\ScheduleDueResolver;
use App\Support\Filtres\FiltresRapport;
use App\Support\Period\PeriodFactory;
use Illuminate\Support\Facades\Mail;

/**
 * La page qui expose les états, et le cadrage qui les rend produisibles.
 */
function directeur(Group $groupe): GroupMember
{
    return GroupMember::create([
        'group_id' => $groupe->id,
        'name' => 'Directeur',
        'email' => 'dg' . $groupe->id . '@test.local',
        'password' => bcrypt('secret'),
        'role' => 'directeur_general',
        'is_active' => true,
    ]);
}

it('affiche la page au directeur du groupe', function (): void {
    $groupe = Group::create(['name' => 'Groupe', 'code' => 'p1', 'status' => 'active']);
    $this->actingAs(directeur($groupe), 'group');

    Livewire::test(Rapports::class)->assertOk();
});

it('ne propose que des états que le registre sait construire', function (): void {
    $groupe = Group::create(['name' => 'Groupe', 'code' => 'p2', 'status' => 'active']);
    $this->actingAs(directeur($groupe), 'group');

    $registre = app(ReportRegistry::class);

    // Le catalogue de la page énumère ses clés à la main. Une clé qui dérive —
    // renommée d'un côté seulement — ne provoque AUCUNE erreur : le bouton
    // reste affiché et ne fait simplement rien. C'est le pire des défauts,
    // parce qu'il ressemble à une lenteur du serveur.
    foreach ((new Rapports())->catalogue() as $entree) {
        expect($registre->connait($entree['cle']))
            ->toBeTrue("La page propose « {$entree['titre']} » ({$entree['cle']}), que le registre ne connaît pas.");
    }
});

it('propose les sept états, dont les trois de détail', function (): void {
    $catalogue = (new Rapports())->catalogue();

    expect($catalogue)->toHaveCount(7)
        ->and(array_filter($catalogue, fn ($r) => $r['detail']))->toHaveCount(3);
});

it('fait enfin suivre la période choisie jusqu\'au document', function (): void {
    $groupe = Group::create(['name' => 'Groupe', 'code' => 'p3', 'status' => 'active']);

    // La période venait de `PeriodFactory::default()`, en dur dans le registre :
    // le sélecteur affiché en tête du portail ne parvenait jamais aux rapports.
    // On pouvait choisir « mois en cours » à l'écran et exporter l'année.
    $mois = new FiltresRapport(periode: PeriodFactory::make(PeriodFactory::TYPE_CURRENT_MONTH));
    $annee = new FiltresRapport(periode: PeriodFactory::make(PeriodFactory::TYPE_CURRENT_YEAR));

    $registre = app(ReportRegistry::class);

    $surMois = $registre->construire(ReportRegistry::CONSOLIDATION_FINANCIERE, $groupe, $mois)->filters()['Période'];
    $surAnnee = $registre->construire(ReportRegistry::CONSOLIDATION_FINANCIERE, $groupe, $annee)->filters()['Période'];

    expect($surMois)->not->toBe($surAnnee);
});

it('cadre les paiements sur le mois et les validés, par défaut', function (): void {
    $defaut = FiltresRapport::paiementsParDefaut();

    // Un défaut ouvert — tout, sur l'année — se ferait refuser par le garde-fou
    // de volume au premier clic, et le directeur en conclurait une panne.
    expect($defaut->statutPaiement)->toBe('validé')
        ->and($defaut->periode()->label())->toBe(PeriodFactory::make(PeriodFactory::TYPE_CURRENT_MONTH)->label());
});

it('ne nomme les établissements dans le bandeau que si le périmètre est restreint', function (): void {
    $groupe = Group::create(['name' => 'Groupe', 'code' => 'p4', 'status' => 'active']);

    $tout = (new FiltresRapport())->libelles([]);
    $restreint = (new FiltresRapport(etablissements: ['a']))->libelles(['École A']);

    expect($tout)->not->toHaveKey('Établissements')
        ->and($restreint['Établissements'])->toBe('École A');
});

it('annonce dans l\'e-mail la période du document joint, pas une autre', function (): void {
    $groupe = Group::create(['name' => 'Groupe', 'code' => 'p5', 'status' => 'active']);
    $membre = directeur($groupe);

    // Le corps du message lisait `PeriodFactory::default()`, soit l'année. Tant
    // que tous les états couvraient l'année, les deux coïncidaient par hasard.
    // Depuis que les états de détail se cadrent sur le MOIS, le message
    // annonçait « Année 2026 » au-dessus d'une pièce jointe portant septembre.
    //
    // On vérifie ce que la commande ENVOIE, pas ce que son source contient :
    // chercher une chaîne dans un fichier passe au vert sur une régression qui
    // survit en commentaire, et au rouge sur un simple renommage.
    GroupReportSchedule::create([
        'group_id' => $groupe->id,
        'report_key' => ReportRegistry::DETAIL_PAIEMENTS,
        'frequency' => ScheduleDueResolver::HEBDOMADAIRE,
        'day_of_week' => (int) now()->dayOfWeek,
        'hour' => (int) now()->hour,
        'recipient_member_ids' => [$membre->id],
        'is_active' => true,
    ]);

    Mail::fake();

    $this->artisan('group:send-scheduled-reports')->assertSuccessful();

    $moisEnCours = PeriodFactory::make(PeriodFactory::TYPE_CURRENT_MONTH)->label();
    $annee = PeriodFactory::make(PeriodFactory::TYPE_CURRENT_YEAR)->label();

    Mail::assertQueued(
        ScheduledReportMail::class,
        fn (ScheduledReportMail $mail): bool => $mail->periode === $moisEnCours && $mail->periode !== $annee,
    );
});
