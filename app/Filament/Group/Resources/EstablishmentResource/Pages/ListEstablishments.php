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
     *     total_students: int, total_staff: int, establishment_count: int, avg_rate: float,
     *     etat_effectifs: string, etat_personnel: string, etat_finances: string,
     *     mention_effectifs: ?string, mention_personnel: ?string, mention_finances: ?string
     * }
     */
    private function buildHeroContext(): array
    {
        $group = auth('group')->user()?->group;
        $kpis = $group ? app(TenantAggregationService::class)->getGroupKpis($group) : [];
        $perimetre = $kpis['perimetre'] ?? [];

        $etat = static fn (string $famille): string => $perimetre[$famille]['etat'] ?? EtatMesure::NON_MESURE;
        $mention = static function (string $famille) use ($perimetre): ?string {
            $p = $perimetre[$famille] ?? null;

            return $p ? EtatMesure::mentionPerimetre((int) $p['repondu'], (int) $p['total']) : null;
        };

        return [
            'total_students' => (int) ($kpis['total_students'] ?? 0),
            'total_staff' => (int) ($kpis['total_staff'] ?? 0),
            'establishment_count' => (int) ($kpis['establishment_count'] ?? 0),
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
