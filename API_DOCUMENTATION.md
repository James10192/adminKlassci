# API REST Master - Documentation

## Vue d'ensemble

L'API REST Master permet aux applications tenant d'interroger de manière centralisée leurs limites d'abonnement, leur utilisation actuelle et les fonctionnalités bloquées.

**Base URL Production :** `https://admin.klassci.com/api`
**Base URL Développement :** `http://localhost:8001/api`

## Authentification

L'API utilise un système de token Bearer personnalisé. Chaque tenant possède un token API unique stocké dans la table `tenants`.

### Obtenir un token

```bash
php artisan tenant:generate-token {code} [--regenerate]
```

**Exemples :**
```bash
# Générer un token pour le tenant 'esbtp-abidjan'
php artisan tenant:generate-token esbtp-abidjan

# Régénérer un token existant (invalide l'ancien)
php artisan tenant:generate-token esbtp-abidjan --regenerate
```

### Méthodes d'authentification

**Option 1 : Header Authorization (recommandé)**
```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
  https://admin.klassci.com/api/tenants/{code}/limits
```

**Option 2 : Paramètre query**
```bash
curl "https://admin.klassci.com/api/tenants/{code}/limits?token=YOUR_TOKEN"
```

## Endpoints

### GET /api/tenants/{code}/limits

Récupère les limites d'abonnement et l'utilisation actuelle d'un tenant.

#### Paramètres URL

- `code` (string, requis) : Code unique du tenant (ex: "esbtp-abidjan", "presentation")

#### Headers

- `Authorization: Bearer {token}` (requis si pas de paramètre query)

#### Paramètres Query (optionnels)

- `token` (string) : Alternative au header Authorization

#### Réponse succès (200 OK)

```json
{
  "tenant_code": "presentation",
  "tenant_name": "Test Présentation",
  "plan": "free",
  "status": "active",
  "subscription": {
    "start_date": "2025-10-11",
    "end_date": "2026-10-11",
    "is_expired": false,
    "days_remaining": 364.09
  },
  "limits": {
    "max_users": 5,
    "max_staff": 5,
    "max_students": 50,
    "max_inscriptions_per_year": 50,
    "max_storage_mb": 512
  },
  "current_usage": {
    "users": 1,
    "staff": 1,
    "students": 3,
    "inscriptions_per_year": 0,
    "storage_mb": 0
  },
  "usage_percentage": {
    "users": 20,
    "staff": 20,
    "students": 6,
    "inscriptions": 0,
    "storage": 0
  },
  "quota_status": {
    "is_over_quota": false,
    "users_over_limit": false,
    "staff_over_limit": false,
    "students_over_limit": false,
    "inscriptions_over_limit": false,
    "storage_over_limit": false
  },
  "blocked_features": [],
  "last_stats_update": "2025-10-11T21:43:13+00:00"
}
```

#### Réponses d'erreur

**401 Unauthorized - Token manquant**
```json
{
  "error": "Unauthorized",
  "message": "API token is required. Provide it via Authorization: Bearer {token} header or ?token={token} query parameter."
}
```

**401 Unauthorized - Token invalide**
```json
{
  "error": "Unauthorized",
  "message": "Invalid API token"
}
```

**404 Not Found - Tenant inexistant**
```json
{
  "error": "Tenant not found",
  "message": "No tenant found with code: inexistant"
}
```

## Structure des données

### Champ `subscription`

- `start_date` (string|null) : Date de début d'abonnement (format YYYY-MM-DD)
- `end_date` (string|null) : Date de fin d'abonnement (format YYYY-MM-DD)
- `is_expired` (boolean) : Indique si l'abonnement est expiré
- `days_remaining` (float|null) : Nombre de jours restants (négatif si expiré)

### Champ `limits`

Limites maximales définies par le plan d'abonnement :

- `max_users` (integer) : Nombre maximum d'utilisateurs (staff)
- `max_staff` (integer) : Nombre maximum de personnel (enseignant, coordinateur, secretaire)
- `max_students` (integer) : Nombre maximum d'étudiants avec compte utilisateur
- `max_inscriptions_per_year` (integer) : Nombre maximum d'inscriptions par année universitaire
- `max_storage_mb` (integer) : Espace de stockage maximum en Mo

### Champ `current_usage`

Utilisation actuelle du tenant :

- `users` (integer) : Nombre actuel d'utilisateurs (staff)
- `staff` (integer) : Nombre actuel de personnel avec compte
- `students` (integer) : Nombre actuel d'étudiants avec user_id (compte plateforme)
- `inscriptions_per_year` (integer) : Nombre d'inscriptions actives pour l'année universitaire courante
- `storage_mb` (integer) : Espace de stockage utilisé en Mo

### Champ `usage_percentage`

Pourcentages d'utilisation par rapport aux limites :

- `users` (float) : Pourcentage d'utilisateurs utilisés (0-100+)
- `staff` (float) : Pourcentage de personnel utilisé (0-100+)
- `students` (float) : Pourcentage d'étudiants utilisés (0-100+)
- `inscriptions` (float) : Pourcentage d'inscriptions utilisées (0-100+)
- `storage` (float) : Pourcentage de stockage utilisé (0-100+)

**Note :** Les pourcentages peuvent dépasser 100% si la limite est dépassée.

### Champ `quota_status`

États booléens des limites :

