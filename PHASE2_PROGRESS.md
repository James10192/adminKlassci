# Phase 2 - Commandes Artisan - EN COURS

**Date:** 11 octobre 2025
**Statut:** 2/6 complétées (33%)

---

## ✅ Commandes complétées

### 1. `saas:create-admin` - ✅ COMPLÉTÉ & TESTÉ

**Signature :**
```bash
php artisan saas:create-admin
    {--name= : Nom de l'administrateur}
    {--email= : Email de l'administrateur}
    {--password= : Mot de passe (si omis, sera généré)}
    {--role=support : Rôle (super_admin, support, billing)}
    {--phone= : Numéro de téléphone}
```

**Fonctionnalités :**
- ✅ Validation complète des données
- ✅ Hash du mot de passe avec bcrypt
- ✅ Vérification doublon email
- ✅ Mode interactif (demande les infos si non fournies)
- ✅ Confirmation mot de passe
- ✅ Affichage récapitulatif

**Test effectué :**
```bash
php artisan saas:create-admin \
    --name="Marcel Dev" \
    --email="marcel@klassci.com" \
    --password="SecurePass123!" \
    --role="super_admin" \
    --phone="+225 0707123456"
```

**Résultat :** ✅ Admin ID=1 créé avec succès

---

### 2. `tenant:update-stats` - ✅ COMPLÉTÉ (non testé)

**Signature :**
```bash
php artisan tenant:update-stats [tenant] {--all}
```

**Fonctionnalités :**
- ✅ Connexion dynamique à la BDD du tenant
- ✅ Comptage utilisateurs (table `users`)
- ✅ Comptage personnel (via `model_has_roles` + rôles)
- ✅ Comptage étudiants (table `esbtp_etudiants`)
- ✅ Calcul espace stockage (récursif sur dossier `storage/`)
- ✅ Mise à jour colonnes `current_*` dans table `tenants`
- ✅ Support mode batch (tous les tenants avec progress bar)
- ✅ Affichage tableau comparatif (valeur actuelle vs limite)
- ✅ Logging des erreurs

**Usage :**
```bash
# Un tenant spécifique
php artisan tenant:update-stats esbtp-abidjan

# Tous les tenants actifs
php artisan tenant:update-stats

# Tous les tenants (actifs + suspendus)
php artisan tenant:update-stats --all
```

**Note :** À tester une fois qu'un tenant sera provisionné

---

## ⏳ Commandes en attente

### 3. `tenant:health-check` - ⏳ À CRÉER

**Objectif :** Vérifier la santé d'un ou plusieurs tenants

**6 types de checks à implémenter :**
1. **http_status** - Vérifier que le site répond (200 OK)
2. **database_connection** - Tester connexion DB
3. **disk_space** - Vérifier espace disque disponible
4. **ssl_certificate** - Vérifier validité SSL (expiration)
5. **application_errors** - Vérifier logs Laravel pour erreurs récentes
6. **queue_workers** - Vérifier que les queues fonctionnent

**Signature prévue :**
```bash
php artisan tenant:health-check [tenant] {--all} {--type=}
```

---

### 4. `tenant:backup` - ⏳ À CRÉER

**Objectif :** Créer des backups de tenants

**3 types de backups :**
1. **full** - Base de données + fichiers storage
2. **database_only** - Dump SQL uniquement
3. **files_only** - Backup storage/ uniquement

**Fonctionnalités prévues :**
- Compression .tar.gz
- Enregistrement dans table `tenant_backups`
- Calcul taille du backup
- Gestion expiration (auto-suppression après X jours)
- Support mode batch

**Signature prévue :**
```bash
php artisan tenant:backup [tenant] {--type=full} {--all}
```

---

### 5. `tenant:deploy` - ⏳ À CRÉER

**Objectif :** Déployer un ou tous les tenants

**Étapes du déploiement :**
1. Enregistrer début déploiement (table `tenant_deployments`)
2. Se connecter au serveur via SSH
3. `cd` vers le dossier du tenant
4. `git pull origin {git_branch}`
5. `composer install --no-dev --optimize-autoloader`
6. `php artisan migrate --force`
7. `php artisan config:cache`
8. `php artisan route:cache`
9. `php artisan view:cache`
10. `chmod -R 775 storage bootstrap/cache`
11. Enregistrer fin déploiement + durée

**Signature prévue :**
```bash
php artisan tenant:deploy [tenant] {--all}
```

---

### 6. `tenant:provision` - ⏳ À CRÉER (LA PLUS COMPLEXE)

**Objectif :** Provisionner un nouveau tenant en 2 minutes

**Étapes complètes (17 étapes) :**

1. ✅ Validation données entrées
2. ✅ Vérification code unique
3. ✅ Création enregistrement dans table `tenants`
4. 🔧 **Création branche Git** (`git checkout -b {tenant_code}`)
5. 🔧 **Push branche vers origin** (`git push -u origin {tenant_code}`)
6. 🔧 **Création BDD via cPanel API**
7. 🔧 **Création sous-domaine via cPanel API**
8. 🔧 **Connexion SSH au serveur**
9. 🔧 **cd /home/c2569688c/public_html/**
10. 🔧 **git clone -b {tenant_code}**
11. 🔧 **Copie .env.production → .env**
12. 🔧 **Remplacement variables .env**
13. 🔧 **composer install --no-dev**
14. 🔧 **php artisan key:generate**
15. 🔧 **php artisan migrate --force**
16. 🔧 **Exécution scripts (fix_permissions, init_storage, etc.)**
17. 🔧 **SSL AutoSSL (Let's Encrypt)**
18. 🔧 **Health check initial**
19. ✅ Logging complet dans `tenant_activity_logs`

**Signature prévue :**
```bash
php artisan tenant:provision
    {--code= : Code tenant (ex: esbtp-yamoussoukro)}
    {--name= : Nom établissement}
    {--subdomain= : Sous-domaine}
    {--plan=essentiel : Plan tarifaire}
    {--admin-email= : Email administrateur}
    {--admin-name= : Nom administrateur}
```

---

## 📊 Progression Phase 2

- [x] Commandes Artisan créées (6/6) avec `php artisan make:command`
- [x] `composer.json` configuré avec autoload PSR-4
- [x] `composer dump-autoload` exécuté
- [x] `saas:create-admin` complété et testé ✅
- [x] `tenant:update-stats` complété (non testé)
- [ ] `tenant:health-check` à créer
- [ ] `tenant:backup` à créer
- [ ] `tenant:deploy` à créer
- [ ] `tenant:provision` à créer

**Temps estimé restant :** 4-5 heures

---

## 🚀 Prochaines étapes immédiates

1. Créer `tenant:health-check` (simple - 30 min)
2. Créer `tenant:backup` (moyen - 1h)
3. Créer `tenant:deploy` (moyen - 1h30)
4. Créer `tenant:provision` (complexe - 2h)
5. Tester toutes les commandes avec un tenant fictif
6. Documenter dans CLAUDE.md

---

**Fichier :** `/home/levraimd/workspace/klassciMaster/PHASE2_PROGRESS.md`
