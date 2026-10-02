<?php

namespace App\Support\Sante;

use App\Models\Tenant;
use App\Services\TenantConnectionManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Le septième contrôle de santé : ce que l'école a elle-même noté comme lent.
 *
 * L'école mesure et filtre (KLASSCIv2, table traces_lentes, sous SES seuils).
 * La console lit cette table par la connexion qu'elle ouvre déjà chaque heure
 * pour les statistiques (TenantConnectionManager) : aucun jeton à créer, et une
 * école nouvellement provisionnée est couverte d'office. Lecture seule.
 *
 * L'agrégat reproduit celui de l'école (AgregatDesTraces, KLASSCIv2) : mêmes
 * groupes, même centile, même définition de l'échec, même ordre. Un test
 * compare les deux sur les mêmes lignes.
 *
 * Rend null quand il n'y a rien à dire de vrai : pas d'identifiants de base,
 * base injoignable, ou table absente (école pas encore à jour). Une ligne
 * « saine » affirmerait ce que personne n'a vérifié ; une base injoignable est
 * déjà le constat de database_connection.
 */
final class ControleActionsLentes
{
    public const TABLE = 'traces_lentes';

    /** Au-delà, la lecture s'arrête et le relevé le dit (même borne que l'école). */
    public const LIGNES_MAX = 50000;

    public const REGLAGE_DUREE_MS = 'exploitation.traces_lentes.seuil_ms';

    public const REGLAGE_REQUETES = 'exploitation.traces_lentes.seuil_requetes';

    /** Combien d'actions la fiche du contrôle garde en détail. */
    private const TOP = 5;

    public function __construct(private readonly TenantConnectionManager $connexions)
    {
    }

    /** @return array{type:string,status:string,response_time_ms:?int,details:string,metadata:array}|null */
    public function verifier(Tenant $tenant): ?array
    {
        $connexion = null;
        $debut = microtime(true);
        try {
            $connexion = $this->connexions->createConnection($tenant);
            if (! DB::connection($connexion)->getSchemaBuilder()->hasTable(self::TABLE)) {
                return null;
            }
            // L'heure de la console (UTC+0) borne la fenêtre ; une école réglée
            // sur un autre fuseau (UTC+1 au Bénin) la voit décalée d'une heure,
            // sans effet sur un relevé de 24 heures.
            $agregat = self::agreger(DB::connection($connexion), now()->subDay(), now());
            $seuils = self::seuilsDeLEcole(DB::connection($connexion));
        } catch (\Throwable $e) {
            Log::warning('sante.actions_lentes.lecture_impossible', ['tenant' => $tenant->code, 'erreur' => $e->getMessage()]);

            return null;
        } finally {
            if ($connexion !== null) {
                $this->connexions->closeConnection($connexion);
            }
        }
        $duree = (int) ((microtime(true) - $debut) * 1000);

        return $this->classer(collect($agregat['actions']), $duree, $seuils, $agregat['tronque']);
    }

