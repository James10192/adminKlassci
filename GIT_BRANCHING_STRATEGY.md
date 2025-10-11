# 🔀 STRATÉGIE GIT MULTI-BRANCHES - KLASSCI

**Date:** 11 octobre 2025
**Convention:** Chaque tenant = 1 branche Git dédiée

---

## 📊 BRANCHES EXISTANTES

```bash
git branch -r
```

**Output actuel:**
```
origin/HEAD → origin/presentation   # Branche par défaut (main)
origin/IFRAN                        # Tenant IFRAN (uppercase)
origin/IfranModif                   # Branch de développement IFRAN
origin/esbt-abidjan                 # Typo (obsolète, remplacé par esbtp-abidjan)
origin/esbtp-abidjan               # ✅ Tenant ESBTP Abidjan (PRODUCTION)
origin/esbtp-yakro                 # ✅ Tenant ESBTP Yakro (PRODUCTION)
origin/modif                       # Branch générale de développement
origin/presentation                # ✅ Tenant test (branch HEAD)
```

---

## 🎯 ÉTAPES DE PROVISIONING AVEC BRANCHE GIT

### **Étape 0: Préparation (Locale ou sur Master)**

Avant de provisionner un nouveau tenant, **créer d'abord sa branche Git** :

```bash
# 1. Clone du repo principal (si pas déjà fait)
git clone https://github.com/yourrepo/KLASSCIv2.git
cd KLASSCIv2

# 2. Se placer sur la branche de base (presentation)
git checkout presentation
git pull origin presentation

# 3. Créer la branche pour le nouveau tenant
git checkout -b esbtp-yamoussoukro

# 4. Push vers remote
git push -u origin esbtp-yamoussoukro

# Résultat: Branch origin/esbtp-yamoussoukro créée
```

### **Étape 1: Clonage avec branche spécifique (SSH sur serveur)**

**⚠️ IMPORTANT:** Le `git clone` clone dans le **répertoire courant (pwd)**. Il faut donc **SE DÉPLACER dans public_html AVANT** de cloner !

```bash
ssh c2569688c@web44.klassci.com << 'EOF'
# 1. Se déplacer dans public_html AVANT le clone (CRITIQUE!)
cd /home/c2569688c/public_html/

# 2. Vérifier qu'on est au bon endroit
pwd  # DOIT afficher: /home/c2569688c/public_html

# 3. Clone avec la branche du tenant
git clone -b esbtp-yamoussoukro https://github.com/yourrepo/KLASSCIv2.git esbtp-yamoussoukro

# 4. Vérifier que le dossier a été créé au bon endroit
ls -la esbtp-yamoussoukro/  # Doit lister les fichiers Laravel

# 5. Se déplacer dans le dossier du tenant
cd esbtp-yamoussoukro

# 6. Vérifier qu'on est bien sur la bonne branche
git branch --show-current  # Doit afficher: esbtp-yamoussoukro

EOF
```

**✅ Résultat garanti:** Le dossier est créé à `/home/c2569688c/public_html/esbtp-yamoussoukro/`

---

## 🛠️ WORKFLOW COMPLET

### **Nouveau tenant: esbtp-yamoussoukro**

```bash
# === SUR MACHINE LOCALE OU MASTER ===
cd /path/to/KLASSCIv2
git checkout presentation
git checkout -b esbtp-yamoussoukro
git push -u origin esbtp-yamoussoukro

# === SUR SERVEUR PRODUCTION VIA SSH ===
# IMPORTANT: Se déplacer dans public_html AVANT le clone
cd /home/c2569688c/public_html/
pwd  # Vérifier: /home/c2569688c/public_html
git clone -b esbtp-yamoussoukro https://github.com/yourrepo/KLASSCIv2.git esbtp-yamoussoukro
```

### **Résultat:**
```
/home/c2569688c/public_html/
├── esbtp-abidjan/          → branch: origin/esbtp-abidjan
├── esbtp-yakro/            → branch: origin/esbtp-yakro
├── esbtp-yamoussoukro/     → branch: origin/esbtp-yamoussoukro ✨ NOUVEAU
├── presentation/           → branch: origin/presentation
└── ifran/                  → branch: origin/IFRAN
```

---

## 📦 PERSONNALISATIONS PAR BRANCHE

Chaque branche tenant contient ses propres personnalisations :

### **Fichiers spécifiques par tenant:**

**`origin/esbtp-abidjan`:**
- `database/seeders/ESBTPAbidjanMatriculeConfigSeeder.php`
- `storage/app/public/logos/esbtp-abidjan-logo.png`
- Configurations spécifiques ESBTP Abidjan

**`origin/esbtp-yakro`:**
- `database/seeders/ESBTPYakroMatriculeConfigSeeder.php` (probablement)
- `storage/app/public/logos/esbtp-yakro-logo.png`
- Configurations spécifiques ESBTP Yakro

**`origin/esbtp-yamoussoukro` (nouveau):**
- Créer `database/seeders/ESBTPYamoussoukroMatriculeConfigSeeder.php` après provisioning
- Upload logo spécifique via interface
- Personnalisations futures

---

## 🔄 MISE À JOUR GLOBALE (MERGE DEPUIS PRESENTATION)

Quand une nouvelle feature est développée dans `origin/presentation`, elle peut être **mergée** dans toutes les branches tenant :

```bash
# Merge feature globale vers esbtp-abidjan
git checkout esbtp-abidjan
git pull origin esbtp-abidjan
git merge origin/presentation
# Résoudre conflits si nécessaire
git push origin esbtp-abidjan

# Répéter pour chaque tenant
git checkout esbtp-yakro
git merge origin/presentation
git push origin esbtp-yakro

# etc.
```

