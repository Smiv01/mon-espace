# Mise en ligne de Lyvo

Pile retenue : hébergement gratuit InfinityFree (PHP 8.3, MySQL, https gratuit) + nom de domaine chez VENAME (paiement Mobile Money possible) + e-mails par Brevo (offre gratuite).

## 1. Préparer le serveur
1. Créer le compte d'hébergement, puis une base MySQL dans le panneau. Noter : serveur MySQL (ex. `sqlXXX.infinityfree.com`), nom de base, utilisateur, mot de passe.
2. Ouvrir phpMyAdmin, choisir la base, onglet **Importer**, envoyer `sql/schema.sql`. (`install.php` est volontairement désactivé hors mode dev.)
3. Ajouter le domaine dans l'hébergement, puis pointer ses serveurs DNS vers ceux d'InfinityFree (chez VENAME). Activer le certificat SSL gratuit.

## 2. Envoyer les fichiers
Envoyer le contenu du dossier `mon-espace` dans `htdocs/` par FTP (FileZilla) : l'identifiant FTP est dans le panneau.
Ne pas envoyer `config/config.local.php` de ton PC : il se crée sur le serveur (étape 3).

## 3. Réglages du serveur (config/config.local.php, créé sur le serveur)
```php
<?php
return [
    'env' => 'prod',
    'db' => ['host' => 'sqlXXX.infinityfree.com', 'name' => 'if0_XXXX_monespace', 'user' => 'if0_XXXX', 'pass' => 'MOT_DE_PASSE'],
    'mail' => ['brevo_api_key' => 'CLE_API_BREVO', 'from_email' => 'adresse-validee-chez-brevo@exemple.com'],
];
```

## 4. E-mails (Brevo)
Créer un compte Brevo gratuit, valider l'adresse expéditeur, créer une clé API (SMTP & API), la coller ci-dessus. Sans cela, personne ne peut valider son inscription.

## 5. Vérifications
- L'adresse commence par `https://` ; la page de profil propose « Installer Lyvo ».
- Créer un compte : le code arrive par e-mail.
- `install.php` et `dev-mails.php` répondent « Page introuvable ».

## Limites de l'hébergement gratuit
Pas de Tesseract (la lecture automatique des tickets/captures est désactivée : coller le texte du SMS reste possible), performances modestes, suspension possible en cas de forte charge. Pour une vraie ouverture au public : hébergement payant et sauvegardes automatiques.


## Page administrateur

`admin.php` n'est visible que par les adresses e-mail listées dans `config/config.local.php` :

```php
'admin' => ['emails' => ['ton-adresse@exemple.com']],
```

Elle affiche les inscriptions, des totaux d'usage (jamais le contenu des utilisateurs) et permet de valider à la main un compte en attente. Pour tout autre visiteur, la page répond 404.

## Installation sur téléphone derrière InfinityFree

Le filtre anti-robot d'InfinityFree bloque `manifest.php` quand le navigateur le demande sans cookie. Le lien du manifeste porte donc `crossorigin="use-credentials"` (voir `includes/layout.php`).
