<?php

namespace App\Http\Controllers\API\Cli\Concerns;

use Illuminate\Support\Facades\Artisan;

trait LanceArtisan
{
    /**
     * Lance une commande courte (santé, stats, scan) et rend sa sortie, sans
     * les codes couleur du terminal. Les commandes longues (déploiement,
     * sauvegarde) passent par la file, jamais par ici.
     *
     * @return array{code: int, texte: string}
     */
    protected function artisan(string $commande, array $arguments): array
    {
        $code = Artisan::call($commande, $arguments);

        return ['code' => $code, 'texte' => trim((string) preg_replace('/\e\[[\d;]*m/', '', Artisan::output()))];
    }
}
