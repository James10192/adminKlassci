<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Définir votre mot de passe — KLASSCI Groupe</title>
    <link rel="icon" href="{{ asset('images/LOGO-KLASSCI-PNG.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap">
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f1f5f9; font-family: 'DM Sans', system-ui, sans-serif; color: #0f172a; padding: 20px; }
        .card { max-width: 480px; width: 100%; background: #ffffff; border-radius: 16px; box-shadow: 0 16px 40px rgba(15, 23, 42, 0.1); padding: 40px 36px; }
        .logo { text-align: center; margin-bottom: 18px; }
        .logo img { height: 44px; }
        h1 { font-size: 1.35rem; font-weight: 700; margin: 0 0 6px; text-align: center; }
        .subtitle { color: #64748b; font-size: 0.9rem; text-align: center; margin-bottom: 28px; }
        .field { margin-bottom: 18px; }
        label { display: block; font-size: 0.85rem; font-weight: 500; color: #334155; margin-bottom: 6px; }
        input[type="password"] { width: 100%; padding: 11px 14px; font-family: inherit; font-size: 0.95rem; border: 1px solid #e2e8f0; border-radius: 10px; background: #ffffff; transition: border-color 0.15s ease, box-shadow 0.15s ease; box-sizing: border-box; }
        input[type="password"]:focus { outline: none; border-color: #0453cb; box-shadow: 0 0 0 3px rgba(4, 83, 203, 0.1); }
        .rules { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; font-size: 0.8rem; color: #475569; margin-bottom: 20px; }
        .rules-title { font-weight: 600; color: #0f172a; margin-bottom: 4px; }
        .rules ul { margin: 6px 0 0; padding-left: 18px; }
        button { width: 100%; padding: 12px; background: #0453cb; color: #ffffff; border: none; border-radius: 10px; font-size: 0.95rem; font-weight: 600; cursor: pointer; transition: background 0.15s ease; font-family: inherit; }
        button:hover { background: #033a8e; }
        .error { display: block; margin-top: 6px; color: #dc2626; font-size: 0.82rem; }
        .session-status { background: #ecfdf5; border: 1px solid #a7f3d0; color: #047857; padding: 10px 14px; border-radius: 10px; font-size: 0.85rem; margin-bottom: 18px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">
            <img src="{{ asset('images/LOGO-KLASSCI-PNG.png') }}" alt="KLASSCI">
        </div>
        <h1>Définir votre mot de passe</h1>
        <p class="subtitle">Bienvenue {{ $member->name }}. Choisissez un mot de passe personnel pour finaliser votre accès.</p>

        @if(session('status'))
            <div class="session-status">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('groupe.set-password.store') }}">
            @csrf

            <div class="field">
                <label for="password">Nouveau mot de passe</label>
                <input type="password" name="password" id="password" required autofocus minlength="8">
                @error('password')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field">
                <label for="password_confirmation">Confirmer le mot de passe</label>
                <input type="password" name="password_confirmation" id="password_confirmation" required minlength="8">
            </div>

            <div class="rules">
                <div class="rules-title">Votre mot de passe doit contenir :</div>
                <ul>
                    <li>au moins 8 caractères</li>
                    <li>au moins une lettre</li>
                    <li>au moins un chiffre</li>
                </ul>
            </div>

            <button type="submit">Valider mon mot de passe</button>
        </form>
    </div>
</body>
</html>
