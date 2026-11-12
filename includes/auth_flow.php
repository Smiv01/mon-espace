<?php

// Logique partagée : codes de vérification et jetons de récupération.

function issue_verification_code($userId, $email)
{
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $ttl = (int)cfg('security.code_ttl_minutes', 15);

    // Un seul code actif à la fois.
    db()->prepare('UPDATE email_verifications SET used_at = ? WHERE user_id = ? AND used_at IS NULL')
        ->execute([now(), $userId]);
    // created_at est écrit par PHP (et non par MySQL) pour éviter tout décalage de fuseau horaire.
    db()->prepare('INSERT INTO email_verifications (user_id, code_hash, expires_at, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$userId, hash_secret($userId . ':' . $code), in_minutes($ttl), now()]);

    send_mail(
        $email,
        'Ton code de vérification',
        "Bonjour,\n\nTon code de vérification est : $code\n\nIl expire dans $ttl minutes. "
        . "Si tu n'es pas à l'origine de cette demande, ignore ce message."
    );
}

function latest_code_age_seconds($userId)
{
    $stmt = db()->prepare('SELECT created_at FROM email_verifications WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ? time() - strtotime($row['created_at']) : null;
}

/** Vérifie un code. Retourne true si valide (et le consomme), false sinon. */
function check_verification_code($userId, $code)
{
    $stmt = db()->prepare(
        'SELECT id, code_hash, attempts FROM email_verifications
          WHERE user_id = ? AND used_at IS NULL AND expires_at > ?
          ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$userId, now()]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['attempts'] >= (int)cfg('security.code_max_attempts', 5)) {
        return false;
    }

    if (hash_equals($row['code_hash'], hash_secret($userId . ':' . $code))) {
        // Consommation atomique : un seul usage possible.
        $upd = db()->prepare('UPDATE email_verifications SET used_at = ? WHERE id = ? AND used_at IS NULL');
        $upd->execute([now(), $row['id']]);
        return $upd->rowCount() === 1;
    }

    db()->prepare('UPDATE email_verifications SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
    return false;
}

function create_reset_link($userId, $email)
{
    $token = bin2hex(random_bytes(32));
    $ttl = (int)cfg('security.reset_ttl_minutes', 30);

    db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
        ->execute([$userId, hash_secret($token), in_minutes($ttl)]);

    send_mail(
        $email,
        'Récupération de ton mot de passe',
        "Bonjour,\n\nPour choisir un nouveau mot de passe, ouvre ce lien (valable $ttl minutes, usage unique) :\n"
        . full_url('nouveau-mot-de-passe.php?token=' . $token)
        . "\n\nSi tu n'as rien demandé, ignore ce message."
    );
}

function find_valid_reset($token)
{
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT id, user_id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?'
    );
    $stmt->execute([hash_secret($token), now()]);
    return $stmt->fetch() ?: null;
}

function revoke_all_sessions($userId, $exceptHash = null)
{
    if ($exceptHash === null) {
        db()->prepare('DELETE FROM user_sessions WHERE user_id = ?')->execute([$userId]);
    } else {
        db()->prepare('DELETE FROM user_sessions WHERE user_id = ? AND session_hash <> ?')->execute([$userId, $exceptHash]);
    }
}

// Faux hachage pour égaliser le temps de réponse quand l'utilisateur n'existe pas.
function dummy_hash()
{
    static $h = null;
    if ($h === null) {
        $h = password_hash('mot-de-passe-factice', PASSWORD_DEFAULT);
    }
    return $h;
}
