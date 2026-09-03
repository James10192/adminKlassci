<?php

namespace App\Services\Group;

use App\Contracts\Group\GroupKpiProviderInterface;
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

        $totals['collection_rate'] = $totals['total_revenue_expected'] > 0
            ? min(100, round(($totals['total_revenue_collected'] / $totals['total_revenue_expected']) * 100, 1))
            : 0;

        $totals['has_surplus'] = $totals['total_revenue_collected'] > $totals['total_revenue_expected'];

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
        $totals['avg_attendance_rate'] = $studentsForAttendance > 0
            ? round($weightedAttendanceSum / $studentsForAttendance, 1)
            : 0;

        return $totals;
    }

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
                    $manquants[$code] = [
                        'nom' => $est['tenant_name'] ?? $code,
                        'motif' => $etat === EtatMesure::NON_APPLICABLE
                            ? EtatMesure::MOTIF_SANS_MODULE
                            : ($est['motif'] ?? EtatMesure::MOTIF_INJOIGNABLE),
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

        // Une ecole suspendue ou archivee n'est pas une ecole en panne.
        //
        // `getEloquentQuery()` de EstablishmentResource ne filtre que sur
        // `group_id` : la liste montre donc AUSSI les etablissements suspendus,
        // et on allait interroger leur base. Celle-ci ne repond generalement
        // plus — on affichait alors « la base de l'etablissement n'a pas
        // repondu » pour une ecole que le groupe a lui-meme suspendue, en
        // designant une panne technique la ou il y a une decision
        // administrative. On s'arrete avant la connexion, et on le dit.
        //
        // Les totaux de groupe ne bougent pas : `getGroupKpis()` n'itere que
        // `activeTenants`.
        if (($tenant->status ?? '') !== 'active') {
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

            // Attendance windowed by Period when explicit, else pre-PR4d 30-day behaviour.
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
                'collection_rate' => $revenueExpected > 0
                    ? min(100, round(($revenueCollected / $revenueExpected) * 100, 1))
                    : 0,
                'has_surplus' => $revenueCollected > $revenueExpected,
                'staff' => $staff,
                'attendance_rate' => $attendanceRate ?? 0,
                'status' => $tenant->status,
                'plan' => $tenant->plan,
                'error' => false,
                'motif' => null,
                // La base a repondu : les quatre familles sont mesurees, et
                // un `0` y veut enfin dire zero.
                'etat_effectifs' => EtatMesure::MESURE,
                'etat_personnel' => EtatMesure::MESURE,
                'etat_finances' => EtatMesure::MESURE,
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
     * Cette methode est publique : on n'y ajoute que des cles, on n'en retire
     * jamais — EtatEtablissementsReport, FinancialOverview::resultat() et
     * GroupAlertCheck lisent tous `error`.
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
            // Presente sur le chemin nominal : « on n'ajoute que des cles, on
            // n'en retire jamais » vaut dans les deux sens.
            'has_surplus' => false,
            // Date du dernier passage de `tenant:update-stats`. Elle ne date
            // AUCUN chiffre affiche ici (voir ci-dessus) ; elle dit seulement
            // depuis quand la maitresse a eu des nouvelles de cette ecole.
            'derniere_nouvelle_at' => $tenant->stats_measured_at?->toIso8601String(),
        ];
    }
}
