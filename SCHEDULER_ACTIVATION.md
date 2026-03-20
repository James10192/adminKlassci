# Activation du Scheduler Laravel — adminKlassci

## Prérequis

Le fichier `routes/console.php` contient déjà 7 tâches planifiées :

| Tâche | Fréquence | Heure |
|-------|-----------|-------|
| Health check tous tenants | Toutes les heures | — |
| Backup DB quotidien | Quotidien | 02:00 |
| Backup full (DB + fichiers) | Hebdo (dimanche) | 03:00 |
| Nettoyage backups expirés | Quotidien | 04:00 |
| Stats update tous tenants | Toutes les heures | — |
| Alertes tenants (quota, expiration, santé) | Quotidien | 09:00 |
| Rotation logs tenants | Hebdo (dimanche) | 01:00 |

## Activation en production (cPanel LWS)

### Étape 1 — Accéder à cPanel

1. Connexion SSH : `ssh c2569688c@web44.lws-hosting.com`
2. Ou via cPanel web : section **Avancé > Tâches Cron**

### Étape 2 — Ajouter la tâche cron

```
* * * * * /usr/local/bin/php /home/c2569688c/public_html/admin/artisan schedule:run >> /dev/null 2>&1
```

> **Important** : Utiliser `/usr/local/bin/php` (CLI), pas `php` (qui pointe vers LSAPI sur LWS).

### Étape 3 — Vérifier

```bash
# Vérifier que le cron est actif
crontab -l | grep schedule

# Vérifier les logs après quelques minutes
tail -f ~/public_html/admin/storage/logs/health-checks.log
tail -f ~/public_html/admin/storage/logs/backups.log
tail -f ~/public_html/admin/storage/logs/alerts.log
```

### Étape 4 — Test manuel

```bash
cd ~/public_html/admin

# Tester le scheduler
/usr/local/bin/php artisan schedule:list

# Tester une commande individuellement
/usr/local/bin/php artisan tenant:health-check --all
/usr/local/bin/php artisan tenant:send-alerts --dry-run
/usr/local/bin/php artisan tenant:backup --all --type=database_only
```

## En développement local

```bash
cd adminKlassci
php artisan schedule:work
```

Cela exécute le scheduler en boucle (toutes les minutes) dans le terminal.
