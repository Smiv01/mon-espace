<?php
// Configuration centralisée. Modifier ici suffit à renommer l'application.
return [
    'brand' => [
        'name'    => 'Lyvo',
        'tagline' => 'Organise ta vie, partage tes moments, reste connecté.',
        'logo'    => 'ME',
    ],

    // 'dev' : les e-mails ne sont pas envoyés, ils s'affichent sur dev-mails.php.
    // 'prod' : à utiliser seulement une fois un vrai envoi d'e-mails configuré.
    'env' => 'dev',

    // Réglages WAMP par défaut (utilisateur root, sans mot de passe).
    // Change-les si ton MySQL est configuré autrement.
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'mon_espace',
        'user' => 'root',
        'pass' => '',
    ],

    // Lecture des captures d'écran (OCR). Laisse vide : le chemin de Tesseract est cherché tout seul.
    // Sous Windows, installe Tesseract puis, si besoin, mets par ex. 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe'.
    'tesseract_path' => '',

    // E-mails en production (code de vérification, mot de passe oublié) : clé API Brevo + adresse expéditeur validée.
    // À renseigner dans config/config.local.php sur le serveur, jamais ici.
    'mail' => [
        'brevo_api_key' => '',
        'from_email'    => '',
    ],

    'security' => [
        'password_min'        => 10,
        'max_login_failures'  => 5,    // par e-mail ou par adresse IP
        'lockout_minutes'     => 15,
        'code_ttl_minutes'    => 15,   // code de vérification
        'code_max_attempts'   => 5,
        'reset_ttl_minutes'   => 30,   // lien de récupération
        'session_days'        => 30,
    ],
];
