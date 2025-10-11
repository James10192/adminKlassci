# 🚀 PLAN DE PROVISIONING AUTOMATISÉ - KLASSCI MASTER

**Date:** 11 octobre 2025
**Version:** 1.0
**Environnement:** Production cPanel (web44.lws-hosting.com)

---

## 📋 TABLE DES MATIÈRES

1. [Contraintes Production Identifiées](#contraintes-production)
2. [Architecture Réelle](#architecture-réelle)
3. [Workflow de Provisioning](#workflow-provisioning)
4. [Structure des Bases de Données](#structure-bdd)
5. [Scripts de Déploiement](#scripts-déploiement)
6. [Plan d'Implémentation](#plan-implémentation)

---

## 🔍 CONTRAINTES PRODUCTION IDENTIFIÉES

### **Environnement serveur cPanel**

**Serveur:** `web44.lws-hosting.com:2083` (cPanel)
**Utilisateur:** `c2569688c`
**Chemin racine:** `/home/c2569688c/public_html/`

**Tenants existants (confirmés):**
```bash
/home/c2569688c/public_html/
├── esbtp-abidjan/          # ESBTP Abidjan (Production) → branch: origin/esbtp-abidjan
├── esbtp-yakro/            # ESBTP Yakro (Production) → branch: origin/esbtp-yakro
├── presentation/           # Test Présentation → branch: origin/presentation (HEAD)
└── ifran/                  # IFRAN → branch: origin/IFRAN
```

### **Stratégie Git Multi-Branches (CRITIQUE)**

**Convention observée:** Chaque tenant possède **sa propre branche Git** dans le repo `KLASSCIv2`.

**Branches existantes (confirmées via `git branch -r`):**
```bash
origin/HEAD → origin/presentation   # Branche par défaut (main)
origin/IFRAN                        # Tenant IFRAN (uppercase)
origin/IfranModif                   # Branch de développement IFRAN
origin/esbt-abidjan                 # Typo (obsolète, remplacé par esbtp-abidjan)
origin/esbtp-abidjan               # ✅ Tenant ESBTP Abidjan (PRODUCTION)
origin/esbtp-yakro                 # ✅ Tenant ESBTP Yakro (PRODUCTION)
origin/modif                       # Branch générale de développement
origin/presentation                # ✅ Tenant test (branch HEAD)
```

**Convention de nommage:**
- Format standard : `{tenant_code}` (ex: `esbtp-abidjan`, `esbtp-yakro`)
- Cas particulier : `IFRAN` (uppercase pour raisons historiques)
- Branches dev : `{tenant_code}Modif` (ex: `IfranModif`)

**Mapping Tenant → Branche Git:**
| Tenant Code | Dossier Serveur | Branche Git | Base de données |
|-------------|-----------------|-------------|-----------------|
| esbtp-abidjan | `/home/c2569688c/public_html/esbtp-abidjan/` | `origin/esbtp-abidjan` | `c2569688c_esbtp_abidjan` |
| esbtp-yakro | `/home/c2569688c/public_html/esbtp-yakro/` | `origin/esbtp-yakro` | `c2569688c_esbtp_yakro` |
| presentation | `/home/c2569688c/public_html/presentation/` | `origin/presentation` | `c2569688c_smart_school` |
| ifran | `/home/c2569688c/public_html/ifran/` | `origin/IFRAN` | `c2569688c_ifran` |

**Avantages de cette architecture:**
✅ **Isolation complète** : Chaque école peut avoir ses personnalisations (seeders matricule, logos, configs spécifiques)
✅ **Déploiements indépendants** : Mise à jour d'une école sans impacter les autres
✅ **Rollback facile** : Retour arrière possible par tenant via `git checkout`
✅ **Hotfixes ciblés** : Correction rapide pour un tenant spécifique
✅ **Historique clair** : Traçabilité des changements par établissement

**Workflow Git:**
```
presentation (main) → Nouvelles features globales
       ↓ merge
origin/esbtp-abidjan → Personnalisations ESBTP Abidjan
origin/esbtp-yakro → Personnalisations ESBTP Yakro
origin/IFRAN → Personnalisations IFRAN
```

**Exemple : ESBTPAbidjanMatriculeConfigSeeder**
- Le seeder `ESBTPAbidjanMatriculeConfigSeeder` existe uniquement dans la branche `origin/esbtp-abidjan`
- Il n'existe PAS dans `origin/esbtp-yakro` (qui a son propre seeder de matricules)
- Chaque tenant a **son propre seeder de nomenclature** dans sa branche

**Impact sur le provisioning:**
⚠️ **IMPORTANT** : Lors du provisioning d'un nouveau tenant, il faut :
1. ✅ Créer une nouvelle branche Git : `origin/{tenant_code}`
2. ✅ Cloner le repo avec `git clone -b {tenant_code}` (pas `-b main`)
3. ✅ Permettre au client de créer son seeder de matricules personnalisé après provisioning

**Commande de clonage modifiée:**
```bash
# ❌ ANCIEN (incorrect pour multi-tenant)
git clone https://github.com/yourrepo/KLASSCIv2.git esbtp-yamoussoukro

# ✅ NOUVEAU (correct avec branche dédiée)
# Option 1: Clone puis checkout
git clone https://github.com/yourrepo/KLASSCIv2.git esbtp-yamoussoukro
cd esbtp-yamoussoukro
git checkout -b esbtp-yamoussoukro origin/presentation  # Créer branch depuis presentation

# Option 2: Clone direct avec branche
git clone -b esbtp-yamoussoukro https://github.com/yourrepo/KLASSCIv2.git esbtp-yamoussoukro
```

### **Convention de nommage des bases de données**

**Format observé:** `c2569688c_{tenant_code}`

**Exemples confirmés:**
- `c2569688c_esbtp_abidjan` → ESBTP Abidjan
- `c2569688c_esbtp_yakro` → ESBTP Yakro
- `c2569688c_ifran` → Ifran
- `c2569688c_smart_school` → Compte test (presentation.klassci.com)

**Contrainte:** Le préfixe `c2569688c_` est **obligatoire** et **automatique** sur cPanel.

### **Fichiers de configuration**

**Template utilisé:** `.env.production` (PAS `.env.example`)

**Configuration SMTP (mail.klassci.com):**
```env
MAIL_MAILER=smtp
MAIL_HOST=mail.klassci.com
MAIL_PORT=465
MAIL_USERNAME=support@klassci.com
MAIL_PASSWORD=@FV@8BWyKk3JiPb
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS=support@klassci.com
MAIL_FROM_NAME="KLASSCI"
```

**Configuration Orange SMS API:**
```env
SMS_PROVIDER=orange
SMS_ENABLED=true
ORANGE_CLIENT_ID=f7vr4LNsxfCcOx0fUI9FxPzF7pTVEF9G
ORANGE_CLIENT_SECRET=g5PlyAuy7enUnTbQSyR9LsMXxDpYjWh9gR5hXYvixQCk
```

### **Scripts de déploiement**

**Premier déploiement (dans l'ordre):**
```bash
1. php fix_permissions.php         # Permissions Spatie + rôles
2. php init_storage.php             # Structure dossiers storage
3. php create_storage_link.php      # Lien symbolique (préféré à artisan storage:link)
4. php deploy_settings.php          # Settings critiques
```

**Déploiements suivants (maintenance):**
```bash
php fix_permissions.php             # Uniquement celui-ci suffit
```

### **Seeders**

**❌ NE PAS exécuter automatiquement:**
- `RoleSeeder` → Géré par `fix_permissions.php`

**⚠️ Exécuter UNIQUEMENT sur demande explicite du client:**
- `ESBTPAbidjanMatriculeConfigSeeder` → Nomenclature spécifique école
- `SettingsSeeder` → Géré par `deploy_settings.php`
- `ServiceTechniqueSeeder` → Géré par `fix_permissions.php`

**Raison:** Chaque école a sa propre nomenclature de matricules. Il faut créer un seeder personnalisé par tenant.

### **Commandes Laravel à ne PAS utiliser**

```bash
# ❌ INTERDIT
php artisan storage:link

# ✅ UTILISER À LA PLACE
php create_storage_link.php
```

**Raison:** Le script `create_storage_link.php` est plus robuste et gère mieux les cas edge (Windows/Linux, backup ancien lien, vérifications).

---

## 🏗️ ARCHITECTURE RÉELLE

### **1. klassci-master (Nouvelle app)**

**Emplacement local (développement):**
```
~/workspace/klassci-master/
```

**Emplacement production (futur):**
```
/home/c2569688c/public_html/klassci-master/
```

**URL:** `https://admin.klassci.com`

**Base de données:** `c2569688c_klassci_master`

**Rôle:**
- Gérer tous les tenants depuis une interface centralisée
- Provisionner nouveaux établissements automatiquement
- Déployer mises à jour sur tous les tenants
- Monitoring et health checks
- Gestion centralisée des paywall
- Backups automatiques

### **2. KLASSCIv2 (App existante - Tenants)**

**Emplacement production:**
```
/home/c2569688c/public_html/{tenant_code}/
```

**URL pattern:** `https://{tenant_code}.klassci.com`

**Base de données pattern:** `c2569688c_{tenant_code}`

**Exemples:**
- `esbtp-abidjan` → `https://esbtp-abidjan.klassci.com` → `c2569688c_esbtp_abidjan`
- `esbtp-yakro` → `https://esbtp-yakro.klassci.com` → `c2569688c_esbtp_yakro`

---

## 🔄 WORKFLOW DE PROVISIONING

### **Phase 1: Création via klassci-master (Interface Web)**

**URL:** `https://admin.klassci.com/saas/tenants/create`

**Formulaire:**
```
Code établissement*: [esbtp-yamoussoukro] (slug unique)
Nom établissement*: [ESBTP Yamoussoukro]
Subdomain*: [esbtp-yamoussoukro]
Email admin*: [admin@esbtp-yamoussoukro.ci]
Plan tarifaire*: [Free / Essentiel / Professional / Elite]
Branche Git: [main]
```

**Validation:**
- Code unique (pas déjà utilisé)
- Format slug valide (lettres, chiffres, tirets uniquement)
- Subdomain disponible
- Email valide

### **Phase 2: Provisioning automatique (Backend)**

**Commande Artisan déclenchée:**
```bash
php artisan tenant:provision \
    --code=esbtp-yamoussoukro \
    --name="ESBTP Yamoussoukro" \
    --subdomain=esbtp-yamoussoukro \
    --plan=essentiel \
    --admin-email=admin@esbtp-yamoussoukro.ci \
    --environment=production
```

**Étapes automatisées:**

#### **Étape 1: Création base de données (API cPanel)**
```php
// Via cPanel API UAPI/WHM
POST https://web44.lws-hosting.com:2083/execute/Mysql/create_database
{
    "name": "esbtp_yamoussoukro"  // Devient automatiquement c2569688c_esbtp_yamoussoukro
}

POST https://web44.lws-hosting.com:2083/execute/Mysql/create_user
{
    "name": "esbtp_yam_user",
    "password": "GENERATED_SECURE_PASSWORD"
}

POST https://web44.lws-hosting.com:2083/execute/Mysql/set_privileges_on_database
{
    "user": "c2569688c_esbtp_yam_user",
    "database": "c2569688c_esbtp_yamoussoukro",
    "privileges": "ALL PRIVILEGES"
}
```

#### **Étape 2: Création branche Git tenant + Clonage (SSH)**

**⚠️ IMPORTANT:** Se déplacer dans `public_html` AVANT le clone !

```bash
# === ÉTAPE 2A: Créer branche Git (Locale ou sur Master) ===
cd /path/to/KLASSCIv2
git checkout presentation
git pull origin presentation
git checkout -b esbtp-yamoussoukro
git push -u origin esbtp-yamoussoukro
# Résultat: Branch origin/esbtp-yamoussoukro créée

# === ÉTAPE 2B: Cloner avec la branche tenant (SSH sur serveur) ===
ssh c2569688c@web44.lws-hosting.com << 'EOF'
# 1. Se déplacer dans public_html AVANT le clone (CRITIQUE!)
cd /home/c2569688c/public_html/

# 2. Vérifier qu'on est au bon endroit
pwd  # DOIT afficher: /home/c2569688c/public_html

# 3. Clone avec la branche spécifique du tenant
git clone -b esbtp-yamoussoukro https://github.com/yourrepo/KLASSCIv2.git esbtp-yamoussoukro

# 4. Vérifier que le dossier a été créé
ls -la esbtp-yamoussoukro/  # Doit lister les fichiers Laravel

# 5. Se déplacer dans le dossier
cd esbtp-yamoussoukro

# 6. Vérifier qu'on est sur la bonne branche
git branch --show-current  # Doit afficher: esbtp-yamoussoukro

EOF
```

**✅ Résultat garanti:** Le dossier est créé à `/home/c2569688c/public_html/esbtp-yamoussoukro/`

#### **Étape 3: Configuration .env (Génération dynamique)**
```bash
# Copier template
cp .env.production .env

# Remplacer variables dynamiquement
sed -i "s|APP_NAME=.*|APP_NAME=\"ESBTP Yamoussoukro\"|" .env
sed -i "s|APP_URL=.*|APP_URL=https://esbtp-yamoussoukro.klassci.com|" .env
sed -i "s|DB_DATABASE=.*|DB_DATABASE=c2569688c_esbtp_yamoussoukro|" .env
sed -i "s|DB_USERNAME=.*|DB_USERNAME=c2569688c_esbtp_yam_user|" .env
sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=${GENERATED_PASSWORD}|" .env

# Ajouter variables spécifiques tenant
echo "" >> .env
echo "# TENANT CONFIGURATION" >> .env
echo "TENANT_CODE=esbtp-yamoussoukro" >> .env
echo "TENANT_PLAN=essentiel" >> .env
```

**Variables injectées automatiquement:**
- `APP_NAME` → Nom établissement
- `APP_URL` → URL subdomain
- `DB_DATABASE` → Nom BDD avec préfixe cPanel
- `DB_USERNAME` → User MySQL avec préfixe
- `DB_PASSWORD` → Password sécurisé généré
- `TENANT_CODE` → Code unique tenant
- `TENANT_PLAN` → Plan tarifaire
- **SMTP & Orange API → Copiés depuis .env.production (déjà configurés)**

#### **Étape 4: Installation dépendances**
```bash
composer install --no-dev --optimize-autoloader --no-interaction
```

#### **Étape 5: Génération clé Laravel**
```bash
php artisan key:generate --force
```

#### **Étape 6: Migrations**
```bash
php artisan migrate --force
```

#### **Étape 7: Scripts de setup (Premier déploiement uniquement)**
```bash
php fix_permissions.php         # Permissions + Rôles
php init_storage.php            # Structure storage
php create_storage_link.php     # Lien symbolique
php deploy_settings.php         # Settings critiques
```

#### **Étape 8: Optimisations Laravel**
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

#### **Étape 9: Permissions fichiers**
```bash
chmod -R 755 storage bootstrap/cache
chown -R c2569688c:c2569688c .
```

#### **Étape 10: Création fichier .tenant.json**
```json
{
  "code": "esbtp-yamoussoukro",
  "name": "ESBTP Yamoussoukro",
  "subdomain": "esbtp-yamoussoukro",
  "database": {
    "name": "c2569688c_esbtp_yamoussoukro",
    "username": "c2569688c_esbtp_yam_user",
    "host": "localhost",
    "port": 3306
  },
  "git_branch": "main",
  "plan": "essentiel",
  "subscription_end": "2026-10-11",
  "max_users": 20,
  "max_inscriptions_per_year": 700,
  "max_storage_mb": 2048,
  "created_at": "2025-10-11T10:30:00Z",
  "provisioned_at": "2025-10-11T10:32:15Z",
  "status": "active"
}
```

#### **Étape 11: Création sous-domaine (API cPanel)**
```php
POST https://web44.lws-hosting.com:2083/execute/SubDomain/addsubdomain
{
    "domain": "esbtp-yamoussoukro",
    "rootdomain": "klassci.com",
    "dir": "/home/c2569688c/public_html/esbtp-yamoussoukro/public"
}
```

#### **Étape 12: Configuration SSL (AutoSSL cPanel)**
```php
// Déclencher AutoSSL (Let's Encrypt gratuit intégré cPanel)
POST https://web44.lws-hosting.com:2083/execute/SSL/install_ssl
{
    "domain": "esbtp-yamoussoukro.klassci.com"
}
```

#### **Étape 13: Enregistrement dans klassci_master**
```sql
INSERT INTO c2569688c_klassci_master.tenants (
    code, name, subdomain, database_name, database_username,
    git_branch, plan, status, subscription_end_date,
    max_users, max_inscriptions_per_year, max_storage_mb,
    admin_email, created_at, provisioned_at
) VALUES (
    'esbtp-yamoussoukro',
    'ESBTP Yamoussoukro',
    'esbtp-yamoussoukro',
    'c2569688c_esbtp_yamoussoukro',
    'c2569688c_esbtp_yam_user',
    'main',
    'essentiel',
    'active',
    '2026-10-11 23:59:59',
    20, 700, 2048,
    'admin@esbtp-yamoussoukro.ci',
    NOW(),
    NOW()
);
```

#### **Étape 14: Health Check initial**
```bash
# Vérifier que le tenant est accessible
curl -I https://esbtp-yamoussoukro.klassci.com

# Vérifier connexion DB
php artisan tinker --execute="DB::connection()->getPdo();"

# Vérifier storage
test -L public/storage && echo "Storage link OK"
```

#### **Étape 15: Notification admin**
```php
// Email envoyé à admin@esbtp-yamoussoukro.ci
Mail::to($adminEmail)->send(new TenantProvisionedMail([
    'tenant_name' => 'ESBTP Yamoussoukro',
    'url' => 'https://esbtp-yamoussoukro.klassci.com',
    'username' => 'admin',
    'password' => 'GENERATED_INITIAL_PASSWORD',
    'plan' => 'Essentiel (20 users, 700 inscriptions/an)',
    'support_email' => 'support@klassci.com'
]));
```

**Durée totale estimée:** 2-3 minutes ⏱️

---

## 📊 STRUCTURE DES BASES DE DONNÉES

### **Base Master (c2569688c_klassci_master)**

#### **Table: tenants**
```sql
CREATE TABLE tenants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,           -- esbtp-yamoussoukro
    name VARCHAR(255) NOT NULL,                  -- ESBTP Yamoussoukro
    subdomain VARCHAR(100) UNIQUE NOT NULL,      -- esbtp-yamoussoukro

    -- Database info
    database_name VARCHAR(100) NOT NULL,         -- c2569688c_esbtp_yamoussoukro
    database_username VARCHAR(100) NOT NULL,     -- c2569688c_esbtp_yam_user
    database_password_encrypted TEXT,            -- Crypté avec Laravel encrypt()

    -- Git & Deployment
    git_branch VARCHAR(50) DEFAULT 'main',
    git_commit_hash VARCHAR(40),
    last_deployed_at TIMESTAMP NULL,

    -- Subscription & Plan
    status ENUM('active', 'suspended', 'expired', 'trial') DEFAULT 'active',
    plan ENUM('free', 'essentiel', 'professional', 'elite') DEFAULT 'free',
    monthly_fee DECIMAL(10,2) DEFAULT 0,
    subscription_start_date DATE,
    subscription_end_date DATE,

    -- Limits (Paywall)
    max_users INT DEFAULT 5,
    max_staff INT DEFAULT 5,
    max_students INT DEFAULT 50,
    max_inscriptions_per_year INT DEFAULT 50,
    max_storage_mb INT DEFAULT 512,

    -- Current usage (updated by scheduler)
    current_users INT DEFAULT 0,
    current_staff INT DEFAULT 0,
    current_students INT DEFAULT 0,
    current_inscriptions_this_year INT DEFAULT 0,
    current_storage_mb DECIMAL(10,2) DEFAULT 0,

    -- Admin contact
    admin_name VARCHAR(255),
    admin_email VARCHAR(255),
    support_email VARCHAR(255),

    -- Metadata
    provisioned_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,

    INDEX idx_status (status),
    INDEX idx_plan (plan),
    INDEX idx_subscription_end (subscription_end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### **Table: tenant_deployments**
```sql
CREATE TABLE tenant_deployments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,

    -- Deployment info
    git_commit_hash VARCHAR(40),
    git_branch VARCHAR(50),
    deployment_type ENUM('provision', 'update', 'rollback', 'hotfix') DEFAULT 'update',

    -- Status
    status ENUM('pending', 'in_progress', 'completed', 'failed', 'rolled_back') DEFAULT 'pending',
    error_message TEXT NULL,
    deployment_log LONGTEXT NULL,

    -- Timing
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    duration_seconds INT NULL,

    -- Who deployed
    deployed_by_user_id BIGINT UNSIGNED NULL,
    deployed_by_name VARCHAR(255),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_tenant_created (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### **Table: tenant_health_checks**
```sql
CREATE TABLE tenant_health_checks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,

    -- Check info
    check_type ENUM('http', 'database', 'storage', 'queue', 'ssl') NOT NULL,
    status ENUM('healthy', 'warning', 'critical', 'unknown') DEFAULT 'unknown',
    response_time_ms INT NULL,

    -- Details
    details JSON NULL,
    error_message TEXT NULL,

    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_checked_at (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### **Table: tenant_backups**
```sql
CREATE TABLE tenant_backups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,

    -- Backup info
    type ENUM('full', 'database_only', 'files_only', 'pre_deployment') DEFAULT 'full',
    backup_path VARCHAR(500),
    size_bytes BIGINT UNSIGNED,

    -- Separate paths
    database_backup_path VARCHAR(500) NULL,
    storage_backup_path VARCHAR(500) NULL,

    -- Status
    status ENUM('pending', 'in_progress', 'completed', 'failed', 'expired') DEFAULT 'pending',
    error_message TEXT NULL,

    -- Expiration
    expires_at TIMESTAMP NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_type_status (type, status),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### **Autres tables:**
- `tenant_features` → Feature flags par tenant
- `tenant_activity_logs` → Audit trail
- `saas_admins` → Admins plateforme Master
- `invoices` → Facturation

---

## 📝 SCRIPTS DE DÉPLOIEMENT

### **Script 1: TenantProvision.php (Commande Artisan)**

**Localisation:** `app/Console/Commands/TenantProvision.php`

**Usage:**
```bash
php artisan tenant:provision \
    --code=esbtp-yamoussoukro \
    --name="ESBTP Yamoussoukro" \
    --subdomain=esbtp-yamoussoukro \
    --plan=essentiel \
    --admin-email=admin@esbtp-yamoussoukro.ci \
    --environment=production
```

**Responsabilités:**
1. Valider les paramètres
2. Vérifier unicité code/subdomain
3. Générer credentials sécurisés
4. Appeler CpanelApiService pour créer BDD
5. Générer script bash de déploiement
6. Exécuter via SSH
7. Créer sous-domaine + SSL
8. Enregistrer dans Master DB
9. Health check initial
10. Notification admin

### **Script 2: CpanelApiService.php**

**Localisation:** `app/Services/CpanelApiService.php`

**Méthodes:**
```php
createDatabase(string $dbName): bool
createDatabaseUser(string $username, string $password): bool
grantPrivileges(string $user, string $database): bool
createSubdomain(string $subdomain, string $rootDomain, string $dir): bool
installSSL(string $domain): bool
```

### **Script 3: deploy-tenant.sh (Généré dynamiquement)**

**Template:** `resources/templates/deploy-tenant.sh.blade.php`

**Variables injectées:**
- `{{ $tenantCode }}`
- `{{ $tenantName }}`
- `{{ $dbName }}`
- `{{ $dbUser }}`
- `{{ $dbPassword }}`
- `{{ $subdomain }}`
- `{{ $adminEmail }}`
- `{{ $plan }}`

**Script généré exemple:**
```bash
#!/bin/bash
set -e

TENANT_CODE="esbtp-yamoussoukro"
TENANT_NAME="ESBTP Yamoussoukro"
DB_NAME="c2569688c_esbtp_yamoussoukro"
DB_USER="c2569688c_esbtp_yam_user"
DB_PASS="SECURE_GENERATED_PASSWORD"
INSTALL_DIR="/home/c2569688c/public_html/${TENANT_CODE}"

echo "🚀 Provisioning ${TENANT_NAME}..."

# 1. Vérifier que public_html existe
if [ ! -d "/home/c2569688c/public_html" ]; then
    echo "❌ /public_html n'existe pas!"
    exit 1
fi

# 2. Vérifier que le dossier tenant n'existe pas déjà
if [ -d "${INSTALL_DIR}" ]; then
    echo "❌ Le dossier ${INSTALL_DIR} existe déjà!"
    exit 1
fi

# 3. Se déplacer dans public_html AVANT le clone (CRITIQUE!)
cd /home/c2569688c/public_html/
pwd  # Afficher le répertoire courant pour debug

# 4. Clone repo avec branche spécifique
echo "📥 Cloning repository (branch: ${TENANT_CODE})..."
git clone -b ${TENANT_CODE} https://github.com/yourrepo/KLASSCIv2.git ${TENANT_CODE}

# 5. Vérifier que le clone a réussi
if [ ! -d "${INSTALL_DIR}" ]; then
    echo "❌ Le clonage a échoué!"
    exit 1
fi

# 6. Se déplacer dans le dossier du tenant
cd ${TENANT_CODE}
pwd  # Doit afficher: /home/c2569688c/public_html/esbtp-yamoussoukro

# 7. Vérifier qu'on est sur la bonne branche
CURRENT_BRANCH=$(git branch --show-current)
if [ "${CURRENT_BRANCH}" != "${TENANT_CODE}" ]; then
    echo "❌ Mauvaise branche! Attendu: ${TENANT_CODE}, Actuel: ${CURRENT_BRANCH}"
    exit 1
fi

echo "✅ Repository cloned successfully to: ${INSTALL_DIR}"
echo "✅ Current branch: ${CURRENT_BRANCH}"

# 2. Configure .env (depuis .env.production, PAS .env.example)
cp .env.production .env
sed -i "s|APP_NAME=.*|APP_NAME=\"${TENANT_NAME}\"|" .env
sed -i "s|APP_URL=.*|APP_URL=https://${TENANT_CODE}.klassci.com|" .env
sed -i "s|DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|" .env
sed -i "s|DB_USERNAME=.*|DB_USERNAME=${DB_USER}|" .env
sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|" .env
echo "" >> .env
echo "TENANT_CODE=${TENANT_CODE}" >> .env
echo "TENANT_PLAN=essentiel" >> .env

# 3. Composer install
composer install --no-dev --optimize-autoloader --no-interaction

# 4. Generate key
php artisan key:generate --force

# 5. Migrations
php artisan migrate --force

# 6. Setup scripts (PREMIER DÉPLOIEMENT UNIQUEMENT)
php fix_permissions.php
php init_storage.php
php create_storage_link.php  # Utilisé à la place de artisan storage:link
php deploy_settings.php

# 7. Cache
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 8. Permissions
chmod -R 755 storage bootstrap/cache
chown -R c2569688c:c2569688c .

echo "✅ ${TENANT_NAME} provisioned successfully!"
```

---

## 🎯 PLAN D'IMPLÉMENTATION

### **Phase 1: Setup Projet klassci-master (Local - Jour 1)**

**Objectif:** Créer la structure de base de klassci-master en local

**Actions:**
```bash
# 1. Créer projet Laravel
cd ~/workspace
composer create-project laravel/laravel klassci-master
cd klassci-master

# 2. Configurer .env local
cp .env.example .env
# Éditer DB_DATABASE=klassci_master, etc.

# 3. Créer base de données
mysql -u root -p
CREATE DATABASE klassci_master;
exit;

# 4. Git init
git init
git add .
git commit -m "Initial commit: klassci-master structure"
```

**Livrables:**
- ✅ Projet Laravel installé
- ✅ Base de données locale créée
- ✅ Git initialisé

---

### **Phase 2: Migrations & Modèles (Jour 1-2)**

**Objectif:** Créer toutes les tables et modèles Eloquent

**Actions:**
```bash
# Créer migrations
php artisan make:migration create_tenants_table
php artisan make:migration create_tenant_deployments_table
php artisan make:migration create_tenant_health_checks_table
php artisan make:migration create_tenant_backups_table
php artisan make:migration create_tenant_features_table
php artisan make:migration create_tenant_activity_logs_table
php artisan make:migration create_saas_admins_table
php artisan make:migration create_invoices_table

# Créer modèles
php artisan make:model Tenant
php artisan make:model TenantDeployment
php artisan make:model TenantHealthCheck
php artisan make:model TenantBackup
php artisan make:model TenantFeature
php artisan make:model TenantActivityLog
php artisan make:model SaasAdmin
php artisan make:model Invoice
```

**Livrables:**
- ✅ 8 migrations créées
- ✅ 8 modèles Eloquent créés
- ✅ Relations définies

---

### **Phase 3: Services & Commandes (Jour 3-4)**

**Objectif:** Créer les services et commandes Artisan

**Actions:**
```bash
# Créer services
touch app/Services/CpanelApiService.php
touch app/Services/SshDeploymentService.php
touch app/Services/TenantProvisioningService.php

# Créer commandes
php artisan make:command TenantProvision
php artisan make:command TenantDeploy
php artisan make:command TenantHealthCheck
php artisan make:command TenantBackup
php artisan make:command SaasCreateAdmin
```

**Livrables:**
- ✅ CpanelApiService (API cPanel)
- ✅ SshDeploymentService (Exécution SSH)
- ✅ TenantProvisioningService (Orchestration)
- ✅ 5 commandes Artisan

---

### **Phase 4: Interface Web Dashboard (Jour 5-7)**

**Objectif:** Créer l'interface web de gestion

**Actions:**
```bash
# Créer contrôleurs
php artisan make:controller SaaS/DashboardController
php artisan make:controller SaaS/TenantController
php artisan make:controller SaaS/DeploymentController
php artisan make:controller SaaS/MonitoringController
php artisan make:controller SaaS/BillingController

# Créer vues
mkdir -p resources/views/saas/{dashboard,tenants,deployments,monitoring,billing}

# Créer middleware auth
php artisan make:middleware AuthenticateSaasAdmin
```

**Pages à créer:**
1. **Dashboard** (`/saas/dashboard`)
   - KPI globaux (tenants actifs, étudiants total, MRR, uptime)
   - Graphiques évolution
   - Alertes récentes

2. **Tenants** (`/saas/tenants`)
   - Liste avec filtres/recherche
   - Formulaire création
   - Page détails par tenant

3. **Déploiements** (`/saas/deployments`)
   - Historique déploiements
   - Bouton "Deploy All"
   - Logs en temps réel

4. **Monitoring** (`/saas/monitoring`)
   - Health checks temps réel
   - Carte des tenants (vert/orange/rouge)
   - Graphiques performance

5. **Billing** (`/saas/billing`)
   - Factures générées
   - Plans tarifaires
   - Statistiques MRR

**Livrables:**
- ✅ 5 contrôleurs
- ✅ 20+ vues Blade
- ✅ Middleware auth
- ✅ Routes web définies

---

### **Phase 5: Tests Provisioning Local (Jour 8)**

**Objectif:** Tester le provisioning complet en local

**Actions:**
```bash
# Tester création tenant local
php artisan tenant:provision \
    --code=test-local \
    --name="Test Local" \
    --subdomain=test-local \
    --plan=free \
    --admin-email=test@local.com \
    --environment=local

# Vérifier BDD créée
mysql -u root -p
SHOW DATABASES LIKE 'klassci_test_local';
exit;

# Vérifier dossier créé
ls -la ~/workspace/tenants/test-local/

# Tester accès web
# Configurer /etc/hosts: 127.0.0.1 test-local.localhost
curl http://test-local.localhost:8000
```

**Livrables:**
- ✅ Provisioning local fonctionnel
- ✅ Tenant test créé
- ✅ Bugs corrigés

---

### **Phase 6: Intégration API cPanel (Jour 9-10)**

**Objectif:** Intégrer l'API cPanel pour création BDD/subdomain/SSL

**Configuration .env Master:**
```env
# cPanel API
CPANEL_URL=https://web44.lws-hosting.com:2083
CPANEL_USERNAME=c2569688c
CPANEL_API_TOKEN=YOUR_API_TOKEN_HERE

# SSH
SSH_HOST=web44.lws-hosting.com
SSH_PORT=22
SSH_USER=c2569688c
SSH_KEY_PATH=/home/user/.ssh/id_rsa

# Tenant defaults
TENANT_DB_PREFIX=c2569688c_
TENANT_USER_PREFIX=c2569688c_
TENANT_INSTALL_DIR=/home/c2569688c/public_html/
```

**Tests:**
```bash
# Tester création BDD via API
php artisan tinker
>>> app(\App\Services\CpanelApiService::class)->createDatabase('test_db');

# Tester création subdomain via API
>>> app(\App\Services\CpanelApiService::class)->createSubdomain('test', 'klassci.com', '/home/c2569688c/public_html/test/public');
```

**Livrables:**
- ✅ API cPanel intégrée
- ✅ Tests unitaires passent
- ✅ Documentation credentials

---

### **Phase 7: Déploiement klassci-master en Production (Jour 11)**

**Objectif:** Déployer klassci-master sur le serveur

**Actions:**
```bash
# 1. SSH vers serveur
ssh c2569688c@web44.lws-hosting.com

# 2. Créer dossier
cd /home/c2569688c/public_html/
git clone https://github.com/yourrepo/klassci-master.git
cd klassci-master

# 3. Configuration
cp .env.example .env
# Éditer .env avec credentials production

# 4. Créer BDD Master via cPanel
# Via interface web cPanel: créer c2569688c_klassci_master

# 5. Composer install
composer install --no-dev --optimize-autoloader

# 6. Migrations
php artisan migrate --force

# 7. Créer admin SaaS
php artisan saas:create-admin \
    --name="Marcel Dev" \
    --email="admin@klassci.com" \
    --password="SECURE_PASSWORD"

# 8. Configurer subdomain admin.klassci.com via cPanel
# Pointer vers /home/c2569688c/public_html/klassci-master/public

# 9. SSL via AutoSSL cPanel
# Activer AutoSSL pour admin.klassci.com
```

**Livrables:**
- ✅ klassci-master en production
- ✅ Accessible via https://admin.klassci.com
- ✅ Admin créé

---

### **Phase 8: Import Tenants Existants (Jour 12)**

**Objectif:** Importer les 3 tenants existants dans Master DB

**Actions:**
```bash
# Via interface web klassci-master
https://admin.klassci.com/saas/tenants/import

# Formulaire import pour chaque tenant:
# 1. ESBTP Abidjan
Code: esbtp-abidjan
Name: ESBTP Abidjan
Subdomain: esbtp-abidjan
Database: c2569688c_esbtp_abidjan (déjà existante)
Plan: professional
Subscription End: 2026-10-11
Max Users: 30
Max Inscriptions: 3000

# 2. ESBTP Yakro
Code: esbtp-yakro
Name: ESBTP Yakro
Subdomain: esbtp-yakro
Database: c2569688c_esbtp_yakro (déjà existante)
Plan: essentiel
Subscription End: 2025-10-18
Max Users: 20
Max Inscriptions: 700

# 3. Presentation
Code: presentation
Name: Test Présentation
Subdomain: presentation
Database: c2569688c_smart_school (déjà existante)
Plan: free
Max Users: 5
Max Inscriptions: 50
```

**Livrables:**
- ✅ 3 tenants importés
- ✅ Fichiers .tenant.json créés
- ✅ Dashboard affiche les 3 tenants

---

### **Phase 9: Tests Provisioning Production (Jour 13)**

**Objectif:** Tester provisioning complet d'un nouveau tenant en production

**Actions:**
```bash
# Via interface web
https://admin.klassci.com/saas/tenants/create

# Formulaire test:
Code: test-prod
Name: Test Production
Subdomain: test-prod
Plan: free
Admin Email: test@klassci.com

# Cliquer "Provisionner"

# Attendre 2-3 minutes

# Vérifier:
1. BDD créée: c2569688c_test_prod ✅
2. Dossier créé: /home/c2569688c/public_html/test-prod ✅
3. Subdomain actif: https://test-prod.klassci.com ✅
4. SSL installé ✅
5. Application fonctionnelle ✅
6. Email reçu par test@klassci.com ✅
```

**Livrables:**
- ✅ Provisioning production validé
- ✅ Tenant test fonctionnel
- ✅ Tous les checks passent

---

### **Phase 10: Monitoring & Health Checks (Jour 14)**

**Objectif:** Activer le monitoring automatique

**Actions:**
```bash
# Créer commande health check
php artisan make:command TenantHealthCheckScheduled

# Configurer scheduler (app/Console/Kernel.php)
$schedule->command('tenant:health-check --all')->everyFiveMinutes();
$schedule->command('tenant:backup --all')->dailyAt('02:00');
$schedule->command('tenant:update-stats --all')->hourly();

# Activer cron sur serveur
crontab -e
# Ajouter:
* * * * * cd /home/c2569688c/public_html/klassci-master && php artisan schedule:run >> /dev/null 2>&1
```

**Livrables:**
- ✅ Health checks automatiques toutes les 5 min
- ✅ Backups quotidiens
- ✅ Stats mises à jour toutes les heures
- ✅ Notifications si tenant down

---

### **Phase 11: Documentation & Formation (Jour 15)**

**Objectif:** Documenter et former à l'utilisation

**Livrables:**
- ✅ Documentation admin classci-master
- ✅ Guide provisioning
- ✅ Guide dépannage
- ✅ Vidéo démo

---

### **Phase 12: Go Live (Jour 16)**

**Objectif:** Mise en production finale

**Actions:**
1. Désactiver mode debug
2. Configurer emails production
3. Tests finaux
4. Annonce go live

**Livrables:**
- ✅ Système 100% opérationnel
- ✅ Monitoring actif
- ✅ Support prêt

---

## 📊 RÉCAPITULATIF

**Durée totale:** 16 jours

**Provisioning d'un nouveau tenant:**
- Temps: 2-3 minutes
- Automatique: 95%
- Manuel: 5% (validation formulaire web)

**Avantages:**
- ✅ Provisioning ultra-rapide
- ✅ Zero downtime deployments
- ✅ Monitoring temps réel
- ✅ Scalable à 100+ écoles
- ✅ Gestion centralisée

---

**Prêt à commencer ? 🚀**
