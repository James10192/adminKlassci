<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Invitation — KLASSCI Groupe</title>
    <style>
        body { margin: 0; padding: 0; background-color: #f2f2f2; font-family: Arial, sans-serif; color: #1e293b; }
        .container { max-width: 640px; margin: 24px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 8px 16px rgba(15, 23, 42, 0.08); }
        .header { background: #0453cb; padding: 28px 32px; color: #ffffff; }
        .header-brand { font-size: 0.82rem; letter-spacing: 0.12em; text-transform: uppercase; opacity: 0.85; margin-bottom: 6px; }
        .header-title { font-size: 1.3rem; font-weight: 700; margin: 0; }
        .content { padding: 28px 32px; }
        .cta { display: inline-block; margin: 14px 0; padding: 12px 24px; background: #0453cb; color: #ffffff !important; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 0.95rem; }
        .credentials { margin: 18px 0; padding: 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; }
        .credentials-row { display: block; margin-bottom: 6px; }
        .credentials-label { color: #64748b; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.08em; display: block; margin-bottom: 2px; }
        .credentials-value { font-family: 'Courier New', monospace; font-size: 1.05rem; color: #0f172a; font-weight: 600; }
        .hint { margin-top: 18px; color: #64748b; font-size: 0.82rem; line-height: 1.5; }
        .footer { background: #f8fafc; padding: 18px 32px; border-top: 3px solid #0453cb; color: #64748b; font-size: 0.78rem; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-brand">KLASSCI — Portail Groupe</div>
            <h1 class="header-title">Bienvenue dans votre espace de direction</h1>
        </div>
        <div class="content">
            <p>Bonjour {{ $member->name }},</p>

            <p>Un compte vient d'être créé pour vous sur le portail groupe
            <strong>{{ $member->group->name }}</strong>. Cliquez sur le bouton
            ci-dessous pour activer votre accès et définir votre mot de passe
            personnel.</p>

            <p>
                <a href="{{ $activationUrl }}" class="cta">Activer mon compte</a>
            </p>

            <div class="credentials">
                <span class="credentials-row">
                    <span class="credentials-label">Identifiant</span>
                    <span class="credentials-value">{{ $member->email }}</span>
                </span>
                <span class="credentials-row">
                    <span class="credentials-label">Mot de passe temporaire</span>
                    <span class="credentials-value">{{ $temporaryPassword }}</span>
                </span>
            </div>

            <p class="hint">
                Ce lien expire dans {{ $ttlHours }} heures. À la première connexion,
                vous devrez définir un mot de passe personnel. Le mot de passe
                temporaire ci-dessus ne peut servir qu'une seule fois.
            </p>

            <p class="hint">
                Si vous n'êtes pas à l'origine de cette invitation, ignorez
                simplement cet email — aucun compte ne sera activé sans votre
                action.
            </p>
        </div>
        <div class="footer">
            KLASSCI — Portail de direction des groupes scolaires.<br>
            Cet email contient des informations confidentielles.
        </div>
    </div>
</body>
</html>
