# Architecture de Mon Espace

Document de référence : comment l'application est construite, pourquoi, et comment la faire évoluer.

## 1. Choix techniques

| Sujet | Choix | Raison |
|---|---|---|
| Langage | PHP (compatible **5.5 à 8.3**), sans framework | Tourne tel quel sur WAMP et sur un hébergement mutualisé |
| Base de données | MySQL / MariaDB via PDO, requêtes préparées | Standard, présent partout |
| Interface | HTML rendu côté serveur + CSS (`assets/style.css`) + un peu de JavaScript facultatif | Simple, rapide, fonctionne sans JavaScript (sauf la carte) |
| Dépendances externes | **Aucune** (pas de Composer, pas de npm) | Le code QR, la carte et le traitement d'images sont écrits dans le projet |
| Lecture d'images (OCR) | Tesseract installé sur le poste, appelé par `includes/ocr.php` | Aucun service payant |
| Thème | Variables CSS, clair / sombre automatique + sélecteur | Suit le téléphone |
| Monnaie | Entiers en **XOF** (FCFA), jamais de décimales | Pas d'erreurs d'arrondi |

## 2. Organisation des fichiers

```
accueil.php, explorer.php        Accueil et liste des modules
agenda*.php, notes.php, note.php Agenda et notes
finances.php, comptes.php,       Budget : comptes, transactions, épargne, dettes
  transactions.php, epargne.php,
  objectif.php, dettes.php, ajouter.php
espaces.php, espace.php          Frigos : stock, courses, achat, partage
journees.php, journee.php,       Ma Journée : journaux, albums, photos
  journee-photo.php
localisation.php, cercle.php,    Localisation : cercles et positions
  localisation-data.php, localisation-update.php
notifications.php                Alertes calculées à la volée
rejoindre.php                    Acceptation des invitations (frigo, journée, cercle)
connexion / inscription / ...    Comptes, vérification par code, mot de passe oublié
install.php                      Crée ou met à jour la base (mode dev seulement)

includes/
  bootstrap.php   Configuration, session, CSRF, base de données, sécurité
  layout.php      Gabarit commun, navigation, thème
  helpers.php     Dates françaises, validations
  money.php       Montants, comptes, lecture de SMS, jetons « une seule fois »
  stock.php       Frigos : accès, quantités, péremption, tickets de caisse
  days.php        Ma Journée : accès, photos, expiration
  geo.php         Localisation : cercles, positions
  notifs.php      Notifications
  qr.php          Générateur de code QR (SVG)
  ocr.php         Lecture d'images avec Tesseract
  media.php       Stockage des photos hors du web
sql/schema.sql    Toutes les tables (CREATE TABLE IF NOT EXISTS)
storage/          Photos envoyées (fermé au web, servi par des scripts PHP)
assets/           style.css, app.js, map.js, theme.js, icônes SVG
```

## 3. Modèle de données

Toutes les tables sont dans `sql/schema.sql`. `install.php` peut être relancé à tout moment : il ne crée que ce qui manque.

- **Comptes** : `users`, `profiles`, `user_preferences`, `user_sessions`, `email_verifications`, `password_resets`, `login_attempts`, `dev_mails`
- **Agenda / Notes** : `tasks`, `events`, `notes`
- **Budget** : `accounts`, `categories`, `transactions`, `budgets` (enveloppes), `savings_goals`, `debts`, `debt_payments`
- **Frigos** : `spaces`, `space_items` (stock + péremption), `shopping_items`, `space_members`, `space_invites`
- **Ma Journée** : `days`, `day_members`, `day_invites`, `day_posts`, `day_reactions`
- **Localisation** : `circles`, `circle_members`, `circle_invites`, `location_shares`

Règle d'or : les noms de contraintes (`fk_…`) doivent être **uniques dans toute la base** (MySQL l'exige). Utiliser un préfixe propre à la table.

## 4. Le modèle de partage (commun aux trois modules)

Frigos, journées et cercles fonctionnent de la même façon :

1. Une ressource a un **propriétaire** (`user_id`) et des **membres** (table `*_members`).
2. Une fonction d'accès (`space_access`, `day_access`, `circle_access`) renvoie la ressource et le rôle (`owner` / `member`), ou `false`. **Chaque page l'appelle avant de lire ou d'écrire.**
3. Le propriétaire crée une **invitation** : jeton aléatoire de 16 octets, seul son hachage SHA-256 est stocké, valable 7 jours, **usage unique** (mise à jour atomique `used_at IS NULL`), maximum 5 en attente.
4. Le lien (ou le code QR qui le contient) n'est affiché **qu'une seule fois**.
5. `rejoindre.php` reconnaît le type d'invitation, demande la connexion si besoin (retour automatique après connexion), puis l'invité accepte ou refuse.
6. Seul le propriétaire peut inviter, retirer un membre ou supprimer. Un membre peut quitter.

