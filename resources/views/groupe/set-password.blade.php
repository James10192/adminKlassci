<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <title>Sécuriser mon accès — KLASSCI Groupe</title>
    <link rel="icon" href="{{ asset('images/LOGO-KLASSCI-PNG.png') }}">
    <style>
        :root { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; color-scheme: light; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; background: radial-gradient(ellipse at 15% 0%, #e4eeff 0, transparent 55%), #f5f7fc; color: #14243a; display: grid; place-items: center; padding: clamp(16px, 4vw, 40px); }
        .shell { width: min(100%, 940px); display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr); background: #fff; border: 1px solid #e3eaf3; border-radius: 24px; box-shadow: 0 24px 75px rgba(19, 43, 82, .10); overflow: hidden; }
        .intro { background: linear-gradient(155deg, #043c9c, #072254); color: white; padding: clamp(26px, 5vw, 50px); display: flex; flex-direction: column; justify-content: space-between; gap: 28px; }
        .brand { display: inline-flex; align-items: center; gap: 12px; font-weight: 800; font-size: 1.08rem; letter-spacing: .01em; }
        .brand img { width: 42px; height: 42px; object-fit: contain; background: white; border-radius: 10px; padding: 5px; }
        .eyebrow { font-size: .76rem; color: #c2d5ff; letter-spacing: .1em; text-transform: uppercase; font-weight: 700; margin: 0 0 12px; }
        .intro h1 { font-size: clamp(1.65rem, 3vw, 2.4rem); margin: 0 0 14px; line-height: 1.17; letter-spacing: -.03em; }
        .intro p { line-height: 1.65; margin: 0; color: #d3e2ff; font-size: .96rem; }
        .footnote { font-size: .78rem; color: #acc5f6; }
        .content { padding: clamp(24px, 5vw, 50px); }
        .content h2 { margin: 0 0 8px; font-size: 1.45rem; letter-spacing: -.02em; }
        .sub { margin: 0 0 28px; font-size: .94rem; line-height: 1.6; color: #53657d; }
        .field { margin-bottom: 22px; }
        label { display: block; font-size: .88rem; font-weight: 650; margin-bottom: 9px; }
        .password-wrap { position: relative; }
        .password-wrap input { width: 100%; min-height: 49px; padding: 12px 56px 12px 14px; border: 1px solid #cbd5e1; border-radius: 12px; background: #fff; color: #0f172a; font: inherit; font-size: 1rem; }
        .password-wrap input:focus { outline: 0; border-color: #0453cb; box-shadow: 0 0 0 3px rgba(4, 83, 203, .13); }
        .toggle { position: absolute; right: 5px; top: 5px; display: grid; place-items: center; width: 40px; height: 39px; border: 0; background: transparent; color: #52647b; border-radius: 9px; cursor: pointer; }
        .toggle:hover { background: #eaf1fe; color: #0343a2; }
        .toggle:focus-visible { outline: 2px solid #0453cb; outline-offset: 2px; }
        .toggle svg { width: 21px; height: 21px; fill: none; stroke: currentColor; stroke-width: 1.7; }
        .toggle .eye-off { display: none; }
        .toggle[aria-pressed="true"] .eye-on { display: none; }
        .toggle[aria-pressed="true"] .eye-off { display: block; }
        .hint { margin: 9px 0 0; font-size: .8rem; line-height: 1.55; color: #66788f; }
        .requirements { margin: 0 0 24px; padding: 14px 18px; border-radius: 12px; background: #f5f8fd; border: 1px solid #e5edf8; color: #445774; font-size: .83rem; }
        .requirements strong { display: block; color: #27374d; margin-bottom: 7px; }
        .requirements ul { margin: 0; padding-left: 18px; line-height: 1.75; }
        .submit { width: 100%; min-height: 50px; padding: 12px 18px; background: #0453cb; color: white; font: inherit; font-weight: 700; border: 0; border-radius: 12px; cursor: pointer; box-shadow: 0 6px 15px rgba(4,83,203,.18); }
        .submit:hover { background: #0343a2; }
        .submit:focus-visible { outline: 3px solid #7dafff; outline-offset: 2px; }
        .error { display: block; margin-top: 7px; color: #b42318; font-size: .85rem; }
        .invalid { border-color: #d92d20 !important; }
        .status { padding: 12px 14px; background: #effdf4; color: #067647; border: 1px solid #b9ebc7; border-radius: 10px; margin-bottom: 18px; font-size: .87rem; }
        .privacy { font-size: .79rem; color: #66788f; text-align: center; margin: 18px 0 0; }
        @media (max-width: 730px) { .shell { max-width: 540px; grid-template-columns: 1fr; border-radius: 18px; } .intro { gap: 12px; padding: 24px; } .intro h1 { font-size: 1.5rem; } .intro .footnote { display: none; } .content { padding: 28px 24px; } }
        @media (prefers-color-scheme: dark) {
            body { background: #0b1220; color: #e8effa; }
            .shell { background: #141f31; border-color: #2a3a53; }
            .content { color: #e8effa; }
            .sub, .hint, .privacy { color: #a9b9d1; }
            .password-wrap input { background: #0c1728; border-color: #3a4c66; color: white; }
            .toggle { color: #b9c8dc; }
            .toggle:hover { background: #253954; color: #fff; }
            .requirements { background: #1b2a40; border-color: #2d405c; color: #b4c5da; }
            .requirements strong { color: #eaf1ff; }
            .status { background: #143426; border-color: #255b40; color: #b5f9d3; }
            .error { color: #ffb3ab; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="intro" aria-label="Bienvenue sur KLASSCI Groupe">
            <div class="brand">
                <img src="{{ asset('images/LOGO-KLASSCI-PNG.png') }}" alt="">
                KLASSCI Groupe
            </div>
            <div>
                <p class="eyebrow">Sécurité de votre compte</p>
                <h1>Votre espace de direction, votre mot de passe.</h1>
                <p>Définissez un mot de passe personnel pour protéger l'accès aux établissements de votre groupe.</p>
            </div>
            <p class="footnote">Accès protégé · Les mots de passe ne sont jamais visibles par les administrateurs.</p>
        </section>

        <section class="content" aria-labelledby="form-heading">
            <h2 id="form-heading">Finaliser mon accès</h2>
            <p class="sub">Bienvenue, {{ $member->name }}. Choisissez votre nouveau mot de passe puis confirmez-le.</p>

            @if(session('status'))
                <div class="status" role="status">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('groupe.set-password.store') }}" autocomplete="off">
                @csrf

                <div class="field">
                    <label for="password">Nouveau mot de passe</label>
                    <div class="password-wrap">
                        <input type="password" id="password" name="password"
                            required autofocus autocomplete="new-password" minlength="{{ $minPasswordLength }}"
                            aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}"
                            @if($errors->has('password')) aria-describedby="password-error" class="invalid" @endif>
                        <button class="toggle" type="button" aria-label="Afficher le nouveau mot de passe"
                            aria-controls="password" aria-pressed="false" data-password-toggle="password">
                            <svg viewBox="0 0 24 24" aria-hidden="true" class="eye-on"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true" class="eye-off"><path d="M3 3l18 18M10.6 6.1c.5-.1.9-.1 1.4-.1 6.5 0 10 6 10 6a17 17 0 0 1-3.1 3.5M6 6.9C3.5 8.4 2 12 2 12s3.5 6 10 6c1.3 0 2.5-.2 3.6-.6M10 10a3 3 0 0 0 4 4"/></svg>
                        </button>
                    </div>
                    @error('password')<span id="password-error" class="error" role="alert">{{ $message }}</span>@enderror
                </div>

                <div class="field">
                    <label for="password_confirmation">Confirmer le mot de passe</label>
                    <div class="password-wrap">
                        <input type="password" id="password_confirmation" name="password_confirmation"
                            required autocomplete="new-password" minlength="{{ $minPasswordLength }}"
                            aria-invalid="{{ $errors->has('password_confirmation') ? 'true' : 'false' }}"
                            @if($errors->has('password_confirmation')) aria-describedby="confirmation-error" class="invalid" @endif>
                        <button class="toggle" type="button" aria-label="Afficher la confirmation"
                            aria-controls="password_confirmation" aria-pressed="false" data-password-toggle="password_confirmation">
                            <svg viewBox="0 0 24 24" aria-hidden="true" class="eye-on"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true" class="eye-off"><path d="M3 3l18 18M10.6 6.1c.5-.1.9-.1 1.4-.1 6.5 0 10 6 10 6a17 17 0 0 1-3.1 3.5M6 6.9C3.5 8.4 2 12 2 12s3.5 6-10 6"/></svg>
                        </button>
                    </div>
                    @error('password_confirmation')<span id="confirmation-error" class="error" role="alert">{{ $message }}</span>@enderror
                </div>

                <div class="requirements">
                    <strong>Pour un accès sécurisé</strong>
                    <ul>
                        <li>Au moins {{ $minPasswordLength }} caractères</li>
                        <li>Au moins une lettre et un chiffre</li>
                        <li>Un mot de passe personnel, différent de celui reçu par invitation</li>
                    </ul>
                </div>

                <button class="submit" type="submit">Enregistrer et accéder au portail</button>
                <p class="privacy">Votre mot de passe est enregistré sous forme chiffrée irréversible (hachage).</p>
            </form>
        </section>
    </main>
    <script>
        document.querySelectorAll('[data-password-toggle]').forEach((button) => {
            button.addEventListener('click', () => {
                const input = document.getElementById(button.dataset.passwordToggle);
                if (!input) return;
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                button.setAttribute('aria-pressed', String(show));
                button.setAttribute('aria-label', (show ? 'Masquer ' : 'Afficher ') +
                    (input.id === 'password' ? 'le nouveau mot de passe' : 'la confirmation'));
                input.focus({ preventScroll: true });
            });
        });
    </script>
</body>
</html>