- `is_over_quota` (boolean) : Indique si au moins une limite est dépassée
- `users_over_limit` (boolean) : Limite d'utilisateurs dépassée
- `staff_over_limit` (boolean) : Limite de personnel dépassée
- `students_over_limit` (boolean) : Limite d'étudiants dépassée
- `inscriptions_over_limit` (boolean) : Limite d'inscriptions dépassée
- `storage_over_limit` (boolean) : Limite de stockage dépassée

### Champ `blocked_features`

Liste des fonctionnalités bloquées en fonction du quota et de l'expiration :

Valeurs possibles :
- `"create_user"` : Création de nouveaux utilisateurs bloquée
- `"create_staff"` : Création de nouveaux membres du personnel bloquée
- `"create_student_account"` : Création de comptes étudiants bloquée
- `"create_inscription"` : Création d'inscriptions bloquée
- `"create_reinscription"` : Création de réinscriptions bloquée
- `"upload_file"` : Upload de fichiers bloqué

**Exemple :**
```json
"blocked_features": ["create_user", "create_staff", "create_inscription"]
```

## Plans d'abonnement

### Free
```json
{
  "max_users": 5,
  "max_staff": 5,
  "max_students": 50,
  "max_inscriptions_per_year": 50,
  "max_storage_mb": 512
}
```

### Essentiel
```json
{
  "max_users": 20,
  "max_staff": 20,
  "max_students": 500,
  "max_inscriptions_per_year": 700,
  "max_storage_mb": 2048
}
```

### Professional
```json
{
  "max_users": 30,
  "max_staff": 30,
  "max_students": 2000,
  "max_inscriptions_per_year": 3000,
  "max_storage_mb": 5120
}
```

### Elite
```json
{
  "max_users": 999999,
  "max_staff": 999999,
  "max_students": 999999,
  "max_inscriptions_per_year": 999999,
  "max_storage_mb": 20480
}
```

## Exemples d'intégration

### PHP avec Guzzle

```php
use GuzzleHttp\Client;

$client = new Client([
    'base_uri' => 'https://admin.klassci.com/api',
    'timeout' => 10.0,
]);

try {
    $response = $client->get('/tenants/' . config('app.tenant_code') . '/limits', [
        'headers' => [
            'Authorization' => 'Bearer ' . config('services.master.api_token'),
            'Accept' => 'application/json',
        ],
    ]);

    $data = json_decode($response->getBody(), true);

    if ($data['quota_status']['is_over_quota']) {
        // Afficher message de dépassement de quota
        $blockedFeatures = $data['blocked_features'];
    }
} catch (\Exception $e) {
    // Fallback vers système local si API inaccessible
    \Log::error('Master API unreachable: ' . $e->getMessage());
}
```

### PHP avec Laravel HTTP Client

```php
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

$limits = Cache::remember('tenant_limits', 300, function () {
    $response = Http::withToken(config('services.master.api_token'))
        ->timeout(10)
        ->get(config('services.master.api_url') . '/tenants/' . config('app.tenant_code') . '/limits');

    if ($response->successful()) {
        return $response->json();
    }

    // Fallback vers système local
    return null;
});

if ($limits && $limits['quota_status']['is_over_quota']) {
    // Bloquer certaines fonctionnalités
}
```

### JavaScript avec fetch

```javascript
const MASTER_API_URL = 'https://admin.klassci.com/api';
const TENANT_CODE = 'esbtp-abidjan';
const API_TOKEN = 'YOUR_TOKEN';

async function getTenantLimits() {
  try {
    const response = await fetch(`${MASTER_API_URL}/tenants/${TENANT_CODE}/limits`, {
      headers: {
        'Authorization': `Bearer ${API_TOKEN}`,
        'Accept': 'application/json',
      },
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    const data = await response.json();
    console.log('Limites tenant:', data);

    if (data.quota_status.is_over_quota) {
      console.warn('Quota dépassé !', data.blocked_features);
    }

    return data;
  } catch (error) {
    console.error('Erreur API Master:', error);
    return null;
  }
}
```

## Mise en cache

**Recommandations :**

1. **Cache côté client :** Mettre en cache la réponse API pendant 5 minutes pour réduire les appels
2. **Fallback local :** En cas d'échec de l'API, utiliser le système de vérification local basé sur `esbtp_system_settings`
3. **Invalidation :** Vider le cache lors de changements critiques (création inscription, paiement validé)

**Exemple Laravel :**
```php
$limits = Cache::remember('tenant_limits', 300, function () {
    return Http::withToken(config('services.master.api_token'))
        ->get(config('services.master.api_url') . '/tenants/' . config('app.tenant_code') . '/limits')
        ->json();
});
```

## Rate Limiting

Actuellement, aucune limite de taux n'est appliquée. Une limite future pourrait être :
- **120 requêtes/heure par tenant** (moyenne : 1 requête toutes les 30 secondes)

## Support

- **Email :** support@klassci.com
- **Documentation complète :** [CLAUDE.md](./CLAUDE.md)
- **Migration guide :** Voir Phase 4 - Part C dans CLAUDE.md

## Changelog

### Version 1.0.0 (11 octobre 2025)
- Version initiale de l'API
- Endpoint GET /api/tenants/{code}/limits
- Authentification par token Bearer ou query parameter
- Support des 4 plans (Free, Essentiel, Professional, Elite)
- Calcul automatique des blocked_features basé sur le quota et l'expiration
- Distinction entre students (avec compte) et inscriptions (année courante)
