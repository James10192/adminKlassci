<?php

namespace App\Filament\Group\Pages;

use App\Filament\Group\Concerns\HasCustomHero;
use App\Services\TenantAggregationService;
use Filament\Pages\Page;

class Benchmarking extends Page
{
    use HasCustomHero;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Benchmarking';

    protected static ?string $navigationGroup = 'Analytiques';

    protected static ?string $title = 'Benchmarking';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.group.pages.benchmarking';

    /**
     * La periode reellement utilisee par les mesures affichees.
     *
     * Le scorecard intitulait une colonne « Presences (30j) ». Il n'existe
     * aucune fenetre de trente jours dans le code : `PeriodFactory::default()`
     * vaut l'annee civile en cours, et `computeAttendanceRate()` ne fait qu'un
     * `whereBetween` sur ses bornes. Deux commentaires affirmaient pourtant un
     * « repli 30 jours » qui n'a jamais existe. Une donnee juste sous une
     * etiquette fausse est une donnee fausse.
     */
    public function periodeMesure(): \App\Support\Period\PeriodInterface
    {
        return \App\Support\Period\PeriodFactory::default();
    }

    public function getComparisonData(): array
    {
        return $this->kpisGroupe()['establishments'] ?? [];
    }

    /**
     * Les totaux du groupe, tels que le tableau de bord les calcule.
     *
     * Cet ecran recalculait son propre taux de recouvrement DANS la vue, en
     * moyennant les taux de chaque ecole. Le tableau de bord, lui, divise
     * l'encaisse du groupe par son attendu. Les deux nombres divergent des que
     * les ecoles n'ont pas le meme poids — deux ecoles, l'une a 100 000 sur
     * 100 000 et l'autre a 100 000 sur 1 000 000 : 55 % en moyenne simple,
     * 18 % en realite. Le fondateur lisait 55 % sur l'ecran de COMPARAISON,
     * celui qu'il ouvre precisement pour arbitrer.
     *
     * Un seul producteur, une seule reponse : la vue lit ce total au lieu de
     * le refaire.
     *
     * @return array<string,mixed>
     */
    public function kpisGroupe(): array
    {
        return $this->kpisMemo ??= app(TenantAggregationService::class)
            ->getGroupKpis(auth('group')->user()->group);
    }

    /** @var array<string,mixed>|null */
    private ?array $kpisMemo = null;

    public function getEnrollmentData(): array
    {
        $group = auth('group')->user()->group;
        $service = app(TenantAggregationService::class);

        return $service->getGroupEnrollment($group);
    }
}
