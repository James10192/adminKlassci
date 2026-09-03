<?php

namespace App\Filament\Group\Pages;

use App\Filament\Group\Concerns\HasCustomHero;
use App\Filament\Group\Concerns\HasReportActions;
use App\Models\Group;
use App\Services\Group\ReportRegistry;
use App\Support\Filtres\FiltresRapport;
use App\Support\Period\PeriodFactory;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/**
 * Les états du portail, et le cadrage qui les rend produisibles.
 *
 * Les quatre premiers états tiennent en une ligne par établissement et se
 * sortent sans rien régler. Les trois derniers descendent sous l'établissement
 * — une ligne par paiement, une ligne par étudiant — et un groupe de quatre
 * écoles s'y compte en dizaines de milliers de lignes quand DomPDF en refuse
 * mille. Le cadrage n'est donc pas un réglage optionnel posé à côté : c'est lui
 * qui permet au document d'exister.
 *
 * D'où cet écran, qui met les filtres AVANT les boutons plutôt qu'à côté, et
 * les valeurs par défaut du côté serré : mois en cours, paiements validés. Un
 * défaut ouvert se ferait refuser au premier clic, et le directeur en
 * conclurait que la fonctionnalité est en panne.
 */
class Rapports extends Page implements HasForms
{
    use HasCustomHero;
    use HasReportActions;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationLabel = 'Rapports';

    protected static ?string $navigationGroup = 'Analytiques';

    protected static ?string $title = 'Rapports';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.group.pages.rapports';

