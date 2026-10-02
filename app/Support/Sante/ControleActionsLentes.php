<?php

namespace App\Support\Sante;

use App\Models\Tenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Le septième contrôle de santé : ce que l'école a elle-même noté comme lent.
 *
 * L'école mesure et filtre (KLASSCIv2, table traces_lentes, sous SES seuils) ;
 * la console ne fait que lire l'agrégat des dernières 24 heures et le classer.
 * Aucune base d'école n'est interrogée d'ici : une seule requête HTTPS, en
 * lecture, avec un jeton cli:read propre à l'école.
 *
 * Rend null quand il n'y a rien à dire de vrai : pas de jeton, ou une école
 * qui ne publie pas encore l'adresse (404, version antérieure). Une ligne
 * « saine » dans ces cas affirmerait ce que personne n'a vérifié, une ligne
 * « dégradée » mettrait toutes les écoles en alerte le jour de la mise en
 * service. Un site injoignable, lui, est déjà le constat de http_status.
 */
final class ControleActionsLentes
{
    public const CHEMIN = '/api/cli/traces/lentes';

    /** Combien d'actions la fiche du contrôle garde en détail. */
    private const TOP = 5;

    /** @return array{type:string,status:string,response_time_ms:?int,details:string,metadata:array}|null */
    public function verifier(Tenant $tenant): ?array
    {
        $jeton = $tenant->cli_lecture_token;
        if (blank($jeton)) {
            return null;
        }

        $debut = microtime(true);
        try {
            $reponse = Http::withToken($jeton)
                ->acceptJson()
                ->timeout(15)
                ->get($tenant->full_url . self::CHEMIN, ['jours' => 1, 'limite' => 100]);
        } catch (ConnectionException) {
            return null;
        }
        $duree = (int) ((microtime(true) - $debut) * 1000);

        if ($reponse->status() === 404) {
            return null;
        }

        if (! $reponse->successful()) {
            return $this->resultat('degraded', $duree, $this->lectureRefusee($reponse->status()), [
                'http_status' => $reponse->status(),
            ]);
        }

        $actions = collect($reponse->json('data.actions', []));

        return $this->classer($actions, $duree, (array) $reponse->json('data.seuils', []), (bool) $reponse->json('data.tronque', false));
    }

    /**
     * Le classement, séparé de la lecture pour se tester sans réseau.
     *
     * @param \Illuminate\Support\Collection<int, array<string, mixed>> $actions
     */
    public function classer($actions, ?int $duree, array $seuils = [], bool $tronque = false): array
    {
        $parJour = (int) config('klassci.actions_lentes.fois_par_jour', 10);
        $p95Critique = (int) config('klassci.actions_lentes.p95_critique_ms', 10000);

        $critiques = $actions->filter(fn ($a) => (int) ($a['p95_ms'] ?? 0) > $p95Critique);
        $travauxEchoues = $actions->filter(
            fn ($a) => ($a['type'] ?? '') !== 'requete' && (int) ($a['echecs'] ?? 0) > 0
        );
        $habituelles = $actions->filter(fn ($a) => (int) ($a['nombre'] ?? 0) > $parJour);

        $statut = match (true) {
            $critiques->isNotEmpty() || $travauxEchoues->isNotEmpty() => 'unhealthy',
            $habituelles->isNotEmpty() => 'degraded',
            default => 'healthy',
        };

        $details = match (true) {
            $travauxEchoues->isNotEmpty() => $travauxEchoues->count() . ' travail(aux) en échec : '
                . $travauxEchoues->pluck('nom')->take(3)->implode(', '),
            $critiques->isNotEmpty() => $critiques->count() . " action(s) au-delà de {$this->secondes($p95Critique)} (p95) : "
                . $critiques->pluck('nom')->take(3)->implode(', '),
            $habituelles->isNotEmpty() => $habituelles->count() . " action(s) lente(s) plus de {$parJour} fois en 24 h",
            $actions->isEmpty() => 'Aucune action lente en 24 h',
            default => $actions->count() . ' action(s) lente(s) occasionnelle(s) en 24 h',
        };

        return $this->resultat($statut, $duree, $details, [
            'fenetre' => '24 heures',
            'seuils_ecole' => $seuils,
            'seuils_console' => ['fois_par_jour' => $parJour, 'p95_critique_ms' => $p95Critique],
            'tronque' => $tronque,
            'total_actions' => $actions->count(),
            'top' => $actions->take(self::TOP)->map(fn ($a) => collect($a)->only([
                'type', 'nom', 'nombre', 'mediane_ms', 'p95_ms', 'max_ms', 'mediane_sql', 'echecs', 'derniere',
            ])->all())->values()->all(),
        ]);
    }

    private function resultat(string $statut, ?int $duree, string $details, array $metadata): array
    {
        return [
            'type' => 'slow_actions',
            'status' => $statut,
            'response_time_ms' => $duree,
            'details' => $details,
            'metadata' => $metadata,
        ];
    }

    private function lectureRefusee(int $code): string
    {
        return match ($code) {
            401 => 'Jeton de lecture refusé par l\'école (401) : à régénérer',
            403 => 'Jeton sans la capacité cli:read (403)',
            default => "Lecture des actions lentes impossible (HTTP {$code})",
        };
    }

    private function secondes(int $ms): string
    {
        return rtrim(rtrim(number_format($ms / 1000, 1, ',', ''), '0'), ',') . ' s';
    }
}
