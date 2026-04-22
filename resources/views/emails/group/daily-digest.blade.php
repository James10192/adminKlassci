@component('emails.group.layout', [
    'group' => $group,
    'unsubscribeUrl' => $unsubscribeUrl,
    'title' => 'Récapitulatif quotidien KLASSCI',
    'headerTitle' => 'Récapitulatif du jour',
])
    <p>Bonjour {{ $member->name }},</p>

    <p>Voici le récapitulatif des alertes de niveau <strong>avertissement</strong> détectées
    aujourd'hui sur votre groupe <strong>{{ $group->name }}</strong>.</p>

    @foreach ($alerts as $alert)
        <div class="alert-card alert-warning">
            <p class="alert-title">{{ $alert['message'] }}</p>
            <p class="alert-tenant">
                Établissement : <strong>{{ $alert['tenant_name'] ?? $alert['tenant_code'] ?? '—' }}</strong>
            </p>
        </div>
    @endforeach

    <p>
        <a href="{{ url('/groupe/alertes') }}" class="cta">Ouvrir le portail groupe</a>
    </p>

    <p style="color: #64748b; font-size: 0.82rem; margin-top: 20px;">
        Les alertes critiques vous sont envoyées individuellement et immédiatement ; ce
        récapitulatif regroupe les avertissements moins urgents pour éviter de saturer votre
        boîte de réception.
    </p>
@endcomponent
