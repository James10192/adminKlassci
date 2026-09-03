<?php

namespace App\Filament\Group\Widgets;

use App\Services\TenantAggregationService;
use App\Support\EtatMesure;
use Filament\Widgets\ChartWidget;

class EnrollmentWidget extends ChartWidget
{
    // Keep lazy: cross-DB queries can be slow

    protected static ?string $heading = 'Effectifs par établissement';

    protected static ?int $sort = 5;

    protected static ?string $pollingInterval = '300s';

    protected static ?string $maxHeight = '350px';

    // Un ChartWidget sans donnees rend un canevas vide : une carte blanche au
    // bas du tableau de bord. Cette vue dit la raison a la place du graphe.
    protected static string $view = 'filament.group.widgets.chart-ou-vide';

    /**
     * Rampe monochrome bleu, du plus soutenu au plus clair.
     *
     * L'ancienne palette mêlait vert, ambre, VIOLET et rose : le design system
     * KLASSCI interdit le multicolore décoratif, et le violet nommément. Une
     * couleur par école ne porte aucune information — elle ne fait que
     * distinguer — donc une seule teinte déclinée suffit et reste lisible.
     *
     * @var list<string>
     */
    private const TEINTES = [
        'rgba(4, 83, 203, 0.85)',
        'rgba(4, 83, 203, 0.68)',
        'rgba(59, 125, 219, 0.72)',
        'rgba(59, 125, 219, 0.55)',
        'rgba(94, 145, 222, 0.62)',
        'rgba(94, 145, 222, 0.45)',
    ];

    /** @var array<string,mixed>|null mémo par instance : getData() et getDescription() lisaient deux fois */
    private ?array $kpisMemo = null;

    /** @return array<string,mixed> */
    private function kpis(): array
    {
        return $this->kpisMemo ??= app(TenantAggregationService::class)
            ->getGroupKpis(auth('group')->user()->group);
    }

    protected function getData(): array
    {
        $kpis = $this->kpis();

        $labels = [];
        $students = [];

        // Une école injoignable renvoyait `inscriptions = 0`, et le camembert
        // traçait une part de zéro : le graphique dessinait quatre écoles
        // vides là où il n'y avait qu'une panne. Un graphe ne sait pas dire
        // « je ne sais pas » — alors on ne lui donne que ce qui est mesuré.
        foreach ($kpis['establishments'] ?? [] as $data) {
            if (! EtatMesure::aUneValeur($data['etat_effectifs'] ?? null)) {
                continue;
            }

            $labels[] = $data['tenant_name'];
            // Des personnes, pas des lignes d'inscription : le camembert doit
            // totaliser le meme nombre que le KPI « Etudiants inscrits ».
            $students[] = $data['students'] ?? 0;
        }

        $teintes = [];
        for ($i = 0, $n = count($labels); $i < $n; $i++) {
            $teintes[] = self::TEINTES[$i % count(self::TEINTES)];
        }

        return [
            'datasets' => [
                [
                    'label' => 'Étudiants inscrits',
                    'data' => $students,
                    'backgroundColor' => $teintes,
                ],
            ],
            'labels' => $labels,
        ];
    }

    /**
     * Le sous-titre dit ce que le graphique ne montre pas.
     *
     * Sans lui, un camembert amputé de deux écoles se lit comme un camembert
     * complet.
     */
    public function getDescription(): ?string
    {
        $perimetre = $this->kpis()['perimetre']['effectifs'] ?? null;

        if ($perimetre === null) {
            return null;
        }

        // Un groupe sans etablissement n'a rien a comparer et rien a expliquer :
        // le meme garde que RevenueComparisonWidget.
        if ((int) ($perimetre['total'] ?? 0) === 0) {
            return null;
        }

        if ((int) $perimetre['repondu'] === 0) {
            // La raison vient des établissements réellement absents : sans ça,
            // le sous-titre affirmait une panne de base même quand les écoles
            // avaient répondu sans année universitaire ouverte.
            $raison = EtatMesure::raisonCommune($perimetre['manquants'] ?? []);

            return ucfirst(EtatMesure::absenceGroupe()) . ($raison ? ' — ' . $raison : '');
        }

        return EtatMesure::mentionPerimetre((int) $perimetre['repondu'], (int) $perimetre['total']);
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
