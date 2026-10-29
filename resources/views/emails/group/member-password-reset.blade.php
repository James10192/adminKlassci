<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="background:#f3f6fc;color:#17263a;font-family:Arial,sans-serif;margin:0;padding:28px 16px">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;margin:auto;background:#fff;border-radius:18px">
        <tr><td style="padding:36px">
            <p style="color:#0453cb;font-weight:bold;margin-top:0">KLASSCI Groupe · Sécurité</p>
            <h1 style="font-size:24px;line-height:1.3">Réinitialiser votre mot de passe</h1>
            <p>Bonjour {{ $member->name }},</p>
            <p>Une demande de réinitialisation a été effectuée pour votre accès au portail Groupe.</p>
            <p style="margin:30px 0"><a href="{{ $resetUrl }}" style="background:#0453cb;color:#fff;text-decoration:none;padding:14px 20px;border-radius:9px;font-weight:bold">Choisir un nouveau mot de passe</a></p>
            <p>Ce lien est à usage unique et expire dans {{ $ttlHours }} heures. Votre mot de passe actuel reste valide tant que vous n'avez pas terminé cette procédure.</p>
            <p>Si vous n'avez pas demandé cette action, ignorez ce message et signalez-le au support.</p>
            <p style="font-size:12px;color:#64748b;overflow-wrap:anywhere">Si le bouton ne s'ouvre pas, copiez le lien suivant dans votre navigateur : <a href="{{ $resetUrl }}">{{ $resetUrl }}</a></p>
        </td></tr>
    </table>
</body>
</html>