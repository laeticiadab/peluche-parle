# Pont SMS Hostinger → Service Paiement StudioATY

**Branche de préparation uniquement, non déployée.** Le webhook `index.php` déjà fonctionnel continue de stocker les SMS et ne doit pas être remplacé.

Le fichier `payment-forwarder.php` lit les nouveaux messages stockés dans la base SQLite privée, puis les transmet par HTTP POST **HMAC-SHA256** au moteur SMS `service-paiement`. Il ne sert jamais de page web et n'a pas d'URL publique utilisable.

## Pré-requis
- PHP CLI 8.3+ avec PDO SQLite et cURL.
- Service de paiement de recette déployé, migrations exécutées, terminal HMAC créé depuis `/admin/relais`.
- Identifiant de SIM réellement transmis par l'Android confirmé dans la rubrique `/admin/sms`.
- Compte Mobile Money activé et lié au numéro de terminal dans l'administration centrale.

## Configuration (en dehors du dépôt)
Fournir les 4 variables à la tâche cron Hostinger :
- `STUDIOATY_PAIEMENT_RELAIS_URL=https://.../api/relais/v1/sms`
- `STUDIOATY_PAIEMENT_SMS_START_ID=identifiant_numerique_exclusif` (les SMS synchronisés doivent avoir un ID strictement supérieur)
- Pour une seule SIM : `STUDIOATY_PAIEMENT_RELAIS_TERMINAL=tel_...` et `STUDIOATY_PAIEMENT_RELAIS_SECRET=secret_hmac_du_terminal`
- Pour plusieurs SIM : `STUDIOATY_PAIEMENT_SIM_CONFIG_JSON={"SIM1":{"terminal":"tel_...","secret":"..."},"SIM2":{"terminal":"tel_...","secret":"..."}}` ; chaque SIM doit avoir un terminal et un secret HMAC distincts.

Ne pas mettre le secret dans le crontab visible, les journaux, l'URL ou le dépôt GitHub. Utiliser la configuration de variables de l'hébergement ou un mécanisme de secret équivalent. Ne pas réutiliser la clé d'une boutique.

## Fonctionnement
- Lecture de la base existante en privé, 20 SMS maximum par exécution.
- UUID stable dérivé de l'identifiant du terminal + identifiant du SMS : la reprise ne crée pas un nouvel événement métier.
- Signature du corps exact envoyé : horodatage + méthode + chemin + corps, selon le contrat du Relais.
- Le champ `sim` présent dans le SMS Forwarder est conservé, sans être considéré fiable à lui seul.
- Réponse 200/201 et JSON `ok:true` nécessaires à la marque de transmission. Sinon nouvelle tentative toutes les 15 minutes, sans abandon automatique. Chaque exécution traite jusqu'à 15 nouveaux SMS et réserve jusqu'à 5 places aux reprises (jusqu'à 20 reprises si aucun nouveau SMS), pour éviter qu'une panne ancienne bloque les nouveaux messages.
- Un verrou de fichier évite deux tâches concurrentes. Le journal n'expose ni SMS ni secret. Une SIM inconnue reste en attente et n'est jamais envoyée sous l'identité d'une autre SIM.
- **Aucun débit, validation financière ou changement d'état de commande n'est déclenché par le script PHP**.

## Recette avant activation
1. Comparer le total des SMS livrés et le total des SMS reçus par le moteur, sur un petit jeu fictif.
2. Vérifier la SIM, l'expéditeur autorisé, l'authentification HMAC, les doublons et une coupure réseau.
3. Confirmer que le récepteur existant reste opérationnel sans le script.
4. Ne planifier le cron réel qu'après validation du moteur et de l'environnement de recette.
