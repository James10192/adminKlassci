<?php

namespace App\Services\Group;

use App\Contracts\Group\GroupKpiProviderInterface;
use App\Enums\TenantStatus;
use App\Models\Group;
use App\Models\Tenant;
use App\Services\TenantConnectionManager;
use App\Support\EtatMesure;
use App\Support\Period\PeriodFactory;
use App\Support\Period\PeriodInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GroupKpiProvider implements GroupKpiProviderInterface
{
    public function __construct(
        private readonly TenantConnectionManager $connectionManager,
        private readonly TenantAggregator $aggregator,
        private readonly TenantBillingContext $billingContext,
    ) {
    }

    public function computeGroupKpis(Group $group, ?PeriodInterface $period = null): array
    {
        $period ??= PeriodFactory::default();

        $totals = [
            'total_students' => 0,
            'total_inscriptions' => 0,
            'total_revenue_expected' => 0,
            'total_revenue_collected' => 0,
            'total_staff' => 0,
            'establishments' => [],
        ];

        $perTenant = $this->aggregator->aggregate($group, self::class, 'computeTenantKpis', 'TenantKpis', $period);

        foreach ($group->activeTenants as $tenant) {
            $kpis = $perTenant[$tenant->code] ?? $this->emptyKpis($tenant);
            $totals['total_students'] += $kpis['students'];
            $totals['total_inscriptions'] += $kpis['inscriptions'];
            $totals['total_revenue_expected'] += $kpis['revenue_expected'];
            $totals['total_revenue_collected'] += $kpis['revenue_collected'];
            $totals['total_staff'] += $kpis['staff'];
            $totals['establishments'][$tenant->code] = $kpis;
        }

        // Ce que le groupe SAIT, et ce qu'il ne sait pas.
        //
        // Les sommes elles-memes ne changent pas — additionner le zero d'une
        // ecole injoignable donne le meme total que l'exclure — mais un total
        // muet sur son incompletude est un mensonge par omission. Le perimetre
        // se compte par famille, parce qu'une ecole injoignable dont la
        // maitresse detient les effectifs est comptee dans le total des
        // etudiants (au dernier releve) et absente du total encaisse.
        $totals['perimetre'] = $this->perimetre($totals['establishments']);
        $totals['computed_at'] = now()->toIso8601String();

        // Retrocompatibilite : `non_mesures`, `nb_non_mesures` et `complet`
        // etaient deja lus par les vues et les etats exportes. On les garde,
        // sur le critere finances — la famille qu'ils decrivaient de fait.
        $nonMesures = [];
        foreach ($totals['establishments'] as $code => $est) {
            if (! EtatMesure::estMesure($est['etat_finances'] ?? null)) {
                $nonMesures[$code] = [
                    'nom' => $est['tenant_name'] ?? $code,
                    'motif' => $est['motif'] ?? EtatMesure::MOTIF_INJOIGNABLE,
                ];
            }
        }
        $totals['non_mesures'] = $nonMesures;
        $totals['nb_non_mesures'] = count($nonMesures);
        $totals['complet'] = $nonMesures === [];

        // Pas d'attendu, pas de taux — et surtout pas un « 0 % » rouge.
        //
        // Ce garde vivait dans quatre vues et un rapport, chacune avec sa
        // propre version : le Benchmarking retournait le tiret, le tableau de
        // bord, le hero Etablissements, la Vue financiere et l'etat de
        // consolidation affichaient « 0,0 % — critique » en ROUGE. Deux ecoles
        // qui repondent mais dont aucun frais n'est encore configure suffisent
        // a declencher le cas : le directeur lisait l'effondrement de son
        // recouvrement le jour ou il ouvrait son annee.
        //
        // La regle appartient au producteur du chiffre, pas a chacun de ses
        // cinq lecteurs. `collection_rate` est nul quand il n'existe pas, et
        // `finances_mesurables` le dit sans forcer chaque vue a le redecouvrir.
        $totals['finances_mesurables'] = $totals['total_revenue_expected'] > 0;
        $totals['collection_rate'] = $totals['finances_mesurables']
            ? min(100, round(($totals['total_revenue_collected'] / $totals['total_revenue_expected']) * 100, 1))
            : null;

        $totals['establishment_count'] = count($totals['establishments']);

        // Moyenne ponderee par les effectifs — et seulement sur les
        // etablissements dont l'assiduite a ete MESUREE. Un effectif au dernier
        // releve ne porte aucune assiduite : le faire peser dans la moyenne
        // reviendrait a lui preter un taux qu'on n'a pas releve.
        $weightedAttendanceSum = 0;
        $studentsForAttendance = 0;
        foreach ($totals['establishments'] as $est) {
            if (EtatMesure::estMesure($est['etat_assiduite'] ?? null) && ($est['students'] ?? 0) > 0) {
                $weightedAttendanceSum += ($est['attendance_rate'] ?? 0) * $est['students'];
                $studentsForAttendance += $est['students'];
            }
        }
        // Meme regle que pour le recouvrement : sans effectif pesant, il n'y a
        // pas de moyenne. Le zero retourne ici passait pour une mesure et
        // s'affichait en ROUGE (barème d'assiduite : 85/70), alors qu'il ne
        // signifiait que « aucune ecole mesuree ne compte d'etudiant ». Le cas
        // survient reellement en debut d'annee civile : les lignes d'appel de
        // l'annee precedente tombent dans la fenetre calendaire pendant
        // qu'aucune inscription de la nouvelle annee n'est encore validee.
        $totals['assiduite_mesurable'] = $studentsForAttendance > 0;
        $totals['avg_attendance_rate'] = $totals['assiduite_mesurable']
            ? round($weightedAttendanceSum / $studentsForAttendance, 1)
            : null;

        return $totals;
    }

    /**
     * Les familles dont l'indicateur de tete est un TAUX, pas un compte.
     *
     * Un groupe sans etablissement a bien zero etudiant et zero membre du
     * personnel — ce sont des mesures. Il n'a pas « 0 % de recouvrement » :
     * un taux sans denominateur n'existe pas, et l'afficher en rouge accuse
     * d'un effondrement un groupe qui n'a simplement pas encore d'ecole.
     *
     * @var list<string>
     */
    private const FAMILLES_A_TAUX = ['finances', 'assiduite'];

    /**
     * Le perimetre de chaque total : combien d'etablissements ont repondu,
     * combien sont au dernier releve, lesquels manquent.
     *
     * Trois familles, trois perimetres distincts. Les effectifs et le personnel
     * ont un repli dans klassci_master, les finances et l'assiduite n'en ont
     * aucun : la maitresse ne stocke aucun chiffre financier, et un taux de
     * recouvrement vieux d'un jour serait plus dangereux qu'un tiret — il ne
     * bouge pas alors que la tresorerie bouge.
     *
     * @param  array<string,array<string,mixed>>  $establishments
     * @return array<string,array<string,mixed>>
     */
    private function perimetre(array $establishments): array
    {
        $familles = [
            'effectifs' => 'etat_effectifs',
            'personnel' => 'etat_personnel',
            'finances' => 'etat_finances',
            'assiduite' => 'etat_assiduite',
        ];

        $total = count($establishments);
        $perimetre = [];

        foreach ($familles as $famille => $cle) {
            $mesures = 0;
            $releves = 0;
            $manquants = [];

            foreach ($establishments as $code => $est) {
                $etat = $est[$cle] ?? EtatMesure::MESURE;

                if (EtatMesure::estMesure($etat)) {
                    $mesures++;
                } elseif ($etat === EtatMesure::RELEVE) {
                    $releves++;
                } else {
                    // Le motif porte par l'etablissement decrit la PANNE de sa
                    // base. Il ne dit rien d'une famille NON_APPLICABLE, ou la
                    // base a justement repondu : sans ce cas, une ecole qui ne
                    // fait pas l'appel se serait vu reprocher « la base n'a pas
                    // repondu », et le fondateur aurait appele son hebergeur.
                    // Un motif propre a la famille prime sur le motif global :
                    // une ecole dont la base a repondu mais qui n'a configure
                    // aucun frais n'a pas « une base qui n'a pas repondu », et
                    // elle n'a pas non plus « pas ce module » — elle n'a pas
                    // encore de frais. Sans cette precedence, le graphique de
                    // groupe aurait envoye le fondateur appeler son hebergeur.
                    $motifFamille = $est['motif_' . $famille] ?? null;

                    $manquants[$code] = [
                        'nom' => $est['tenant_name'] ?? $code,
                        'motif' => $motifFamille
                            ?? ($etat === EtatMesure::NON_APPLICABLE
                                ? EtatMesure::MOTIF_SANS_MODULE
                                : ($est['motif'] ?? EtatMesure::MOTIF_INJOIGNABLE)),
                    ];
                }
            }

            $perimetre[$famille] = [
                'total' => $total,
                'mesures' => $mesures,
                'releves' => $releves,
                // Ce qui porte une valeur : mesure ou releve. C'est le
                // numerateur de « sur N des M etablissements ».
                'repondu' => $mesures + $releves,
                'manquants' => $manquants,
                'complet' => $manquants === [],
                // Un total qui contient un releve n'est pas une mesure : il
                // bascule entierement en etat RELEVE, et le dira.
                //
                // Un total amputé reste MESURE : ce qu'il additionne a bien été
                // mesuré. C'est `mentionPerimetre()` qui dit qu'il est partiel,
                // pas l'état — un total de trois écoles sur quatre n'est pas
                // « non mesuré ».
                'etat' => match (true) {
                    // Un groupe SANS etablissement : « zero » est la bonne
                    // reponse pour un COMPTE (zero etudiant, zero personnel),
                    // mais il n'existe pas de TAUX sans denominateur. Poser
                    // MESURE sur les deux faisait afficher « Recouvrement moyen
                    // 0,0 % — critique » en ROUGE a un groupe neuf ou dont
                    // toutes les ecoles sont suspendues : le zero fabrique qu'on
                    // corrige, revenu par le bord.
                    //
                    // `getGroupOutstandingAging()` et `getGroupTrends()` gardent
                    // `$total > 0` pour la meme raison — ce sont des montants,
                    // pas des taux.
                    $total === 0 => in_array($famille, self::FAMILLES_A_TAUX, true)
                        ? EtatMesure::NON_MESURE
                        : EtatMesure::MESURE,
                    $mesures + $releves === 0 => EtatMesure::NON_MESURE,
                    $releves > 0 => EtatMesure::RELEVE,
                    default => EtatMesure::MESURE,
                },
            ];
        }

        return $perimetre;
    }

    public function computeTenantKpis(Tenant $tenant, ?PeriodInterface $period = null): array
    {
        $period ??= PeriodFactory::default();

        // Une ecole suspendue ou resiliee n'est pas une ecole en panne.
        //
        // `getEloquentQuery()` de EstablishmentResource ne filtre que sur
        // `group_id` : la liste montre donc AUSSI ces etablissements, et on
        // allait interroger leur base. Celle-ci ne repond generalement plus —
        // on affichait alors « la base de l'etablissement n'a pas repondu »
        // pour une ecole que le groupe a lui-meme suspendue, en designant une
        // panne technique la ou il y a une decision administrative. On s'arrete
        // avant la connexion, et on le dit.
        //
        // C'est `TenantStatus::mesurable()` qui tranche, PAS « tout sauf
        // actif » : une premiere version testait `!== 'active'` et coupait donc
        // aussi la MAINTENANCE. Or la maintenance dure le temps d'un
        // deploiement, la base repond parfaitement, et le directeur voyait ses
        // deux mille etudiants disparaitre derriere un badge « Hors service »
        // — l'exact miroir du defaut que ce chantier corrige : presenter une
        // mesure disponible comme une absence.
        //
        // Les totaux de groupe ne bougent pas : `getGroupKpis()` n'itere que
        // `activeTenants`.
        if (! TenantStatus::mesurableDe($tenant->status)) {
            return $this->emptyKpis($tenant, EtatMesure::MOTIF_INACTIF);
        }

        $conn = $this->connectionManager->createConnection($tenant);

        try {
            $currentYear = DB::connection($conn)
                ->table('esbtp_annee_universitaires')
                ->where('is_current', 1)
                ->first();

            if (!$currentYear) {
                // La base a repondu : ce n'est pas une panne, c'est une ecole
                // sans annee universitaire courante.
                return $this->emptyKpis($tenant, EtatMesure::MOTIF_SANS_ANNEE);
            }

            // Snapshot metrics (students, inscriptions, staff) — Period deliberately ignored.
            // These reflect the current academic year regardless of the date window.
            $inscriptions = DB::connection($conn)
                ->table('esbtp_inscriptions')
                ->where('annee_universitaire_id', $currentYear->id)
                ->where('status', 'active')
                ->where('workflow_step', 'etudiant_cree')
                ->count();

            $students = DB::connection($conn)
                ->table('esbtp_inscriptions')
                ->where('annee_universitaire_id', $currentYear->id)
                ->where('status', 'active')
                ->where('workflow_step', 'etudiant_cree')
                ->distinct()
                ->count('etudiant_id');

            $revenueExpected = $this->billingContext->computeRevenueExpected($conn, $tenant->id, $currentYear->id);

            // Windowed metric: revenue_collected filtered by Period [start, end].
            // When Period === default (CurrentYear), the window spans Jan 1 → Dec 31
            // which is effectively equivalent to the pre-PR4d annee_universitaire_id filter.
            $revenueCollected = (float) DB::connection($conn)
                ->table('esbtp_paiements')
                ->where('annee_universitaire_id', $currentYear->id)
                ->where('status', 'validé')
                ->whereBetween('date_paiement', [$period->startDate(), $period->endDate()])
                ->sum('montant');

            $staff = DB::connection($conn)
                ->table('users')
                ->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->whereIn('roles.name', ['enseignant', 'coordinateur', 'secretaire', 'comptable'])
                ->where('model_has_roles.model_type', 'App\\Models\\User')
                ->distinct()
                ->count('users.id');

            // L'assiduite est bornee par la Periode — toujours, sans repli.
            // Deux commentaires annonçaient ici un « repli 30 jours » qui
            // n'existe dans aucune branche du code : le scorecard en avait
            // tire une colonne « Presences (30j) » posee sur un taux calcule
            // sur l'annee entiere.
            $attendanceRate = $this->computeAttendanceRate($conn, $period);

            return [
                'tenant_id' => $tenant->id,
                'tenant_code' => $tenant->code,
                'tenant_name' => $tenant->name,
                'academic_year' => $currentYear->name ?: ($currentYear->libelle ?: 'N/A'),
                'students' => $students,
                'inscriptions' => $inscriptions,
                'revenue_expected' => $revenueExpected,
                'revenue_collected' => $revenueCollected,
                // Un taux sans attendu n'existe pas. Il valait `0` ici, ce
                // qui passait pour une mesure et s'affichait en ROUGE avec la
                // mention « critique » — sur une ecole qui vient simplement
                // d'ouvrir son annee sans avoir encore configure ses frais.
                'collection_rate' => $revenueExpected > 0
                    ? min(100, round(($revenueCollected / $revenueExpected) * 100, 1))
                    : null,
                'staff' => $staff,
                'attendance_rate' => $attendanceRate ?? 0,
                'status' => $tenant->status,
                'plan' => $tenant->plan,
                'error' => false,
                // La base a repondu : il n'y a pas de motif d'absence GLOBAL.
                // Le motif propre aux finances sans attendu est porte par
                // `motif_finances`, que `perimetre()` lit pour ne pas accuser
                // une panne de base.
                'motif' => null,
                'motif_finances' => $revenueExpected > 0
                    ? null
                    : EtatMesure::MOTIF_SANS_FRAIS,
                // La base a repondu : les quatre familles sont mesurees, et
                // un `0` y veut enfin dire zero.
                'etat_effectifs' => EtatMesure::MESURE,
                'etat_personnel' => EtatMesure::MESURE,
                // Les finances font exception a « la base a repondu, donc
                // c'est mesure » : sans montant attendu, il n'y a pas de taux
                // a mesurer. NON_APPLICABLE, comme l'assiduite d'une ecole qui
                // ne fait pas l'appel — ce n'est pas une panne, et ca ne doit
                // rien alerter.
                'etat_finances' => $revenueExpected > 0
                    ? EtatMesure::MESURE
                    : EtatMesure::NON_APPLICABLE,
                // L'assiduite est la seule famille qui peut manquer alors meme
                // que la base a repondu : une ecole qui ne fait pas l'appel n'a
                // pas de taux, et ce n'est pas une panne.
                'etat_assiduite' => $attendanceRate === null
                    ? EtatMesure::NON_APPLICABLE
                    : EtatMesure::MESURE,
                // Meme nom que sur le chemin d'echec (`emptyKpis()`) : deux noms
                // pour une idee, c'est une cle que personne ne lit des qu'on
                // change de chemin.
                // Chaine ISO8601, comme sur le chemin d'echec : le NOM avait ete
                // unifie, le TYPE divergeait encore (Carbon ici, chaine la-bas).
                // Le meme piege, un cran plus bas.
                'derniere_nouvelle_at' => $tenant->stats_measured_at?->toIso8601String(),
            ];
        } catch (\Exception $e) {
            Log::error("[group-refactor] computeTenantKpis failed for {$tenant->code}: {$e->getMessage()}");
            return $this->emptyKpis($tenant);
        } finally {
            $this->connectionManager->closeConnection($conn);
        }
    }

    /**
     * Le taux de presence, ou `null` quand il n'a pas ete mesure.
     *
     * Cette methode retournait `0` dans TROIS situations que rien ne
     * distinguait ensuite : une assiduite reellement nulle, aucune ligne
     * d'assiduite sur la periode (l'ecole n'utilise pas le module), et une
     * requete en echec (table absente, colonne manquante). L'appelant posait
     * pourtant `etat_assiduite = MESURE` sans condition — donc une ecole qui ne
     * fait pas d'appel affichait une tuile « Taux de presence 0 % ».
     *
     * Un zero mesure reste un zero. Une absence de mesure remonte `null`, et
     * l'appelant en tire l'etat.
     */
    private function computeAttendanceRate(string $conn, PeriodInterface $period): ?float
    {
        try {
            $stats = DB::connection($conn)
                ->table('esbtp_attendances')
                ->whereBetween('date', [$period->startDate(), $period->endDate()])
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present
                ")
                ->first();

            // Aucune ligne d'appel sur la periode : il n'y a pas de taux a
            // calculer. Ce n'est pas une panne — l'ecole n'utilise simplement
            // pas ce module, ou pas encore sur cette periode.
            if (! $stats || (int) $stats->total === 0) {
                return null;
            }

            return round(($stats->present / $stats->total) * 100, 1);
        } catch (\Exception $e) {
            Log::warning("[group-refactor] computeAttendanceRate failed: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Les indicateurs d'un etablissement dont la base n'a pas repondu.
     *
     * Tous les champs valent zero, et c'est precisement le piege : rien ne
     * distinguait « cette ecole n'a aucun etudiant » de « la base de cette
     * ecole n'a pas repondu ». Les quatre familles declarent donc leur etat, et
     * les vues interrogent l'etat AVANT de formater — les zeros qui restent ici
     * ne sont plus lus comme des valeurs.
     *
     * ─── Pourquoi aucun repli sur klassci_master ───
     *
     * La maitresse tient bien `current_students`, `current_staff` et
     * `current_inscriptions_per_year`, et l'idee d'y retomber pour afficher un
     * « dernier releve » est tentante. Elle est fausse : ces colonnes ne
     * mesurent PAS les memes populations que les indicateurs du portail.
     *
     *   `current_students`  compte `esbtp_etudiants WHERE user_id IS NOT NULL`
     *                       — les etudiants qui ont un compte plateforme. Le KPI
     *                       compte les `etudiant_id` distincts inscrits cette
     *                       annee en `status=active AND workflow_step=etudiant_cree`.
     *                       TenantConnectionManager avertit lui-meme que les
     *                       deux divergent fortement (« 1000 etudiants dans la
     *                       BDD mais seulement 300 inscriptions actives »).
     *   `current_inscriptions_per_year` omet le filtre `workflow_step` : c'est
     *                       un sur-ensemble du KPI.
     *   `current_staff`     compte trois roles (enseignant, coordinateur,
     *                       secretaire) ; le KPI en compte quatre — le comptable
     *                       manque.
     *
     * Afficher l'une de ces valeurs sous le libelle du KPI remplacerait un zero
     * VISIBLE par un chiffre FAUX et invisible : le defaut exact qu'on corrige,
     * en pire. Aligner ces colonnes sur les mesures du portail est possible,
     * mais elles alimentent les quotas d'abonnement (`isOverQuota`,
     * `max_students`) : les redefinir changerait silencieusement le paywall de
     * tous les tenants en production. Ce n'est pas une correction a glisser ici.
     *
     * L'etat RELEVE reste donc declare dans EtatMesure — il est juste, et le
     * jour ou un releve mesurera la meme chose il aura sa place — mais aucun
     * producteur ne l'emet aujourd'hui. Ce qu'on ne sait pas, on le dit.
     *
     * `sans_annee` n'est pas une panne : la base a repondu, l'ecole n'a
     * simplement pas d'annee universitaire courante. Les deux cas donnaient le
     * meme zero ; ils ne se disent pas de la meme facon a l'ecran.
     *
     * Cette methode est publique : sa forme est lue par les rapports exportes,
     * la Vue financiere et la verification d'alertes. On n'y retire une cle
     * qu'apres avoir verifie qu'aucun appelant ne la lit.
     *
     * `error` est ce cas limite : le docblock affirmait que trois consommateurs
     * la lisaient, et c'etait FAUX — aucun `grep` ne trouve un seul lecteur de
     * `$kpis['error']` (MasseSalarialeReport, le dernier, est passe a l'etat de
     * mesure). Elle survit comme forme publique de payload, pas parce qu'elle
     * sert : l'etat par famille dit desormais tout ce qu'elle disait, en plus
     * precis. `has_surplus`, elle, a ete retiree — trois ecritures, zero
     * lecteur, et je l'avais moi-meme etendue « par symetrie » plutot que de
     * constater qu'elle etait morte.
     */
    public function emptyKpis(Tenant $tenant, string $motif = EtatMesure::MOTIF_INJOIGNABLE): array
    {
        return [
            'tenant_id' => $tenant->id,
            'tenant_code' => $tenant->code,
            'tenant_name' => $tenant->name,
            'students' => 0,
            'inscriptions' => 0,
            'revenue_expected' => 0,
            'revenue_collected' => 0,
            'collection_rate' => 0,
            'staff' => 0,
            'attendance_rate' => 0,
            'academic_year' => null,
            'status' => $tenant->status,
            'plan' => $tenant->plan,
            'error' => true,
            'motif' => $motif,
            'etat_effectifs' => EtatMesure::NON_MESURE,
            'etat_personnel' => EtatMesure::NON_MESURE,
            'etat_finances' => EtatMesure::NON_MESURE,
            'etat_assiduite' => EtatMesure::NON_MESURE,
            // Date du dernier passage de `tenant:update-stats`. Elle ne date
            // AUCUN chiffre affiche ici (voir ci-dessus) ; elle dit seulement
            // depuis quand la maitresse a eu des nouvelles de cette ecole.
            'derniere_nouvelle_at' => $tenant->stats_measured_at?->toIso8601String(),
        ];
    }
}