    protected static ?string $slug = 'rapports';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'periode' => PeriodFactory::TYPE_CURRENT_MONTH,
            'etablissements' => [],
            'statut_paiement' => 'validé',
            'statut_inscription' => 'active',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Section::make('Cadrage')
                    ->description("Les états de détail descendent à la ligne. Sans cadrage, ils dépassent ce qu'un PDF peut porter — le tableur, lui, encaisse bien davantage.")
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 3])->schema([
                            Select::make('periode')
                                ->label('Période')
                                ->options([
                                    PeriodFactory::TYPE_CURRENT_MONTH => 'Mois en cours',
                                    PeriodFactory::TYPE_CURRENT_YEAR => 'Année en cours',
                                    PeriodFactory::TYPE_CUSTOM_RANGE => 'Plage libre',
                                ])
                                ->selectablePlaceholder(false)
                                ->live()
                                ->required(),

                            DatePicker::make('debut')
                                ->label('Du')
                                ->native(false)
                                ->visible(fn ($get): bool => $get('periode') === PeriodFactory::TYPE_CUSTOM_RANGE)
                                ->required(fn ($get): bool => $get('periode') === PeriodFactory::TYPE_CUSTOM_RANGE),

                            DatePicker::make('fin')
                                ->label('Au')
                                ->native(false)
                                ->visible(fn ($get): bool => $get('periode') === PeriodFactory::TYPE_CUSTOM_RANGE)
                                ->required(fn ($get): bool => $get('periode') === PeriodFactory::TYPE_CUSTOM_RANGE)
                                ->afterOrEqual('debut'),
                        ]),

                        Select::make('etablissements')
                            ->label('Établissements')
                            ->multiple()
                            ->options(fn (): array => $this->etablissements())
                            ->placeholder('Tout le groupe')
                            ->helperText('Laissez vide pour couvrir tout le groupe.'),

                        Grid::make(['default' => 1, 'md' => 3])->schema([
                            Select::make('statut_paiement')
                                ->label('Statut du paiement')
                                ->options(FiltresRapport::statutsPaiement())
                                ->placeholder('Tous les statuts')
                                ->helperText("Sans statut, le document ne totalise pas : additionner un paiement validé et un paiement rejeté donnerait une recette qui n'en est pas une."),

                            Select::make('statut_inscription')
                                ->label('Inscription')
                                ->options(FiltresRapport::statutsInscription())
                                ->placeholder('Tous les statuts'),

                            TextInput::make('mode_paiement')
                                ->label('Mode de paiement')
                                ->placeholder('Tous les modes')
                                ->helperText("Le libellé exact utilisé par l'école — Espèces, Wave, Orange Money…"),
                        ]),
                    ]),
            ]);
    }

    /** @return array<string, string> */
    public function etablissements(): array
    {
        $groupe = $this->groupe();

        return $groupe === null
            ? []
            : $groupe->activeTenants->sortBy('name')->pluck('name', 'code')->all();
    }

    /**
     * Les états proposés, du plus consolidé au plus détaillé.
     *
     * @return array<int, array{cle: string, titre: string, resume: string, detail: bool}>
     */
    public function catalogue(): array
    {
        return [
            ['cle' => ReportRegistry::ETAT_ETABLISSEMENTS, 'titre' => 'État des établissements',
                'resume' => "Effectifs, personnel, assiduité et recouvrement — une ligne par école.", 'detail' => false],
            ['cle' => ReportRegistry::CONSOLIDATION_FINANCIERE, 'titre' => 'Consolidation financière',
                'resume' => "Attendu, encaissé, reste à recouvrer — une ligne par école.", 'detail' => false],
            ['cle' => ReportRegistry::MASSE_SALARIALE, 'titre' => 'Masse salariale enseignante',
                'resume' => "Brut, net versé, retenues et engagé non versé — une ligne par école.", 'detail' => false],
            ['cle' => ReportRegistry::SANTE_ABONNEMENTS, 'titre' => 'Santé et abonnements',
                'resume' => "Offre, quotas, échéance d'abonnement et points de vigilance.", 'detail' => false],
            ['cle' => ReportRegistry::DETAIL_PAIEMENTS, 'titre' => 'Détail des paiements',
                'resume' => "Une ligne par encaissement : date, étudiant, montant, mode, référence.", 'detail' => true],
            ['cle' => ReportRegistry::SITUATION_ETUDIANTS, 'titre' => 'Situation par étudiant',
                'resume' => "Attendu, encaissé et reste, du plus gros impayé au plus petit.", 'detail' => true],
            ['cle' => ReportRegistry::EFFECTIFS_SCOLARITE, 'titre' => 'Effectifs et scolarité',
                'resume' => "Une ligne par inscrit : classe, filière, niveau, date d'inscription.", 'detail' => true],
        ];
    }

    public function telechargerPdf(string $cle)
    {
        return $this->exporter($cle, 'pdf');
    }

    public function telechargerExcel(string $cle)
    {
        return $this->exporter($cle, 'xlsx');
    }

    private function exporter(string $cle, string $format)
    {
        // Aucun `return null` silencieux ici. Une clé inconnue ou un membre sans
        // groupe rendaient un bouton MUET : le directeur cliquait, rien ne se
        // passait, et il attribuait à la lenteur du serveur ce qui était un
        // défaut. `construire()` lève sur une clé inconnue et `telecharger()`
        // transforme cette levée en notification qui dit ce qui a échoué.
        return $this->telecharger(function () use ($cle) {
            $groupe = $this->groupe();

            if ($groupe === null) {
                throw new \RuntimeException("Aucun groupe n'est rattaché à ce compte.");
            }

            return app(ReportRegistry::class)->construire($cle, $groupe, $this->filtres());
        }, $format);
    }

    /** Le cadrage tel que l'écran le décrit, converti en objet de filtres. */
    private function filtres(): FiltresRapport
    {
        $etat = $this->form->getState();

        $type = $etat['periode'] ?? PeriodFactory::TYPE_CURRENT_MONTH;

        // `makeCustomRange` lève si une borne manque. Le formulaire les rend
        // obligatoires, mais un état reconstruit — retour arrière du navigateur,
        // requête Livewire concurrente — peut arriver ici incomplet. On retombe
        // alors sur la période par défaut plutôt que de montrer une page
        // d'erreur au directeur pour une date vide.
        $periode = $type === PeriodFactory::TYPE_CUSTOM_RANGE
            && ! empty($etat['debut']) && ! empty($etat['fin'])
                ? PeriodFactory::make($type, ['start' => $etat['debut'], 'end' => $etat['fin']])
                : PeriodFactory::make(
                    $type === PeriodFactory::TYPE_CUSTOM_RANGE ? PeriodFactory::TYPE_CURRENT_MONTH : $type,
                );

        return new FiltresRapport(
            etablissements: array_values($etat['etablissements'] ?? []),
            periode: $periode,
            statutPaiement: $etat['statut_paiement'] ?: null,
            modePaiement: ($etat['mode_paiement'] ?? '') !== '' ? trim((string) $etat['mode_paiement']) : null,
            statutInscription: $etat['statut_inscription'] ?: null,
        );
    }

    private function groupe(): ?Group
    {
        $membre = auth('group')->user();

        return $membre?->group;
    }
}
