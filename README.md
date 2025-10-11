# KLASSCI MASTER - Application SaaS Multi-Tenant

## 🎯 Objectif

Application centralisée pour gérer TOUS les établissements Klassci depuis un seul endroit.

## 📋 Pour démarrer

1. **Ouvrir ce dossier dans Claude Code**
   ```bash
   cd /home/levraimd/workspace/klassciMaster
   ```

2. **Initialiser un nouveau projet Laravel**
   ```bash
   composer create-project laravel/laravel . --prefer-dist
   ```

3. **Demander à Claude de créer l'application complète**

   Le fichier `CLAUDE.md` (symlink vers KLASSCIv2/CLAUDE.md) contient toute la documentation de l'architecture SaaS.

   **Prompt suggéré** :
   ```
   Je veux créer l'application Master pour gérer tous mes établissements Klassci.

   Lis @CLAUDE.md section "ARCHITECTURE SAAS" et crée :

   1. Toutes les migrations (tenants, tenant_deployments, tenant_backups, etc.)
   2. Tous les modèles Eloquent (Tenant, SaasAdmin, etc.)
   3. Toutes les commandes Artisan (tenant:provision, tenant:deploy, etc.)
   4. Tous les contrôleurs (TenantController, DeploymentController, etc.)
   5. Toutes les vues (dashboard, liste tenants, détails tenant, etc.)
   6. Routes complètes
   7. Middleware d'authentification SaaS

   Suis les spécifications exactes dans CLAUDE.md.
   ```

## 🏗️ Architecture

Cette application va gérer :
- ✅ **3 tenants existants** : esbtp-abidjan, esbtp-yakro, presentation
- ✅ **Paywall centralisé** : Remplace le système local actuel
- ✅ **Déploiement automatique** : git pull + migrate sur tous les tenants en 1 clic
- ✅ **Monitoring** : Health checks HTTP, DB, Storage
- ✅ **Backups** : Quotidiens automatiques
- ✅ **Facturation** : Plans, abonnements, factures

## 📚 Documentation (Symlinks)

Tous les fichiers de documentation sont des symlinks vers `../KLASSCIv2/` :

- **.claude/** → Configuration et commandes Claude Code
- **CLAUDE.md** → Documentation principale avec architecture SaaS complète
- **SAAS_ARCHITECTURE.md** → Architecture détaillée avec schémas
- **SAAS_DEPLOYMENT_PLAN.md** → Plan de développement (16 jours)
- **SAAS_MIGRATION_PLAN.md** → Plan de migration des tenants existants (2 jours)
- **deploy-saas.sh** → Script Bash de déploiement automatique

**Contenu CLAUDE.md section ARCHITECTURE SAAS** :
- Vue d'ensemble (2 apps distinctes)
- Tenants existants (esbtp-abidjan, esbtp-yakro, presentation)
- Système Paywall (actuel vs migration)
- Structure Master DB (8 tables)
- Plans tarifaires (Free, Essentiel, Pro, Elite)
- Commandes Artisan Master
- Scheduler (health checks, backups)
- Dashboard Master
- Sécurité (utilisateur readonly, rôles)
- Migration zero downtime (8 étapes)

## 🚀 Prochaines étapes

Après création de l'app :
1. Tester en local
2. Installer sur serveur (`/var/www/klassci-master`)
3. Configurer Nginx pour `admin.klassci.com`
4. Importer les 3 tenants existants
5. Migrer le paywall

## 📞 Support

Voir `CLAUDE.md` pour toutes les spécifications détaillées.
