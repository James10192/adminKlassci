<?php

namespace App\Filament\Group\Resources\EstablishmentResource\Pages;

use App\Filament\Group\Concerns\HasCustomHero;
use App\Filament\Group\Resources\EstablishmentResource;
use App\Services\TenantAggregationService;
use App\Support\EtatMesure;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;

class ListEstablishments extends ListRecords
{
    use HasCustomHero;

    protected static string $resource = EstablishmentResource::class;

    protected static ?string $title = 'Mes Établissements';

    public function getHeader(): ?View
    {
        return view('filament.group.partials.establishments-hero', [
            'context' => $this->buildHeroContext(),
        ]);
    }

    /**
     * Le contexte du hero, avec l'état de chaque famille d'indicateur.
     *
     * Cet écran affichait « 0 étudiant », « 0 personnel » et surtout
     * « Recouvrement moyen 0,0 % — critique » dans une tuile bordée de rouge,
     * alors qu'aucune base n'avait répondu. Le fondateur y lisait un groupe en
     * détresse financière là où il n'y avait qu'une panne de connexion : le
     * même défaut que la tuile verte des impayés, dans sa version alarmiste.
     *
     * @return array{
     *     total_students: int, total_staff: int, establishment_count: int,
     *     establishment_actifs: int, avg_rate: float,
     *     etat_effectifs: string, etat_personnel: string, etat_finances: string,
     *     mention_effectifs: ?string, mention_personnel: ?string, mention_finances: ?string
     * }
     */
    private function buildHeroContext(): array
    {
        $group = auth('group')->user()?->group;
        $kpis = $group ? app(TenantAggregationService::class)->getGroupKpis($group) : [];
        $perimetre = $kpis['perimetre'] ?? [];

        // Deux absences distinctes, longtemps confondues sous un seul `??`.
        //
        // Pas de groupe : on n'a RIEN interrogé, et les zéros qui suivent ne
        // sont pas des mesures — c'est le cas où le tiret s'impose.
        //
        // Groupe présent mais `perimetre` absent : c'est une charge de cache
        // écrite avant que le périmètre n'existe. Les chiffres, eux, sont bien
        // ceux d'une mesure ; les afficher en tirets jusqu'à expiration du
        // cache effacerait un tableau de bord juste. On retombe donc sur
        // MESURE, comme `EtatMesure::estMesure(null)` — et comme le fait
        // KpiOverviewWidget, qui répondait jusqu'ici l'inverse pour la même
        // question.
        $defaut = $group ? EtatMesure::MESURE : EtatMesure::NON_MESURE;

        $etat = static fn (string $famille): string => $perimetre[$famille]['etat'] ?? $defaut;
        $mention = static function (string $famille) use ($perimetre): ?string {
            $p = $perimetre[$famille] ?? null;

            return $p
                ? EtatMesure::mentionPerimetre((int) ($p['repondu'] ?? 0), (int) ($p['total'] ?? 0))
                : null;
        };

        return [
            'total_students' => (int) ($kpis['total_students'] ?? 0),
            'total_staff' => (int) ($kpis['total_staff'] ?? 0),
            // Le hero comptait les etablissements ACTIFS pendant que le
            // tableau juste en dessous en listait quatre — `getEloquentQuery()`
            // ne filtre que sur `group_id`. Un groupe dont une ecole est
            // suspendue lisait « 3 etablissements » au-dessus d'une liste de
            // quatre lignes. Le hero compte desormais ce que la page montre, et
            // dit combien sont actifs quand les deux different.
            'establishment_count' => $group ? $group->tenants()->count() : 0,
            'establishment_actifs' => (int) ($kpis['establishment_count'] ?? 0),
            'avg_rate' => (float) ($kpis['collection_rate'] ?? 0),
            'etat_effectifs' => $etat('effectifs'),
            'etat_personnel' => $etat('personnel'),
            'etat_finances' => $etat('finances'),
            'mention_effectifs' => $mention('effectifs'),
            'mention_personnel' => $mention('personnel'),
            'mention_finances' => $mention('finances'),
        ];
    }
}
