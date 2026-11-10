# PairPlay — prototype de couplage TV ↔ téléphone

Prototype indépendant sur la branche `prototype-tv-pairing-hostinger` ; le contenu de la branche principale de `peluche-parle` n'est pas modifié.

## Objectif
- URL sur la TV : `?mode=tv` ; code temporaire et QR.
- URL sur le téléphone : `?mode=phone` ; code ou scan QR.
- API PHP `api.php` pour associer les deux navigateurs et transmettre les commandes A, B, haut, bas, gauche, droite et START.
- Association mémorisée dans localStorage de chaque navigateur : aucun e-mail ni mot de passe sur la TV.
- L'état est conservé dans un dossier privé au-dessus de `public_html`.

## Tests
1. Ouvrir `?mode=tv` sur un écran, noter le code à 6 chiffres.
2. Ouvrir `?mode=phone` sur un autre navigateur et saisir le code.
3. Vérifier que la TV affiche « Téléphone associé » et reçoit A/B/flèches.
4. Recharger les deux pages et vérifier la reconnexion automatique.
5. Cliquer sur « Générer un nouveau code » sur la TV pour révoquer l'ancienne manette.
6. GET `api.php?action=health` doit retourner un JSON `ok: true`.

## Limites
- La page TV doit être ouverte : un site web ne peut pas lancer arbitrairement un navigateur TV ni réveiller toute télévision.
- Pas de jeu, pas de streaming, pas de Cast natif à ce stade.
- QR code généré localement dans le navigateur via une bibliothèque chargée depuis jsDelivr ; si elle ne se charge pas, le code manuel continue de fonctionner.
- Prototype de validation : avant tout déploiement commercial, ajouter des tests automatisés, supervision, limitation de débit plus élaborée, nettoyage périodique des sessions et protection anti-abus.
