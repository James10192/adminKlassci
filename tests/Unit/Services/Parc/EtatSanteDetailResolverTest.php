<?php

use App\Services\Parc\EtatSanteDetailResolver;

beforeEach(function () {
    $this->resolver = new EtatSanteDetailResolver;
});

it('refuse de declarer sain un etablissement sans aucun controle', function () {
    expect($this->resolver->resoudre([]))->toBe('unknown');
});

it('refuse de declarer sain si une seule sonde manque ou est perimee', function () {
    expect($this->resolver->resoudre(['healthy', null, 'healthy']))->toBe('unknown')
        ->and($this->resolver->resoudre(['healthy', 'unknown']))->toBe('unknown');
});

it('ne rend vert que tous les controles de disponibilite connus et sains', function () {
    expect($this->resolver->resoudre(['healthy', 'healthy', 'healthy']))->toBe('healthy');
});

it('fait primer une alerte connue sur le manque de mesures', function () {
    expect($this->resolver->resoudre(['healthy', null, 'degraded']))->toBe('degraded')
        ->and($this->resolver->resoudre(['unhealthy', 'degraded', null]))->toBe('unhealthy');
});

it('refuse de convertir un statut inattendu en etat sain', function () {
    expect($this->resolver->resoudre(['healthy', 'warning']))->toBe('unknown');
});