    /**
     * Les actions lentes d'une période, groupées par (type, nom), comme
     * AgregatDesTraces côté école. Les plus récentes d'abord : si la lecture
     * s'arrête, c'est le passé lointain qu'elle laisse.
     *
     * @return array{tronque: bool, actions: list<array<string, mixed>>}
     */
    public static function agreger(\Illuminate\Database\ConnectionInterface $db, \DateTimeInterface $depuis, \DateTimeInterface $jusqua): array
    {
        $lignes = $db->table(self::TABLE)
            ->whereBetween('created_at', [$depuis, $jusqua])
            ->orderByDesc('id')
            ->limit(self::LIGNES_MAX + 1)
            ->select(['type', 'nom', 'duree_ms', 'requetes_sql', 'code', 'created_at'])
            ->cursor();

        $groupes = [];
        $lues = 0;
        foreach ($lignes as $ligne) {
            if (++$lues > self::LIGNES_MAX) {
                break;
            }
            $g = &$groupes[$ligne->type.'|'.$ligne->nom];
            $g['type'] = $ligne->type;
            $g['nom'] = $ligne->nom;
            $g['durees'][] = (int) $ligne->duree_ms;
            $g['sql'][] = (int) $ligne->requetes_sql;
            $g['echecs'] = ($g['echecs'] ?? 0) + (self::estUnEchec($ligne->type, $ligne->code) ? 1 : 0);
            $g['derniere'] = max($g['derniere'] ?? '', (string) $ligne->created_at);
            unset($g);
        }

        $jours = max(1.0, (\Carbon\Carbon::instance($jusqua)->getTimestamp() - \Carbon\Carbon::instance($depuis)->getTimestamp()) / 86400);
        $actions = array_map(fn (array $g) => [
            'type' => $g['type'],
            'nom' => $g['nom'],
            'nombre' => count($g['durees']),
            'par_jour' => round(count($g['durees']) / $jours, 1),
            'mediane_ms' => self::centile($g['durees'], 50),
            'p95_ms' => self::centile($g['durees'], 95),
            'max_ms' => max($g['durees']),
            'mediane_sql' => self::centile($g['sql'], 50),
            'echecs' => $g['echecs'],
            'derniere' => $g['derniere'],
        ], array_values($groupes));

        usort($actions, fn ($a, $b) => [$b['nombre'], $b['p95_ms']] <=> [$a['nombre'], $a['p95_ms']]);

        return ['tronque' => $lues > self::LIGNES_MAX, 'actions' => $actions];
    }

    /** Un statut 5xx pour une page ; tout code non nul pour un travail ou une commande. */
    public static function estUnEchec(string $type, mixed $code): bool
    {
        if ($code === null) {
            return false;
        }

        return $type === 'requete' ? (int) $code >= 500 : (int) $code !== 0;
    }

    /**
     * Centile par rang le plus proche ; la médiane d'un nombre pair de valeurs
     * est la moyenne des deux du milieu.
     *
     * @param list<int> $valeurs
     */
    public static function centile(array $valeurs, int $centile): int
    {
        sort($valeurs);
        $n = count($valeurs);
        if ($centile === 50 && $n % 2 === 0) {
            return (int) round(($valeurs[$n / 2 - 1] + $valeurs[$n / 2]) / 2);
        }

        return $valeurs[max(0, (int) ceil($centile / 100 * $n) - 1)];
    }

    /**
     * Les seuils sous lesquels l'école ne trace rien, pour le détail du relevé.
     * Mêmes replis et mêmes bornes que SeuilsDesTraces (KLASSCIv2). Illisibles,
     * ils sont omis : ils renseignent, ils ne décident de rien.
     *
     * @return array{duree_ms?: int, requetes_sql?: int}
     */
    private static function seuilsDeLEcole(\Illuminate\Database\ConnectionInterface $db): array
    {
        try {
            $valeurs = collect($db->table('settings')
                ->whereIn('key', [self::REGLAGE_DUREE_MS, self::REGLAGE_REQUETES])
                ->where('is_active', true)
                ->pluck('value', 'key'));
        } catch (\Throwable) {
            return [];
        }
        $entier = fn ($brut, int $repli) => ($brut === null || trim((string) $brut) === '') ? $repli : (int) $brut;

        return [
            'duree_ms' => max(50, min(60000, $entier($valeurs->get(self::REGLAGE_DUREE_MS), 1000))),
            'requetes_sql' => max(10, min(10000, $entier($valeurs->get(self::REGLAGE_REQUETES), 100))),
        ];
    }

    /**
     * Le classement, séparé de la lecture pour se tester sans base d'école.
     *
     * @param Collection<int, array<string, mixed>> $actions
     */
    public function classer(Collection $actions, ?int $duree, array $seuils = [], bool $tronque = false): array
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
        // erreurs, pas des lenteurs. Ses passes en échec sont donc retirées du
        // compte, y compris les rares qui étaient aussi lentes : l'écart va
        // dans le sens prudent (moins d'alertes, jamais une fausse).
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

    private function secondes(int $ms): string
    {
        return rtrim(rtrim(number_format($ms / 1000, 1, ',', ''), '0'), ',') . ' s';
    }
}
