# Incidents techniques centralisés — adminKLASSCI

L'écran **Surveillance → Incidents techniques** affiche les erreurs des instances par établissement, référence \`X-Request-ID\`, route, catégorie, SQLSTATE/errno non sensibles et statut de traitement. Il ne remplace pas les sondes de santé ni les tickets de support.

## Flux

- Instance authentifiée par son identifiant Care **portée \`telemetry:send\`**, jamais par l'API token historique, en-tête Authorization Bearer.
- POST \`/api/v1/support/incidents\` avec uniquement \`request_id\`, \`route_name\`, \`exception_class\`, \`category\`, \`method\`, \`http_status\`, \`sqlstate\` et \`db_errno\`. Pas de requête SQL, message d'exception, stacktrace, URL avec paramètres, corps de formulaire, adresse ni identité étudiante.
- Identifiant \`(tenant_id, request_id)\` unique ; le même événement réémis renvoie HTTP 200, le premier HTTP 201.
- L'instance et le tenant central doivent être déployés séparément. Mettre d'abord adminKlassci à jour (base comprise), puis émettre un nouveau credential Care avec \`telemetry:send\` et activer la télémétrie dans la configuration de l'instance. Ne pas révoquer un credential existant sans plan de rotation.
- Les erreurs côté école se conservent dans ses logs privés, leur identifiant est la seule passerelle vers le panneau central. Un incident ne déclenche jamais automatiquement une migration : les modifications SQL doivent être sauvegardées et opérées par instance.

## Incident pilote

ESBTP Abidjan, 2026-10-29 : la migration \`2026_10_29_110000_add_email_delivery_to_esbtp_annonces.php\` n'avait pas été appliquée après synchronisation Git, causant SQLSTATE 42S22 / errno 1054 sur \`esbtp.annonces.store\`. La page de création fonctionne après \`composer install\` ; les migrations sont une étape distincte.

Cf. \`KLASSCIv2#1710\` et \`adminKlassci#142\`.
