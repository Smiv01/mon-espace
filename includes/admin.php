<?php

// Administrateurs : adresses e-mail listées dans config/config.local.php, par exemple
//   'admin' => ['emails' => ['moi@exemple.com']],
// Aucun réglage en base de données : retirer l'adresse du fichier retire l'accès.

function is_admin_user($user)
{
    $emails = cfg('admin.emails', []);
    if (!is_array($emails) || !$user || empty($user['email'])) {
        return false;
    }
    $mine = mb_strtolower(trim((string)$user['email']));
    foreach ($emails as $email) {
        if ($mine !== '' && $mine === mb_strtolower(trim((string)$email))) {
            return true;
        }
    }
    return false;
}
