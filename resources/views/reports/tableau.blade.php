@php
    use App\Domain\Exports\TableauReport;
    use App\Support\FcfaFormatter;

    $formate = function ($valeur, ?string $format) {
        if ($valeur === null || $valeur === '') {
            return '—';
        }

        return match ($format) {
            TableauReport::FCFA => FcfaFormatter::full((float) $valeur),
            TableauReport::NOMBRE => number_format((float) $valeur, 0, ',', ' '),
            TableauReport::POURCENT => number_format((float) $valeur, 1, ',', ' ') . ' %',
            default => (string) $valeur,
        };
    };

    // Un rapport peut declarer la largeur de ses colonnes, en pourcentage.
    // Sans ca, DomPDF repartit au juge : sur un tableau a huit colonnes, il
    // donnait 97 points a « Echeance » — assez pour couper « Dans 547 jours »
    // en deux lignes — pendant qu'une colonne de dates en gardait le double.
    $largeurs = array_filter(array_map(fn (array $c) => $c['largeur'] ?? null, $colonnes));
    $largeursDeclarees = $largeurs !== [];

    $estNumerique = fn (?string $format) => in_array(
        $format,
        [TableauReport::FCFA, TableauReport::NOMBRE, TableauReport::POURCENT],
        true,
    );
@endphp

<x-report-document :title="$reportTitle" :subtitle="$reportSubtitle" :filters="$reportFilters">
    @if (empty($lignes))
        {{-- Un tableau vide et un tableau non consolidé se ressemblent sur le
             papier. On dit lequel des deux c'est. --}}
        <p style="padding:14px;border:1px solid #e2e8f0;border-radius:4px;color:#475569">
            Aucune donnée sur la période retenue.
        </p>
    @else
        <table class="donnees {{ $largeursDeclarees ? 'donnees--fixe' : '' }}">
            @if ($largeursDeclarees)
                <colgroup>
                    @foreach ($colonnes as $colonne)
                        <col @if(isset($colonne['largeur'])) style="width: {{ $colonne['largeur'] }}%" @endif>
                    @endforeach
                </colgroup>
            @endif
            <thead>
                <tr>
                    @foreach ($colonnes as $colonne)
                        <th class="{{ $estNumerique($colonne['format'] ?? null) ? 'num' : '' }}">{{ $colonne['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($lignes as $ligne)
                    <tr>
                        @foreach ($colonnes as $index => $colonne)
                            <td class="{{ $estNumerique($colonne['format'] ?? null) ? 'num' : '' }}">
                                {{ $formate($ligne[$index] ?? null, $colonne['format'] ?? null) }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
            @if ($totaux)
                <tfoot>
                    <tr class="totaux">
                        @foreach ($colonnes as $index => $colonne)
                            <td class="{{ $estNumerique($colonne['format'] ?? null) ? 'num' : '' }}">
                                {{ $formate($totaux[$index] ?? null, $colonne['format'] ?? null) }}
                            </td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
    @endif
</x-report-document>
