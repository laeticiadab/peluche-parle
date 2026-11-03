# BBbear · Geo AR Lab — prototype indépendant

## Cible d'intégration

Dépôt recommandé : `laeticiadab/peluche-parle`, nouvelle page `geo-ar.html` à la racine, distincte du site BBbear et de sa démo vocale. Ce prototype n'exige **aucune modification de `index.html`** et ne doit pas être déployé en production sans revue.

## Fonctions

- Carte OpenStreetMap/Leaflet, sélection d'emplacement ;
- 3 objets 3D intégrés (ours, cube et totem) en Three.js ;
- import d'un modèle GLB **via URL HTTPS publique autorisant CORS** ;
- coordonnées, taille, hauteur relative, suppression ;
- stockage local navigateur ; export/import JSON pour transporter une collection ;
- caméra arrière + GPS + orientation du smartphone ;
- ajustement manuel du cap (indispensable selon le navigateur).

## Démarrage

Servir le dossier avec HTTPS, ou localhost pour les essais sur ordinateur. Par exemple : `python3 -m http.server 8000` puis ouvrir `http://localhost:8000/geo-ar.html`. Sur GitHub Pages, publier `geo-ar.html` dans la racine du site et ouvrir `https://laeticiadab.github.io/peluche-parle/geo-ar.html` **après publication**.

Activer caméra et géolocalisation sur smartphone. Ajouter un objet à proximité, puis entrer en mode AR. Sur iOS, le navigateur demandera également la permission des capteurs d'orientation. Le fonctionnement dépend des autorisations et appareils.

## Limites importantes

Il s'agit d'une **simulation AR géolocalisée de niveau prototype** : pas de VPS, pas de SLAM, pas de détection du sol, pas d'occlusion et pas de précision de placement garantie. L'alignement de la caméra et de Three.js dépend du champ de vision et de l'orientation des capteurs. La boussole Android peut être relative ; le curseur de cap sert à ajuster manuellement. L'altitude est relative et approximative. Les modèles GLB peuvent ne pas être homogènes en échelle/origine.

Le système ne partage pas les objets entre personnes : `localStorage` local seulement. Les coordonnées sont communiquées aux services de cartographie lors de l'affichage des tuiles. Les fichiers 3D externes sont téléchargés de leur hébergeur.

## Avant mise en production

1. Vérifier les conditions de licence et politique de tuiles OSM ; choisir un fournisseur adapté au trafic.
2. Ajouter API authentifiée et base PostGIS avec contrôle de propriété, modération et règles de publication.
3. Ajouter suppression des données et consentement géolocalisation, puis politique de confidentialité.
4. Effectuer des essais Android/iOS, vérifier les différences d'orientation des capteurs et le champ de vision.
5. Pour ancrage réel fiable, implémenter ARCore Geospatial ou un autre VPS sur les appareils/lieux pris en charge.
6. Revoir les limites de taille et CORS pour les GLB et les exigences d'accessibilité.

**Aucun secret ni clé API inclus.**

## Vérifications automatisées

Depuis le dépôt, exécuter `node --test tests/geo-ar.test.mjs` (Node.js 20+). Les tests vérifient la validation des objets, les distances et azimuts, les contrôles de l'interface et le repli manuel sans boussole. Ils ne remplacent pas un test sur smartphones Android/iOS.

Sans accès aux capteurs d'orientation, le mode caméra démarre à 0° et l'utilisateur doit ajuster le cap avec le curseur. Le rendu est approximatif : aucun VPS ni SLAM n'est fourni.
