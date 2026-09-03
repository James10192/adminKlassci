<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Period selector (PR4b foundation UI)
    |--------------------------------------------------------------------------
    |
    | When enabled, a Livewire period selector is injected in the group portal
    | topbar via PanelsRenderHook::TOPBAR_END. The selected period is persisted
    | in the URL query string as ?period=current-month|current-year|custom-range.
    |
    | Default OFF for production safety: the selector ships dark and is wired
    | to widget providers in PR4c. Activate progressively via .env:
    |
    |   GROUP_PORTAL_PERIOD_SELECTOR=true
    |
    */
    'period_selector_enabled' => env('GROUP_PORTAL_PERIOD_SELECTOR', false),

    /*
    | Debounce applied client-side (Alpine) before the Livewire state is updated.
    | Tuned for quick clicks without triggering one HTTP roundtrip per keystroke.
    */
    'period_selector_debounce_ms' => (int) env('GROUP_PORTAL_PERIOD_DEBOUNCE_MS', 300),

    /*
    |--------------------------------------------------------------------------
    | Widgets period-aware (PR4e)
    |--------------------------------------------------------------------------
    |
    | When enabled, the 3 time-windowed widgets (KpiOverviewWidget +
    | RevenueComparisonWidget + GroupAgingWidget) listen to the period-change
    | event and re-render with the selected period. When disabled, they ignore
    | the event and always render with the default period (PR4c behaviour).
    |
    | Decoupled from `period_selector_enabled` so ops can roll out the event
    | producer and the consumers independently. Flip this only once the UI
    | selector has proved stable in production.
    |
    |   GROUP_PORTAL_WIDGETS_PERIOD_AWARE=true
    |
    */
    'widgets_period_aware' => env('GROUP_PORTAL_WIDGETS_PERIOD_AWARE', false),

    /*
    |--------------------------------------------------------------------------
    | Subscription alerts banner (PR7a)
    |--------------------------------------------------------------------------
    |
    | Permanent banner at the top of every /groupe page, mirroring the
    | KLASSCIv2 PaywallMiddleware pattern: surfaces the worst subscription
    | expiry tier across the group's tenants so the founder sees a single
    | actionable summary instead of digging into GroupAlertsWidget.
    |
    |   GROUP_PORTAL_ALERTS_BANNER_ENABLED=false   # kill switch
    |
    | Tier thresholds are expressed in days remaining on
    | `tenants.subscription_end_date`:
    |
    |   expired  : days < 0
    |   urgent   : days <= subscription_urgent_days    (maps to AlertSeverity::Critical)
    |   warning  : days <= subscription_warning_days   (maps to AlertSeverity::Warning)
    |   info     : days <= subscription_info_days      (maps to AlertSeverity::Info)
    |   null     : days > subscription_info_days OR end_date is null (free tier)
    |
    | Free-tier tenants (subscription_end_date = null) are always ignored —
    | they have no expiry to worry about.
    */
    'alerts_banner_enabled' => env('GROUP_PORTAL_ALERTS_BANNER_ENABLED', true),
    'subscription_urgent_days' => (int) env('GROUP_PORTAL_SUBSCRIPTION_URGENT_DAYS', 7),
    'subscription_warning_days' => (int) env('GROUP_PORTAL_SUBSCRIPTION_WARNING_DAYS', 14),
    'subscription_info_days' => (int) env('GROUP_PORTAL_SUBSCRIPTION_INFO_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Health alerts (PR7b)
    |--------------------------------------------------------------------------
    |
    | Additional group-level alerts surfaced in GroupAlertsWidget (NOT the
    | permanent banner — that remains reserved for subscription expiry).
    | Four families: plan overage, stale tenant, SSL cert expiry, enrollment
    | decline. Single coarse kill switch (per-alert toggles proved ops burden
    | without operational gain in review).
    |
    |   GROUP_PORTAL_HEALTH_ALERTS_ENABLED=false
    |
    | PlanMismatch replaces the generic QuotaExceeded alert for the `students`
    | quota specifically — the message is richer (plan name + upgrade hint).
    | Other quotas (users, staff, storage) still flow through QuotaExceeded.
    */
    'health_alerts_enabled' => env('GROUP_PORTAL_HEALTH_ALERTS_ENABLED', true),

    'plan_overage_warning_pct' => (int) env('GROUP_PORTAL_PLAN_OVERAGE_WARNING_PCT', 100),
    'plan_overage_critical_pct' => (int) env('GROUP_PORTAL_PLAN_OVERAGE_CRITICAL_PCT', 110),

    'stale_tenant_days' => (int) env('GROUP_PORTAL_STALE_TENANT_DAYS', 30),

    // Group-level SSL thresholds are intentionally more conservative than the
    // per-tenant TenantHealthCheck thresholds (30/7) so the founder is alerted
    // before any single tenant flips to degraded/unhealthy status.
    'ssl_expiry_warning_days' => (int) env('GROUP_PORTAL_SSL_EXPIRY_WARNING_DAYS', 15),
    'ssl_expiry_critical_days' => (int) env('GROUP_PORTAL_SSL_EXPIRY_CRITICAL_DAYS', 7),

    // Single-month dips are noise; the two-consecutive-months requirement in
    // EnrollmentTrendAnalyzer filters for genuine trends.
    'enrollment_decline_threshold_pct' => (int) env('GROUP_PORTAL_ENROLLMENT_DECLINE_THRESHOLD_PCT', 10),

    // Unpaid invoices (PR7c): per-tenant balance_due threshold in FCFA.
    // Pre-aggregated from the master-DB `invoices` table (status sent|overdue)
    // so a group with 20 tenants incurs a single grouped SELECT, not 20 per-
    // tenant queries. Thresholds match the general KLASSCI spending bands.
    'unpaid_invoices_warning_fcfa' => (int) env('GROUP_PORTAL_UNPAID_INVOICES_WARNING_FCFA', 200000),
    'unpaid_invoices_critical_fcfa' => (int) env('GROUP_PORTAL_UNPAID_INVOICES_CRITICAL_FCFA', 500000),

    // Teacher workload (PR7d): weekly hours per teacher thresholds.
    // Computed from `esbtp_seance_cours` (current academic year) per tenant,
    // fanned out via the aggregator pattern. CI labor convention is 40h/week;
    // warning at 30h gives a 10h cushion before the hard ceiling.
    'teacher_workload_warning_hours' => (int) env('GROUP_PORTAL_TEACHER_WORKLOAD_WARNING_HOURS', 30),
    'teacher_workload_critical_hours' => (int) env('GROUP_PORTAL_TEACHER_WORKLOAD_CRITICAL_HOURS', 40),

    /*
    |--------------------------------------------------------------------------
    | Founder email notifications (PR-C)
    |--------------------------------------------------------------------------
    |
    | Pushes alerts surfaced by GroupAlertsWidget directly to group member
    | inboxes (founder, directeur_general, directeur_financier). Two channels:
    |
    |   immediate  — Critical severity alerts, dispatched every 15 min
    |                (aligned with the 5-min health-metrics cache)
    |   digest     — Warning severity, one email/day per member at their
    |                configured digest_time slot
    |
    | Default OFF for safe rollout — ship with the pipeline wired but dark.
    | Flip on per-environment via env:
    |
    |   GROUP_PORTAL_NOTIFICATIONS_ENABLED=true
    |
    | Dedup: each member's `group_alert_notifications_log` is consulted BEFORE
    | dispatch — same fingerprint (group+tenant+type+severity) within the
    | member's dedup_hours window is skipped silently.
    */
    'notifications_enabled' => env('GROUP_PORTAL_NOTIFICATIONS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Rapports programmés
    |--------------------------------------------------------------------------
    |
    | Envoi automatique des états du portail par e-mail, selon les
    | programmations créées par les membres du groupe.
    |
    | Éteint par défaut, comme les notifications : la mécanique est branchée
    | mais ne part pas tant qu'on ne l'allume pas sur l'environnement.
    |
    |   GROUP_PORTAL_SCHEDULED_REPORTS_ENABLED=true
    |
    | Le balayage est horaire ; c'est la commande qui décide de l'échéance,
    | par semaine ou par mois, pour rattraper un passage manqué sans jamais
    | envoyer deux fois dans la même période.
    */
    'scheduled_reports_enabled' => env('GROUP_PORTAL_SCHEDULED_REPORTS_ENABLED', false),

    /*
     * Le domaine sous lequel vivent les sites des établissements.
     *
     * Il était écrit en dur dans le constructeur d'URL SSO. KLASSCI est
     * multi-instance : le jour où une école est hébergée ailleurs — ou où la
     * plateforme change de nom — cette valeur unique évite de servir une
     * adresse fausse à tout le monde sans une seule erreur. La dérogation par
     * établissement reste `tenants.metadata.base_url`.
     */
    'tenant_domain' => env('GROUP_PORTAL_TENANT_DOMAIN', 'klassci.com'),

    /*
    |--------------------------------------------------------------------------
    | Storage ingestion (PR7e)
    |--------------------------------------------------------------------------
    |
    | Populates `tenants.current_storage_mb` from real disk usage via SSH +
    | `du -sm`. Once populated, the existing `collectQuotaAlerts` starts
    | firing for storage — no additional alert plumbing needed.
    |
    | Default OFF so local dev doesn't crash on missing SSH credentials.
    | Production enables via env:
    |
    |   GROUP_PORTAL_STORAGE_INGESTION_ENABLED=true
    |   GROUP_PORTAL_STORAGE_SSH_HOST=web44.lws-hosting.com
    |   GROUP_PORTAL_STORAGE_SSH_USER=c2569688c
    |   GROUP_PORTAL_STORAGE_TENANT_BASE_PATH=/home/c2569688c/public_html
    |
    | SSH keys must be deployed on the master host in the runtime user's
    | ~/.ssh — we don't embed them in config.
    */
    'storage_ingestion_enabled' => env('GROUP_PORTAL_STORAGE_INGESTION_ENABLED', false),
    'storage_ssh_host' => env('GROUP_PORTAL_STORAGE_SSH_HOST', ''),
    'storage_ssh_user' => env('GROUP_PORTAL_STORAGE_SSH_USER', ''),
    'storage_tenant_base_path' => env('GROUP_PORTAL_STORAGE_TENANT_BASE_PATH', ''),
    'storage_ssh_timeout_sec' => (int) env('GROUP_PORTAL_STORAGE_SSH_TIMEOUT_SEC', 30),

    /*
    |--------------------------------------------------------------------------
    | Bounce auto-disable (#47)
    |--------------------------------------------------------------------------
    |
    | When an email-notification job exhausts its retries with a hard bounce
    | (5xx SMTP code), BounceTracker increments `bounce_count` on the member's
    | preferences and flips `disabled_due_to_bounces = true` once
    | `bounce_threshold` is reached. The dispatcher then skips that member on
    | future non-Critical sends — Critical severity ALWAYS bypasses the
    | disable (safer default: founder must hear about Critical even at the
    | risk of one more mail attempt).
    |
    | 4xx codes (soft bounces) are logged to `last_bounce_smtp_code` but do
    | NOT increment the counter — they're transient provider issues, not
    | permanent mailbox failures.
    |
    | Default OFF until a soak period confirms the SMTP-code heuristic has
    | low false-positive rate. Flip per-env:
    |
    |   GROUP_PORTAL_BOUNCE_AUTO_DISABLE_ENABLED=true
    */
    'bounce_auto_disable_enabled' => env('GROUP_PORTAL_BOUNCE_AUTO_DISABLE_ENABLED', false),
    'bounce_threshold' => (int) env('GROUP_PORTAL_BOUNCE_THRESHOLD', 3),

    /*
    |--------------------------------------------------------------------------
    | Member invitation flow (#54)
    |--------------------------------------------------------------------------
    |
    | When enabled, creating a group_member via the admin panel auto-generates
    | a secure password, stores a hashed invitation token, and sends a signed
    | activation URL (24h TTL) by email. On first login, the EnsurePasswordChanged
    | middleware redirects to /groupe/set-password until the member rotates
    | their password.
    |
    | Default OFF so an admin deploying this code doesn't trigger bulk emails
    | on existing tenants. Flip per env:
    |
    |   GROUP_PORTAL_INVITE_FLOW_ENABLED=true
    |
    | Password generator: 16-char mix of letters/digits/symbols via Str::password.
    | Invitation token: 64-char random string, stored as sha256 hash (raw only
    | in the emailed URL — zero plaintext persistence).
    */
    'invite_flow_enabled' => env('GROUP_PORTAL_INVITE_FLOW_ENABLED', false),
    'invitation_ttl_hours' => (int) env('GROUP_PORTAL_INVITATION_TTL_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Seuils de santé d'un taux (recouvrement, assiduité)
    |--------------------------------------------------------------------------
    |
    | Lus par App\Support\RateHealth, qui colore et qualifie tout taux affiché
    | dans le portail. Un taux de recouvrement de 65 % n'a pas le même sens
    | pour un groupe qui encaisse à l'inscription et pour un groupe qui étale
    | sur trois tranches — d'où la configuration plutôt qu'une constante.
    |
    |   >= healthy  -> vert,   « sain »
    |   >= at_risk  -> orange, « à surveiller »
    |   sinon       -> rouge,  « critique »
    |
    | Surchargeables par environnement :
    |   GROUP_PORTAL_RATE_HEALTHY=75
    |   GROUP_PORTAL_RATE_AT_RISK=55
    |
    */
    'rate_health' => [
        'healthy' => (int) env('GROUP_PORTAL_RATE_HEALTHY', 70),
        'at_risk' => (int) env('GROUP_PORTAL_RATE_AT_RISK', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Seuils d'occupation des quotas d'abonnement
    |--------------------------------------------------------------------------
    |
    | Lus par App\Support\QuotaHealth, qui sert A LA FOIS le moteur d'alertes
    | et la couleur de la colonne « Inscriptions ». Les deux les lisaient
    | séparément — le tableau ne testait que le dépassement — et se
    | contredisaient sur le même écran.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Seuils de sante de l'assiduite
    |--------------------------------------------------------------------------
    |
    | Distincts de `rate_health`, qui est calibre pour le RECOUVREMENT. Un taux
    | d'encaissement de 72 % est confortable ; une assiduite de 72 % ne l'est
    | pas. Faire lire a l'assiduite les seuils du recouvrement faisait basculer
    | une tuile de « a surveiller » a « sain » sans decision.
    |
    */

    'attendance_health' => [
        'healthy' => (int) env('GROUP_PORTAL_ATTENDANCE_HEALTHY', 85),
        'at_risk' => (int) env('GROUP_PORTAL_ATTENDANCE_AT_RISK', 70),
    ],

    'quota_health' => [
        'exceeded' => (int) env('GROUP_PORTAL_QUOTA_EXCEEDED', 100),
        'critical' => (int) env('GROUP_PORTAL_QUOTA_CRITICAL', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Identité visuelle du portail
    |--------------------------------------------------------------------------
    |
    | Valeurs de repli, utilisées quand le groupe connecté n'a pas défini les
    | siennes. La cascade complète (groupe -> établissement -> KLASSCI) est
    | résolue par App\Services\Group\GroupBranding ; ce bloc n'en est que le
    | dernier étage, celui de la marque KLASSCI elle-même.
    |
    | Aucune de ces valeurs n'est écrite en dur dans GroupPanelProvider : un
    | groupe qui pose son logo et sa couleur doit voir son portail changer
    | sans qu'on redéploie.
    |
    */
    'branding' => [
        'name' => env('GROUP_PORTAL_BRAND_NAME', 'KLASSCI Groupe'),
        'logo' => env('GROUP_PORTAL_BRAND_LOGO', 'images/LOGO-KLASSCI-PNG.png'),
        'logo_height' => env('GROUP_PORTAL_BRAND_LOGO_HEIGHT', '2.5rem'),

        // Côté document, le logo est réduit à cette taille avant d'être
        // embarqué. Les écrans, eux, servent toujours le fichier d'origine.
        // Un logo de 1080 px affiché à 40 points pesait 99 % d'un PDF de
        // quatre lignes — pièce jointe comprise, à chaque destinataire.
        'logo_max_px' => (int) env('GROUP_PORTAL_BRAND_LOGO_MAX_PX', 320),
        'favicon' => env('GROUP_PORTAL_BRAND_FAVICON', 'images/LOGO-KLASSCI-PNG.png'),
        'primary' => env('GROUP_PORTAL_BRAND_PRIMARY', '#0453cb'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Garde-fous de volume sur les exports
    |--------------------------------------------------------------------------
    |
    | DomPDF n'échoue pas franchement sur un gros tableau : il épuise la
    | mémoire PHP et rend un document tronqué ou vide. Or un rapport vide
    | ressemble à « rien à signaler », ce qu'un outil de direction ne doit
    | jamais laisser croire. Au-delà du seuil, ReportRenderer refuse en
    | disant combien de lignes la sélection compte et quoi faire.
    |
    | Le tableur encaisse bien davantage, d'où l'écart entre les deux.
    |
    */
    'exports' => [
        'max_pdf_rows' => (int) env('GROUP_PORTAL_MAX_PDF_ROWS', 1000),
        'max_excel_rows' => (int) env('GROUP_PORTAL_MAX_EXCEL_ROWS', 50000),
    ],
];