**Automatisation possible** : Commande Artisan `tenant:deploy --all` qui merge automatiquement depuis `presentation` vers toutes les branches tenant.

---

## ⚠️ POINTS D'ATTENTION

### **1. Ne jamais merger tenant → presentation**
```bash
# ❌ INTERDIT
git checkout presentation
git merge origin/esbtp-abidjan  # NE JAMAIS FAIRE ÇA !
```

**Raison:** Les personnalisations d'un tenant ne doivent JAMAIS polluer la branche principale.

### **2. Seeders de matricules par tenant**

Chaque tenant a **sa propre nomenclature de matricules** :
- ESBTP Abidjan : `MESBTP25-0001`, `FESBTP25-0001`
- ESBTP Yakro : Format différent (à définir par eux)
- ESBTP Yamoussoukro : Format à définir lors du provisioning

**Emplacement:**
```
database/seeders/
├── ESBTPAbidjanMatriculeConfigSeeder.php      # Branch esbtp-abidjan uniquement
├── ESBTPYakroMatriculeConfigSeeder.php        # Branch esbtp-yakro uniquement
└── ESBTPYamoussoukroMatriculeConfigSeeder.php # Branch esbtp-yamoussoukro uniquement
```

### **3. Gestion des conflits lors du merge**

Fichiers à risque de conflits lors d'un merge global :
- `config/*.php` - Configurations
- `resources/views/layouts/app.blade.php` - Layout principal
- `routes/web.php` - Routes

**Solution:** Utiliser des **feature flags** ou **tenant-specific configs** pour éviter les conflits.

---

## 📝 CHECKLIST PROVISIONING

Lors du provisioning d'un nouveau tenant `esbtp-yamoussoukro` :

- [ ] **Étape 0 : Créer branche Git**
  ```bash
  git checkout presentation
  git checkout -b esbtp-yamoussoukro
  git push -u origin esbtp-yamoussoukro
  ```

- [ ] **Étape 1 : Créer BDD** via API cPanel
  - `c2569688c_esbtp_yamoussoukro`

- [ ] **Étape 2 : Cloner avec branche** (depuis /home/c2569688c/public_html/)
  ```bash
  cd /home/c2569688c/public_html/  # SE DÉPLACER DANS public_html AVANT !
  pwd  # Vérifier le répertoire
  git clone -b esbtp-yamoussoukro https://github.com/yourrepo/KLASSCIv2.git esbtp-yamoussoukro
  ```

- [ ] **Étape 3 : Configuration .env**
  - Copier depuis `.env.production`

- [ ] **Étape 4 : Composer + Migrations**

- [ ] **Étape 5 : Scripts setup**
  - fix_permissions.php
  - init_storage.php
  - create_storage_link.php
  - deploy_settings.php

- [ ] **Étape 6 : Création fichier `.tenant.json`**
  ```json
  {
    "code": "esbtp-yamoussoukro",
    "git_branch": "esbtp-yamoussoukro",
    ...
  }
  ```

- [ ] **Étape 7 : Sous-domaine + SSL** via API cPanel

- [ ] **Étape 8 : APRÈS provisioning - Créer seeder matricule personnalisé**
  - Demander au client le format souhaité
  - Créer `ESBTPYamoussoukroMatriculeConfigSeeder.php`
  - Commit dans la branche `origin/esbtp-yamoussoukro`
  - Exécuter manuellement : `php artisan db:seed --class=ESBTPYamoussoukroMatriculeConfigSeeder`

---

## 🔧 COMMANDE ARTISAN POUR CRÉATION DE BRANCHE

**Suggestion:** Intégrer la création de branche dans `TenantProvision` :

```php
// app/Console/Commands/TenantProvision.php

protected function createGitBranch(string $tenantCode): bool
{
    $this->info("Creating Git branch for tenant: {$tenantCode}");

    // Chemin vers le repo local KLASSCIv2
    $repoPath = config('tenant.git_repo_path');  // ~/workspace/KLASSCIv2

    $commands = [
        "cd {$repoPath}",
        "git checkout presentation",
        "git pull origin presentation",
        "git checkout -b {$tenantCode}",
        "git push -u origin {$tenantCode}",
    ];

    $command = implode(' && ', $commands);
    exec($command . ' 2>&1', $output, $returnCode);

    if ($returnCode === 0) {
        $this->info("✅ Branch origin/{$tenantCode} created successfully!");
        return true;
    } else {
        $this->error("❌ Failed to create Git branch");
        $this->error(implode("\n", $output));
        return false;
    }
}
```

**Configuration .env Master:**
```env
# Git configuration
GIT_REPO_PATH=/path/to/KLASSCIv2
GIT_REMOTE_URL=https://github.com/yourrepo/KLASSCIv2.git
GIT_BASE_BRANCH=presentation
```

---

## 📊 RÉCAPITULATIF

**Structure actuelle:**
```
presentation (main) ← Nouvelles features globales
    ↓ merge
├── esbtp-abidjan (personnalisations ESBTP Abidjan)
├── esbtp-yakro (personnalisations ESBTP Yakro)
├── esbtp-yamoussoukro (nouveau tenant)
└── IFRAN (personnalisations IFRAN)
```

**Avantages:**
- ✅ Isolation complète des personnalisations
- ✅ Déploiements indépendants
- ✅ Rollback par tenant
- ✅ Hotfixes ciblés
- ✅ Historique clair

**Prêt à provisionner avec branches Git ! 🚀**
