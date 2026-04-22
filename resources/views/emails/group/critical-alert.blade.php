@component('emails.group.layout', [
    'group' => $group,
    'unsubscribeUrl' => $unsubscribeUrl,
    'title' => 'Alerte critique KLASSCI',
    'headerTitle' => 'Alerte critique détectée',
])
    <p>Bonjour {{ $member->name }},</p>

    <p>Une alerte critique a été détectée sur votre groupe <strong>{{ $group->name }}</strong>.
    Une action immédiate peut être nécessaire pour éviter une interruption de service.</p>

    <div class="alert-card alert-critical">
        <p class="alert-title">{{ $alert->message }}</p>
        <p class="alert-tenant">
            Établissement : <strong>{{ $alert->tenantName !== '' ? $alert->tenantName : ($alert->tenantCode ?? '—') }}</strong>
        </p>
    </div>

    <p>
        <a href="{{ url('/groupe/alertes') }}" class="cta">Voir toutes les alertes</a>
    </p>

    <p style="color: #64748b; font-size: 0.82rem; margin-top: 20px;">
        Cette alerte a été envoyée parce qu'elle dépasse les seuils critiques configurés pour
        votre groupe. Vous pouvez ajuster vos préférences de notification depuis le portail.
    </p>
@endcomponent
