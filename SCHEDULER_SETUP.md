# Configuration du Scheduler Laravel

## Tâches programmées

Le système klassci-master exécute automatiquement les tâches suivantes :

### 1. Mise à jour des stats tenants (Toutes les heures)

```bash
php artisan tenant:update-stats
```

**Fréquence** : Toutes les heures (0 * * * *)
**Fonction** : Met à jour les statistiques de tous les tenants actifs :
- Staff (personnel avec compte)
- Students (étudiants avec compte)
- Inscriptions (année courante)
- Storage (MB)

**Options** :
- `withoutOverlapping()` : Évite l'exécution simultanée
- `runInBackground()` : Exécution asynchrone
- Logs : `storage/logs/tenant-stats-updates.log`

### 2. Citation inspirante (Toutes les heures)

```bash
php artisan inspire
```

**Fréquence** : Toutes les heures (0 * * * *)
**Fonction** : Affiche une citation inspirante (commande Laravel par défaut)

---

## Activation en production

### Option 1 : Crontab (Linux/Ubuntu)

Éditer le crontab de l'utilisateur :

```bash
crontab -e
```

Ajouter la ligne suivante :

```cron
* * * * * cd /var/www/klassci-master && php artisan schedule:run >> /dev/null 2>&1
```

**Important** : Remplacer `/var/www/klassci-master` par le chemin réel de l'application.

### Option 2 : Systemd Timer (Recommandé pour production)

Créer le fichier de service :

```bash
sudo nano /etc/systemd/system/klassci-scheduler.service
```

Contenu :

```ini
[Unit]
Description=Klassci Master Scheduler
After=network.target

[Service]
Type=oneshot
User=www-data
WorkingDirectory=/var/www/klassci-master
ExecStart=/usr/bin/php artisan schedule:run
```

Créer le fichier timer :

```bash
sudo nano /etc/systemd/system/klassci-scheduler.timer
```

Contenu :

```ini
[Unit]
Description=Run Klassci Scheduler Every Minute

[Timer]
OnCalendar=*:0/1
Persistent=true

[Install]
WantedBy=timers.target
```

Activer et démarrer :

```bash
sudo systemctl daemon-reload
sudo systemctl enable klassci-scheduler.timer
sudo systemctl start klassci-scheduler.timer
sudo systemctl status klassci-scheduler.timer
```

### Option 3 : Supervisor (Pour dev local)

Créer le fichier de configuration :

```bash
sudo nano /etc/supervisor/conf.d/klassci-scheduler.conf
```

Contenu :

```ini
[program:klassci-scheduler]
process_name=%(program_name)s
command=php /var/www/klassci-master/artisan schedule:work
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/klassci-master/storage/logs/scheduler.log
```

Recharger Supervisor :

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start klassci-scheduler
```

---

## Vérification

### Lister les tâches programmées

```bash
php artisan schedule:list
```

Sortie attendue :

```
0 * * * *  php artisan inspire ................... Next Due: dans 31 minutes
0 * * * *  php artisan tenant:update-stats ....... Next Due: dans 31 minutes
```

### Tester manuellement

Exécuter toutes les tâches dues immédiatement :

```bash
php artisan schedule:run
```

Exécuter une commande spécifique :

```bash
php artisan tenant:update-stats
```

### Vérifier les logs

```bash
# Logs des mises à jour stats
tail -f storage/logs/tenant-stats-updates.log

# Logs Laravel généraux
tail -f storage/logs/laravel.log
```

---

## Désactivation (si nécessaire)

### Crontab

```bash
crontab -e
# Commenter ou supprimer la ligne du scheduler
```

### Systemd

```bash
sudo systemctl stop klassci-scheduler.timer
sudo systemctl disable klassci-scheduler.timer
```

### Supervisor

```bash
sudo supervisorctl stop klassci-scheduler
```

---

## Fréquences disponibles

Laravel Scheduler supporte de nombreuses fréquences :

```php
->everyMinute()         // Toutes les minutes
->everyFiveMinutes()    // Toutes les 5 minutes
->everyTenMinutes()     // Toutes les 10 minutes
->everyFifteenMinutes() // Toutes les 15 minutes
->everyThirtyMinutes()  // Toutes les 30 minutes
->hourly()              // Toutes les heures (00:00)
->hourlyAt(17)          // Toutes les heures à XX:17
->daily()               // Tous les jours à 00:00
->dailyAt('13:00')      // Tous les jours à 13:00
->weekly()              // Tous les lundis à 00:00
->monthly()             // Le 1er de chaque mois à 00:00
```

Pour modifier la fréquence, éditer `bootstrap/app.php` et changer `->hourly()` par la fréquence souhaitée.

---

## Troubleshooting

### Les tâches ne s'exécutent pas

1. Vérifier que le cron est actif :
   ```bash
   sudo systemctl status cron
   ```

2. Vérifier les permissions :
   ```bash
   sudo chown -R www-data:www-data /var/www/klassci-master
   sudo chmod -R 775 /var/www/klassci-master/storage
   ```

3. Vérifier les logs :
   ```bash
   tail -f /var/log/syslog | grep CRON
   ```

### Exécutions multiples simultanées

Si les tâches s'exécutent plusieurs fois en même temps, vérifier :

1. Qu'il n'y a qu'une seule entrée crontab
2. Que `withoutOverlapping()` est bien présent
3. Les logs pour détecter les doublons

---

**Dernière mise à jour** : 11 octobre 2025
**Version Laravel** : 11.x
**Fréquence actuelle** : Toutes les heures
