<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KLASSCI — Préférences de notification</title>
    <style>
        body { margin: 0; padding: 40px 20px; background: #f2f2f2; font-family: Arial, sans-serif; color: #1e293b; }
        .card { max-width: 520px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 40px; box-shadow: 0 8px 16px rgba(15, 23, 42, 0.08); text-align: center; }
        .check { font-size: 3rem; color: #10b981; margin-bottom: 12px; }
        h1 { color: #0f172a; margin: 0 0 12px; font-size: 1.4rem; }
        p { color: #475569; line-height: 1.55; }
        a { color: #0453cb; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <div class="card">
        <div class="check">✓</div>
        <h1>Préférence mise à jour</h1>
        <p>Bonjour {{ $member->name }},</p>
        @if ($type === 'digest')
            <p>Vous ne recevrez plus le récapitulatif quotidien des avertissements.</p>
        @else
            <p>Vous ne recevrez plus d'emails pour ce type d'alerte.</p>
        @endif
        <p>Pour modifier à nouveau vos préférences, connectez-vous au <a href="{{ url('/groupe') }}">portail groupe</a>.</p>
    </div>
</body>
</html>
