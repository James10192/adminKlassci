<?php

use App\Filament\Group\Resources\ReportScheduleResource;
use App\Filament\Group\Resources\ReportScheduleResource\Pages\ListReportSchedules;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupReportSchedule;
use App\Services\Group\ScheduleDueResolver;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Illuminate\Support\Facades\Hash;

/**
 * Le hero des rapports programmés.
 *
 * Cet écran était le seul du portail à ouvrir sur le titre Filament brut
 * au-dessus d'un tableau : sept écrans sur huit portaient le bandeau du
 * groupe, le huitième ressemblait à une page inachevée.
 *
 * Ses trois cartouches ne mesurent aucune école — tout vient de
 * `klassci_master`, qui répond toujours. Elles ne peuvent donc pas être « non
 * mesurées », mais elles peuvent être vides, et le vide se dit en gris et par
 * un tiret, jamais par un « 0 » qu'on lit comme un échec.
 */
function programmation(int $groupId, array $attributs = []): GroupReportSchedule
{
    return GroupReportSchedule::create(array_merge([
        'group_id' => $groupId,
        'report_key' => 'consolidation_financiere',
        'frequency' => ScheduleDueResolver::HEBDOMADAIRE,
        'day_of_week' => 1,
        'hour' => 7,
        'recipient_member_ids' => [1, 2],
        'is_active' => true,
    ], $attributs));
}

/** Le contexte réellement produit par la page, pas un tableau écrit à la main. */
function contexteRapports(): array
{
    $methode = new ReflectionMethod(ListReportSchedules::class, 'buildHeroContext');
    $methode->setAccessible(true);

    return $methode->invoke(new ListReportSchedules());
}

beforeEach(function () {
    $this->groupe = Group::create(['name' => 'Groupe ROSTAN', 'code' => 'rostan', 'status' => 'active']);
    $this->autre = Group::create(['name' => 'Groupe ESBTP', 'code' => 'esbtp', 'status' => 'active']);

    $this->membre = GroupMember::create([
        'group_id' => $this->groupe->id,
        'email' => 'dg@rostan.test',
        'name' => 'Marcel',
        'role' => 'directeur_general',
        'password' => Hash::make('demo1234'),
        'is_active' => true,
    ]);

    $this->actingAs($this->membre, 'group');

    // Hors requete HTTP, aucun panneau n'est « courant » : `Resource::getUrl()`
    // retomberait sur le panneau par defaut et chercherait une route
    // `filament.admin.…` qui n'existe pas.
    Filament::setCurrentPanel(Filament::getPanel('group'));
});

it('compte les envois, dédoublonne les destinataires et ignore les suspendus', function () {
    programmation($this->groupe->id, ['recipient_member_ids' => [1, 2]]);
    programmation($this->groupe->id, ['recipient_member_ids' => [2, 3]]);

    // Un envoi suspendu n'écrit à personne : son destinataire exclusif (9) ne
    // doit pas gonfler le compte, sinon le hero promet des e-mails qui ne
    // partiront pas.
    programmation($this->groupe->id, ['is_active' => false, 'recipient_member_ids' => [9]]);

    $contexte = contexteRapports();

    expect($contexte['total'])->toBe(3);
    expect($contexte['actifs'])->toBe(2);
    // 1, 2, 3 — le membre 2 est nommé deux fois mais reste une personne.
    expect($contexte['destinataires'])->toBe(3);
});

it('ne compte jamais les programmations d\'un autre groupe', function () {
    programmation($this->groupe->id);
    programmation($this->autre->id, ['recipient_member_ids' => [50, 51, 52]]);

    $contexte = contexteRapports();

    expect($contexte['total'])->toBe(1);
    expect($contexte['destinataires'])->toBe(2);
});

it('dit qu\'aucun envoi n\'est jamais parti plutôt que d\'inventer une date', function () {
    programmation($this->groupe->id, ['last_sent_at' => null]);

    expect(contexteRapports()['dernier_envoi'])->toBeNull();
});

it('rapporte la date du dernier envoi effectué', function () {
    programmation($this->groupe->id, ['last_sent_at' => now()->subDays(3)]);
    programmation($this->groupe->id, ['last_sent_at' => now()->subDays(10)]);

    expect(contexteRapports()['dernier_envoi'])->toContain('jours');
});

it('affiche des tirets gris, jamais des zéros, quand rien n\'est programmé', function () {
    $html = view('filament.group.partials.rapports-hero', [
        'context' => contexteRapports(),
    ])->render();

    expect($html)->toContain('Rapports programmés');
    expect($html)->toContain(\App\Support\EtatMesure::TIRET);
    expect($html)->toContain('data-tone="inconnu"');
    expect($html)->toContain('aucun envoi actif');
    expect($html)->toContain('0 envoi configuré');
});

it('accorde les pluriels en français, sans passer par l\'inflecteur anglais', function () {
    programmation($this->groupe->id, ['recipient_member_ids' => [1, 2], 'last_sent_at' => now()->subDay()]);
    programmation($this->groupe->id, ['recipient_member_ids' => [3]]);

    $html = view('filament.group.partials.rapports-hero', [
        'context' => contexteRapports(),
    ])->render();

    expect($html)->toContain('2 envois configurés');
    expect($html)->toContain('membres du groupe');

    // Les trois cartouches sont renseignées : aucune ne doit être grisée, et
    // aucun tiret ne doit rester.
    expect($html)->not->toContain('data-tone="inconnu"');
    expect($html)->not->toContain(\App\Support\EtatMesure::TIRET);
});

it('garde le bouton de creation que le hero a evince', function () {
    // `getHeader()` remplace tout l'en-tete : sans reprise explicite, la page
    // n'offre plus aucun moyen de programmer un envoi. On monte la page comme
    // Livewire le fait, sinon ses actions ne sont pas encore construites.
    // On monte la page comme Livewire le fait : hors montage, ses actions ne
    // sont pas encore construites et le hero les recevrait vides.
    $html = Livewire::test(ListReportSchedules::class)->html();

    expect($html)->toContain('gp-hero');
    expect($html)->toContain('Programmer un envoi');
    expect($html)->toContain(ReportScheduleResource::getUrl('create'));
});

it('la page porte le hero et tait le titre Filament en double', function () {
    $page = new ListReportSchedules();

    // `getHeader()` remplace tout l'en-tête ; sans ce silence, Filament rend
    // son propre <h1> juste au-dessus du bandeau.
    expect($page->getHeading())->toBe('');
    expect($page->getSubheading())->toBeNull();
    expect($page->getHeader())->not->toBeNull();
});

it('l\'état vide porte une horloge, pas une croix', function () {
    // Le vide est ici l'état de départ, sous un texte qui invite à créer.
    // La croix par défaut de Filament s'y lit comme un échec.
    $table = ReportScheduleResource::table(
        \Filament\Tables\Table::make(new ListReportSchedules())
    );

    expect($table->getEmptyStateIcon())->toBe('heroicon-o-clock');
});