## 5. Les flux importants

**Courses et stock (Frigos).** Le stock n'est jamais modifié directement : on ajoute à la liste de courses, puis « Acheté ✓ » (un produit) ou « J'ai fini mes courses » (toute la liste) ouvre l'écran d'achat : date de péremption par produit, et « Qui a payé ? ». Si c'est l'utilisateur, une dépense « Alimentation » est créée sur le compte choisi (jamais au-delà du solde). Le bouton « − » du stock remet le produit sur la liste. La photo d'un ticket passe par l'OCR puis par le même écran.

**Ma Journée.** Un journal par jour et par personne (`kind = journal`), ou des albums nommés (`kind = album`). Les photos sont réduites à 1280 px, ré-encodées en JPEG (métadonnées supprimées) et rangées dans `storage/days`, servies par `journee-photo.php` après vérification de l'accès. Une journée « temporaire » a `expires_at` à 30 jours ; elle est effacée (photos comprises) au prochain passage dans le module.

**Localisation.** Rien n'est partagé par défaut. Un membre active un partage de 1, 4 ou 8 heures pour un cercle (`location_shares`). Le navigateur envoie la position à `localisation-update.php` (toutes les 15 secondes, page ouverte) ; seule la **dernière position** est gardée. L'arrêt, l'expiration, le départ ou le retrait du membre l'effacent. La carte (`assets/map.js`) assemble des tuiles OpenStreetMap sans bibliothèque ; la politique de sécurité n'autorise ce domaine que sur la page de la carte.

**Notifications.** Aucune table : `includes/notifs.php` calcule à chaque affichage les produits périmés ou bientôt périmés, tâches en retard ou du jour, événements d'aujourd'hui et de demain, enveloppes à 80 % ou dépassées, dettes à échéance.

## 6. Sécurité

- Mots de passe hachés (`password_hash`) ; code de vérification à 6 chiffres haché, 5 essais, 15 minutes ; limitation des tentatives par e-mail et par IP.
- Jeton **CSRF** sur chaque formulaire et sur les requêtes JavaScript d'écriture ; jeton **« une seule fois »** contre le double envoi.
- Requêtes préparées partout ; échappement `e()` de toute sortie.
- Politique CSP stricte : pas de script ni de style en ligne ; les tuiles de carte ne sont autorisées que sur la page concernée.
- Chaque requête filtre par propriétaire ou par appartenance (jamais de confiance dans un identifiant reçu).
- Fichiers envoyés : taille limitée, type vérifié par lecture réelle de l'image, ré-encodage, nom aléatoire, dossier fermé au web.
- Redirection après connexion limitée à un format précis (`rejoindre.php?code=` + 32 caractères hexadécimaux).
- Dossiers `includes`, `config`, `sql`, `storage` fermés par `.htaccess`.

## 7. Tests

Les tests de bout en bout ont été exécutés sur un serveur de développement PHP 8.3 avec SQLite (pas sur MySQL) : parcours complets de chaque module, droits des membres et des étrangers, doubles envois, injections, expirations. **À refaire sur la vraie installation (PHP 5.5 / MySQL) après chaque mise à jour importante** — en particulier l'OCR avec de vraies captures Wave / Orange et de vrais tickets de caisse.

## 8. Mise en ligne : liste de contrôle

- [ ] Passer à **PHP 8.x** (PHP 5.5 n'est plus maintenu)
- [ ] Mettre `env` à `prod` dans `config/config.php` (désactive `install.php` et `dev-mails.php`)
- [ ] Brancher un vrai envoi d'e-mails dans `send_mail()` (`includes/bootstrap.php`)
- [ ] Activer **HTTPS** (indispensable pour la localisation sur téléphone)
- [ ] Mot de passe MySQL propre, utilisateur dédié (pas `root`)
- [ ] Installer Tesseract (avec le français) sur le serveur, ou désactiver l'OCR
- [ ] Sauvegardes régulières de la base **et** du dossier `storage/`
- [ ] Vérifier la politique des tuiles OpenStreetMap pour l'usage prévu, ou choisir un fournisseur de cartes

## 9. Feuille de route

Fait : comptes, Agenda, Notes, Budget, Frigos et courses, Ma Journée, Localisation, Notifications.

À venir : connexion avec Google (identifiant client requis), notifications sur le téléphone, application installable (PWA), sauvegarde automatique, modification d'un produit du stock.
