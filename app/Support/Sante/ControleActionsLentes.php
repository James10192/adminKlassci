<?php

namespace App\Support\Sante;

use App\Models\Tenant;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        // Un jeton illisible (clé de l'application changée, valeur abîmée) ne
        // doit pas arrêter la tournée des autres écoles : on le dit, une fois.
        try {
            $jeton = $tenant->cli_lecture_token;
        } catch (DecryptException $e) {
            Log::warning('sante.actions_lentes.jeton_illisible', ['tenant' => $tenant->code]);

            return $this->resultat('degraded', null, 'Jeton de lecture illisible : à ressaisir sur la fiche de l\'école', []);
        }
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
        } catch (\Throwable $e) {
            Log::warning('sante.actions_lentes.lecture_impossible', ['tenant' => $tenant->code, 'erreur' => $e->getMessage()]);

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

        // Une page de maintenance ou une route de repli répond 200 sans le
        // contrat : la lire comme « aucune action lente » inventerait un verdict.
        $actions = $reponse->json('data.actions');
        if ($reponse->json('success') !== true || ! is_array($actions)) {
            return null;
        }
        $actions = collect($actions);

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
        $echecsCritiques = max(1, (int) config('klassci.actions_lentes.echecs_critiques', 3));

        $critiques = $actions->filter(fn ($a) => (int) ($a['p95_ms'] ?? 0) > $p95Critique);
        // Un envoi qui dépend d'un service tiers échoue parfois une fois : c'est
        // à surveiller, pas une alerte rouge pour vingt-quatre heures.
        $travauxEchoues = $actions->filter(
            fn ($a) => ($a['type'] ?? '') !== 'requete' && (int) ($a['echecs'] ?? 0) > 0
        );
        $travauxEnEchecRepete = $travauxEchoues->filter(fn ($a) => (int) $a['echecs'] >= $echecsCritiques);
        // Une page en 500 est notée même rapide : elle relève du contrôle des
        // erreurs, pas des lenteurs. Seules ses passes lentes sont comptées ici.
        $habituelles = $actions->filter(fn ($a) => self::lentes($a) > $parJour);

        $statut = match (true) {
            $critiques->isNotEmpty() || $travauxEnEchecRepete->isNotEmpty() => 'unhealthy',
            $travauxEchoues->isNotEmpty() || $habituelles->isNotEmpty() => 'degraded',
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
            'seuils_console' => ['fois_par_jour' => $parJour, 'p95_critique_ms' => $p95Critique, 'echecs_critiques' => $echecsCritiques],
            'tronque' => $tronque,
            'total_actions' => $actions->count(),
            'top' => $actions->take(self::TOP)->map(fn ($a) => collect($a)->only([
                'type', 'nom', 'nombre', 'mediane_ms', 'p95_ms', 'max_ms', 'mediane_sql', 'echecs', 'derniere',
            ])->all())->values()->all(),
        ]);
    }

    /** @param array<string, mixed> $a */
    private static function lentes(array $a): int
    {
        $nombre = (int) ($a['nombre'] ?? 0);

        return ($a['type'] ?? '') === 'requete' ? $nombre - (int) ($a['echecs'] ?? 0) : $nombre;
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
