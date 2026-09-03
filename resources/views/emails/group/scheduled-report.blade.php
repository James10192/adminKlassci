@component('emails.group.layout', [
    'group' => $group,
    'unsubscribeUrl' => $unsubscribeUrl,
    'title' => $titreRapport . ' — KLASSCI',
    'headerTitle' => $titreRapport,
])
    <p>Bonjour {{ $member->name }},</p>

    <p>Voici l'état <strong>{{ $titreRapport }}</strong> du groupe
    <strong>{{ $group->name }}</strong>, en pièce jointe.</p>

    <p style="color:#64748b;font-size:0.9rem">
        Période : {{ $periode }}<br>
        Envoi {{ $cadence }}
    </p>

    <p style="color:#64748b;font-size:0.85rem">
        Ce document est consolidé sur les établissements actifs du groupe au
        moment de l'envoi. Les établissements dont la base n'a pas répondu sont
        signalés dans le document lui-même.
    </p>
@endcomponent
