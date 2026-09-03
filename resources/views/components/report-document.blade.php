{{--
    Enveloppe commune à tous les documents PDF du portail.

    Porte l'identité du groupe connecté, le bandeau des filtres appliqués et
    le pied paginé. Un rapport n'écrit que son contenu ; tout le reste se
    décide ici, une fois, pour les douze documents.

    Les valeurs arrivent en propriétés et non par les variables de la vue
    parente : un composant Blade a une portée isolée, et compter sur des
    variables héritées donnait un document au titre vide. ReportRenderer les
    injecte dans la vue du rapport, qui les fait suivre :

        <x-report-document :title="$reportTitle" :subtitle="$reportSubtitle"
                           :filters="$reportFilters">
--}}
@props([
    'title' => 'Rapport',
    'subtitle' => null,
    'filters' => [],
])

@php
    $branding = app(\App\Services\Group\GroupBranding::class);
    $logo = $branding->logoDataUri();
    $primaire = $branding->primaryHex();
    $groupe = $branding->name();

    $titre = $title;
    $sousTitre = $subtitle;
    $filtres = $filters ?? [];
    $genereLe = now()->format('d/m/Y à H:i');
    $genesPar = auth('group')->user()?->name;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $titre }}</title>
    <style>
        /* La hauteur reservee en haut ne couvre QUE le bandeau, dont le
           contenu est constant. Le recapitulatif des filtres, lui, depend des
           donnees et peut passer a la ligne : il vivait dans l'en-tete fixe, y
           debordait de la hauteur declaree, et venait se superposer a la
           premiere ligne du tableau — deux textes l'un sur l'autre, illisibles.
           Il est desormais dans le flux. */
        @page { margin: 108px 34px 74px 34px; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9.5pt;
            color: #1e293b;
            margin: 0;
        }

        /* En-tête et pied répétés sur chaque page : DomPDF ne sait le faire
           qu'avec du position:fixed, pas avec des balises thead/tfoot. */
        header { position: fixed; top: -96px; left: 0; right: 0; height: 84px; }
        footer { position: fixed; bottom: -52px; left: 0; right: 0; height: 40px; }

        .bandeau {
            background: {{ $primaire }};
            color: #fff;
            padding: 12px 16px;
            border-radius: 6px;
        }
        .bandeau-table { width: 100%; border-collapse: collapse; }
        .bandeau-table td { vertical-align: middle; border: none; padding: 0; }
        .logo { height: 40px; }
        .groupe { font-size: 12pt; font-weight: bold; letter-spacing: .2px; }
        .titre { font-size: 15pt; font-weight: bold; margin-top: 2px; }
        .sous-titre { font-size: 9pt; opacity: .85; margin-top: 2px; }

        .filtres {
            margin-bottom: 10px;
            font-size: 8pt;
            color: #475569;
            border-left: 3px solid {{ $primaire }};
            padding: 4px 0 4px 8px;
        }
        .filtres b { color: #1e293b; }

        footer {
            font-size: 7.5pt;
            color: #64748b;
            border-top: 1px solid #e2e8f0;
            padding-top: 6px;
        }
        .pied-table { width: 100%; border-collapse: collapse; }
        .pied-table td { border: none; padding: 0; }
        .pied-droite { text-align: right; }

        table.donnees { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.donnees th {
            background: #f1f5f9;
            color: #0f172a;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: .3px;
            text-align: left;
            padding: 5px 6px;
            border-bottom: 1.5px solid {{ $primaire }};
        }
        table.donnees td {
            padding: 4px 6px;
            border-bottom: 1px solid #eef2f7;
        }
        table.donnees tr:nth-child(even) td { background: #fafbfc; }
        table.donnees tfoot td {
            font-weight: bold;
            background: #f1f5f9;
            border-top: 1.5px solid {{ $primaire }};
            border-bottom: none;
        }
        .num { text-align: right; }

        /* Mise en page fixe : les largeurs declarees par le rapport priment sur
           la repartition automatique. `word-wrap` evite qu'un libelle plus long
           que sa colonne deborde silencieusement sur la voisine. */
        table.donnees--fixe { table-layout: fixed; }
        table.donnees--fixe th,
        table.donnees--fixe td { word-wrap: break-word; vertical-align: top; }
    </style>
</head>
<body>
    <header>
        <div class="bandeau">
            <table class="bandeau-table">
                <tr>
                    @if($logo)
                        <td style="width:52px"><img src="{{ $logo }}" class="logo" alt=""></td>
                    @endif
                    <td>
                        <div class="groupe">{{ $groupe }}</div>
                        <div class="titre">{{ $titre }}</div>
                        @if($sousTitre)
                            <div class="sous-titre">{{ $sousTitre }}</div>
                        @endif
                    </td>
                </tr>
            </table>
        </div>

    </header>

    <footer>
        <table class="pied-table">
            <tr>
                <td>
                    Édité le {{ $genereLe }}@if($genesPar) par {{ $genesPar }}@endif
                </td>
                {{-- La pagination est dessinee par ReportRenderer apres le
                     rendu : DomPDF ne sait pas resoudre `counter(pages)` ici,
                     il y ecrivait « Page 1 / 0 ». --}}
            </tr>
        </table>
    </footer>

    <main>
        @if(! empty($filtres))
            <div class="filtres">
                @foreach($filtres as $libelle => $valeur)
                    <b>{{ $libelle }}</b> : {{ $valeur }}@if(! $loop->last) &nbsp;·&nbsp; @endif
                @endforeach
            </div>
        @endif

        {{ $slot }}
    </main>
</body>
</html>
