# Mon Espace

Application web personnelle et de partage (PHP 5.5+ / MySQL, pensée pour WAMP puis un hébergement), en français, montants en FCFA (XOF).

## Modules

| Module | Ce qu'il fait |
|---|---|
| **Agenda** | Tâches (priorités, dates) et événements |
| **Notes** | Idées et mémos |
| **Budget** | Comptes (espèces, Wave, Mobile Money…), dépenses, transferts, épargne, dettes, enveloppes, graphique, lecture de SMS ou de captures d'écran (OCR) |
| **Frigos** | Stock avec dates de péremption, liste de courses, achat avec choix du payeur, photo du ticket de caisse (OCR), espaces partagés par lien ou code QR |
| **Ma Journée** | Journal du jour et albums partagés (photos, messages, ❤️), souvenirs temporaires de 30 jours en option |
| **Localisation** | Cercles de proches, partage de position volontaire et limité dans le temps, carte OpenStreetMap |
| **Notifications** | Produits qui expirent, tâches en retard, enveloppes et dettes |

Thème clair / sombre (automatique selon le téléphone). Aucune bibliothèque externe : code QR, carte et OCR (Tesseract installé sur le poste) sont gérés par le projet.

## Mettre à jour la base

Après une mise à jour du code, ouvre **http://localhost/mon-espace/install.php** : les tables manquantes sont créées, les données existantes ne sont pas touchées.

---

## Démarrage (Phase 1)

Fondation de l'application : comptes sécurisés, profil, appareils connectés, tableau de bord et navigation. Le nom et le logo texte se changent dans `config/config.php`.

## Lancer en local avec WAMP

1. Démarre WAMP et attends l'icône **verte**.
2. Le projet doit être dans `C:\wamp\www\mon-espace`.
3. Ouvre **http://localhost/mon-espace/install.php** et clique sur « Installer la base » (crée la base `mon_espace` et ses tables).
4. Ouvre **http://localhost/mon-espace/**.

Réglages MySQL par défaut de WAMP : utilisateur `root`, sans mot de passe. Si le tien est différent, modifie `config/config.php`.

Dans WAMP, ton dossier peut être `C:\wamp64\www` ou `C:\wamp\www` : l'adresse reste la même.

## E-mails en développement

Aucun e-mail réel n'est envoyé. Les codes de vérification et les liens de récupération s'affichent sur **http://localhost/mon-espace/dev-mails.php**. En passant `env` à `prod` dans la config, `install.php` et `dev-mails.php` sont désactivés ; il faudra alors brancher un vrai service d'envoi dans `send_mail()` (`includes/bootstrap.php`).

## Parcours à tester

1. Créer un compte, lire le code dans la boîte de développement, le saisir : tu arrives sur l'accueil.
2. Fermer le navigateur, rouvrir : tu es toujours connecté (session de 30 jours).
3. Profil : modifier le prénom, recharger.
4. Connexion depuis un second navigateur, puis « Fermer » cet appareil depuis le profil.
5. « Mot de passe oublié » : lien dans la boîte de développement, nouveau mot de passe, ancien refusé, toutes les sessions fermées.
6. 5 mots de passe faux de suite : la connexion est bloquée 15 minutes.

## Sécurité en place

- Mots de passe hachés (`password_hash`), jamais en clair.
- Requêtes préparées (pas d'injection SQL), échappement systématique (`e()`), jeton CSRF sur tous les formulaires, cookie de session `HttpOnly` + `SameSite`, session régénérée à la connexion.
- Code de vérification à 6 chiffres : haché, expire en 15 min, 5 essais, usage unique.
- Lien de récupération : jeton aléatoire de 32 octets, seule son empreinte est stockée, expire en 30 min, usage unique consommé de façon atomique, ferme toutes les sessions.
- Messages identiques que l'adresse existe ou non (inscription et récupération).
- Limitation des tentatives de connexion par adresse e-mail et par IP.
- Sessions révocables à distance ; un utilisateur ne peut fermer que les siennes.
- Dossiers `includes`, `config` et `sql` fermés par `.htaccess` (nécessite `AllowOverride` activé, ce qui est le cas par défaut dans WAMP pour `www`).
- En-têtes de sécurité dont une politique CSP : pas de scripts ni de styles en ligne.

## Ce qui est testé

37 vérifications de bout en bout ont réussi (inscription, validation, CSRF, code de vérification, e-mail déjà utilisé, profil, connexion, appareils, accès aux sessions d'autrui, récupération, jeton expiré, verrouillage). Elles ont été exécutées sur une base **SQLite de test**, pas sur MySQL : les requêtes sont standard, mais le premier lancement sur MySQL/WAMP reste à confirmer.

## Pas encore fait

- Modules Agenda, Finances, Espaces, Journées (pages marquées « pas encore développé »).
- Option « rester connecté » configurable, question secrète, photo de profil, exportation et suppression du compte, thème clair, notifications.
- Connexion par numéro de téléphone (nécessite un service SMS).
- Textes juridiques définitifs, envoi réel d'e-mails.

## Structure

- `index.php`, `inscription.php`, `verification.php`, `connexion.php`, `mot-de-passe-oublie.php`, `nouveau-mot-de-passe.php` : authentification
- `accueil.php`, `profil.php`, `agenda.php`, `journees.php`, `finances.php`, `espaces.php` : application
- `includes/` : bootstrap (base, session, sécurité), gabarits, logique d'authentification
- `sql/schema.sql` : schéma de la base
- `assets/` : styles, script, icône
