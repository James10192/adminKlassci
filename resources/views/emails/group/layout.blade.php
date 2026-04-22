<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'KLASSCI — Alertes' }}</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #f2f2f2;
            font-family: Arial, sans-serif;
            color: #1e293b;
        }
        .container {
            max-width: 640px;
            margin: 24px auto;
            background: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 8px 16px rgba(15, 23, 42, 0.08);
        }
        .header {
            background: #0453cb;
            padding: 28px 32px;
            color: #ffffff;
        }
        .header-brand {
            font-size: 0.82rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            opacity: 0.85;
            margin-bottom: 6px;
        }
        .header-title {
            font-size: 1.3rem;
            font-weight: 700;
            margin: 0;
        }
        .content {
            padding: 28px 32px;
        }
        .content p {
            line-height: 1.55;
            margin: 0 0 14px;
        }
        .alert-card {
            background: #f8fafc;
            border-left: 4px solid #0453cb;
            border-radius: 6px;
            padding: 16px 18px;
            margin: 18px 0;
        }
        .alert-card.alert-critical {
            border-left-color: #dc2626;
            background: #fef2f2;
        }
        .alert-card.alert-warning {
            border-left-color: #d97706;
            background: #fffbeb;
        }
        .alert-title {
            font-weight: 700;
            margin: 0 0 4px;
            color: #0f172a;
        }
        .alert-tenant {
            font-size: 0.82rem;
            color: #64748b;
            margin: 4px 0 0;
        }
        .cta {
            display: inline-block;
            margin-top: 16px;
            padding: 12px 22px;
            background: #ffffff;
            color: #0453cb !important;
            border: 2px solid #0453cb;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 700;
        }
        .footer {
            background: #f8fafc;
            padding: 18px 32px;
            border-top: 3px solid #0453cb;
            font-size: 0.78rem;
            color: #64748b;
        }
        .footer a {
            color: #0453cb;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-brand">KLASSCI — Portail groupe</div>
            <h1 class="header-title">{{ $headerTitle ?? 'Alertes de votre groupe' }}</h1>
        </div>

        <div class="content">
            {{ $slot }}
        </div>

        <div class="footer">
            Vous recevez cet email parce que vous êtes membre du groupe <strong>{{ $group->name }}</strong>.<br>
            Pour gérer vos préférences ou vous désabonner de ce type d'alerte,
            <a href="{{ $unsubscribeUrl }}">cliquez ici</a>.
        </div>
    </div>
</body>
</html>
