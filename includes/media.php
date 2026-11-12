<?php

// Photos de profil : fichiers hors de portée du web (dossier storage protégé), servis par avatar.php.

function storage_dir($sub)
{
    $dir = APP_ROOT . '/storage/' . $sub;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $guard = APP_ROOT . '/storage/.htaccess';
    if (!is_file($guard)) {
        @file_put_contents($guard, "Require all denied\n");
    }
    return $dir;
}

/** Enregistre la photo envoyée. Retourne null si tout va bien, sinon un message d'erreur. */
function save_avatar($userId, $file)
{
    if (!is_array($file) || opt($file, 'error', UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $code = is_array($file) ? opt($file, 'error', UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            return 'Photo trop lourde (3 Mo maximum).';
        }
        return 'Aucune photo reçue. Choisis un fichier et réessaie.';
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return 'Envoi invalide.';
    }
    if ($file['size'] > 3 * 1024 * 1024) {
        return 'Photo trop lourde (3 Mo maximum).';
    }
    if (!function_exists('imagecreatetruecolor') || !function_exists('getimagesize')) {
        return "Le traitement d'images (extension GD de PHP) n'est pas activé sur ce serveur.";
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info) {
        return "Ce fichier n'est pas une image valide.";
    }
    $type = $info[2];
    if ($type === IMAGETYPE_JPEG) {
        $src = @imagecreatefromjpeg($file['tmp_name']);
    } elseif ($type === IMAGETYPE_PNG) {
        $src = @imagecreatefrompng($file['tmp_name']);
    } elseif ($type === 18 && function_exists('imagecreatefromwebp')) { // IMAGETYPE_WEBP
        $src = @imagecreatefromwebp($file['tmp_name']);
    } else {
        return 'Format non accepté (JPG, PNG ou WebP).';
    }
    if (!$src) {
        return "Impossible de lire l'image.";
    }
    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 1 || $h < 1 || $w * $h > 40000000) {
        return 'Image trop grande.';
    }
    // Recadrage carré au centre, puis ré-encodage : supprime les métadonnées et tout contenu caché.
    $side = min($w, $h);
    $dst = imagecreatetruecolor(256, 256);
    imagecopyresampled($dst, $src, 0, 0, (int)(($w - $side) / 2), (int)(($h - $side) / 2), 256, 256, $side, $side);

    $name = $userId . '_' . bin2hex(random_bytes(8)) . '.jpg';
    $dir = storage_dir('avatars');
    if (!@imagejpeg($dst, $dir . '/' . $name, 85)) {
        return "Impossible d'enregistrer la photo.";
    }
    $stmt = db()->prepare('SELECT avatar_path FROM profiles WHERE user_id = ?');
    $stmt->execute([$userId]);
    $old = (string)$stmt->fetchColumn();
    db()->prepare('UPDATE profiles SET avatar_path = ? WHERE user_id = ?')->execute([$name, $userId]);
    if ($old !== '') {
        @unlink($dir . '/' . basename($old));
    }
    return null;
}

function delete_avatar($userId)
{
    $stmt = db()->prepare('SELECT avatar_path FROM profiles WHERE user_id = ?');
    $stmt->execute([$userId]);
    $old = (string)$stmt->fetchColumn();
    db()->prepare('UPDATE profiles SET avatar_path = NULL WHERE user_id = ?')->execute([$userId]);
    if ($old !== '') {
        @unlink(storage_dir('avatars') . '/' . basename($old));
    }
}
