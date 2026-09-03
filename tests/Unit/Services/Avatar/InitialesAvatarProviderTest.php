<?php

use App\Support\Avatar\InitialesAvatarProvider;

beforeEach(fn () => $this->p = new InitialesAvatarProvider);

it('prend la premiere lettre du prenom et celle du dernier mot', function () {
    expect($this->p->initiales('Marcel Djedje-li'))->toBe('ML');
});

it('se contente d une lettre pour un nom seul', function () {
    expect($this->p->initiales('Marcel'))->toBe('M');
});

it('ignore les mots du milieu', function () {
    expect($this->p->initiales('Issouf Amadou Ouédraogo'))->toBe('IO');
});

it('met les accents en majuscule correctement', function () {
    expect($this->p->initiales('Émile Ébrottié'))->toBe('ÉÉ');
});

it('ne rend pas une chaine vide sur un nom vide', function () {
    expect($this->p->initiales(''))->toBe('?')
        ->and($this->p->initiales('   '))->toBe('?');
});

it('donne a chacun une teinte stable, et pas la meme a deux personnes', function () {
    expect($this->p->couleur('Marcel Djedje-li'))->toBe($this->p->couleur('Marcel Djedje-li'))
        ->and($this->p->couleur('Marcel Djedje-li'))->not->toBe($this->p->couleur('Issouf Ouédraogo'));
});

it('n appelle aucun service tiers', function () {
    $membre = new class extends Illuminate\Database\Eloquent\Model
    {
        protected $attributes = ['name' => 'Marcel Djedje-li'];
    };

    $uri = $this->p->get($membre);

    // Le defaut de Filament renvoie une URL https://ui-avatars.com/... portant
    // le nom de la personne. Ici, rien ne sort.
    expect($uri)->toStartWith('data:image/svg+xml;base64,')
        ->and($uri)->not->toContain('http');

    $svg = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')));

    expect($svg)->toContain('<svg')
        ->toContain('ML')
        // Le nom complet ne doit pas non plus se retrouver dans l image.
        ->not->toContain('Djedje');
});
