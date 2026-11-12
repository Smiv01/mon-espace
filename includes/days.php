<?php
// Ma Journée : journaux du jour et albums partagés. Les photos sont stockées hors du web (storage/days)
// et servies par journee-photo.php après vérification de l'accès.
require_once __DIR__ . '/media.php';

/** Supprime une journée avec ses photos et tout son contenu. */
function day_remove($dayId)
{
    $dayId = (int)$dayId;
    $stmt = db()->prepare('SELECT photo_path FROM day_posts WHERE day_id = ? AND photo_path IS NOT NULL');
    $stmt->execute([$dayId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $f) {
        @unlink(storage_dir('days') . '/' . basename((string)$f));
    }
    db()->prepare('DELETE FROM day_reactions WHERE post_id IN (SELECT id FROM day_posts WHERE day_id = ?)')->execute([$dayId]);
    db()->prepare('DELETE FROM day_posts WHERE day_id = ?')->execute([$dayId]);
    db()->prepare('DELETE FROM day_members WHERE day_id = ?')->execute([$dayId]);
    db()->prepare('DELETE FROM day_invites WHERE day_id = ?')->execute([$dayId]);
    db()->prepare('DELETE FROM days WHERE id = ?')->execute([$dayId]);
}

/** Efface les journées temporaires arrivées à échéance (appelé quand on ouvre le module). */
function days_purge()
{
    $stmt = db()->prepare('SELECT id FROM days WHERE expires_at IS NOT NULL AND expires_at <= ?');
    $stmt->execute([gmdate('Y-m-d H:i:s')]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        day_remove($id);
    }
}

/** Journée accessible à l'utilisateur (propriétaire ou membre), avec son rôle ; false sinon. */
function day_access($userId, $id)
{
    $id = (int)$id;
    if ($id < 1) {
        return false;
    }
    $stmt = db()->prepare('SELECT * FROM days WHERE id = ?');
    $stmt->execute([$id]);
    $d = $stmt->fetch();
    if (!$d || ($d['expires_at'] !== null && $d['expires_at'] <= gmdate('Y-m-d H:i:s'))) {
        return false;
    }
    if ((int)$d['user_id'] === (int)$userId) {
        $d['role'] = 'owner';
        return $d;
    }
    $stmt = db()->prepare('SELECT 1 FROM day_members WHERE day_id = ? AND user_id = ?');
    $stmt->execute([$id, (int)$userId]);
    if ($stmt->fetchColumn()) {
        $d['role'] = 'member';
        return $d;
    }
    return false;
}

/** Mon journal d'aujourd'hui : le crée s'il n'existe pas encore. */
function day_journal_today($userId, $today)
{
    $stmt = db()->prepare("SELECT id FROM days WHERE user_id = ? AND kind = 'journal' AND day_date = ?");
    $stmt->execute([(int)$userId, $today]);
    $id = $stmt->fetchColumn();
    if (!$id) {
        db()->prepare("INSERT INTO days (user_id, kind, title, day_date) VALUES (?, 'journal', ?, ?)")
            ->execute([(int)$userId, 'Ma journée', $today]);
        $id = db()->lastInsertId();
    }
    return (int)$id;
}

/** Journées visibles (les miennes et celles où je suis membre), les plus récentes d'abord. */
function days_visible($userId)
{
    $stmt = db()->prepare(
        "SELECT d.*, (SELECT COUNT(*) FROM day_posts p WHERE p.day_id = d.id) AS n_posts,
                (SELECT COUNT(*) FROM day_members m WHERE m.day_id = d.id) AS n_members
           FROM days d
          WHERE (d.user_id = ? OR d.id IN (SELECT day_id FROM day_members WHERE user_id = ?))
            AND (d.expires_at IS NULL OR d.expires_at > ?)
          ORDER BY d.day_date DESC, d.id DESC LIMIT 100"
    );
    $stmt->execute([(int)$userId, (int)$userId, gmdate('Y-m-d H:i:s')]);
    return $stmt->fetchAll();
}

/** Souvenirs d'il y a 1 semaine, 1 mois et 1 an (dans un groupe donné, ou dans tous mes groupes si $dayId = 0). */
function day_memories($userId, $today, $dayId = 0)
{
    $out = [];
    $now = gmdate('Y-m-d H:i:s');
    foreach ([[7, 'Il y a 1 semaine'], [30, 'Il y a 1 mois'], [365, 'Il y a 1 an']] as $per) {
        $date = gmdate('Y-m-d', strtotime($today . ' 12:00:00 UTC') - $per[0] * 86400);
        $sql = 'SELECT p.id, p.day_id, p.body, p.photo_path, d.title, d.kind, pr.first_name
                  FROM day_posts p JOIN days d ON d.id = p.day_id LEFT JOIN profiles pr ON pr.user_id = p.user_id
                 WHERE DATE(p.created_at) = ?
                   AND (d.expires_at IS NULL OR d.expires_at > ?)';
        $args = [$date, $now];
        if ($dayId > 0) {
            $sql .= ' AND p.day_id = ?';
            $args[] = (int)$dayId;
        } else {
            $sql .= ' AND (d.user_id = ? OR d.id IN (SELECT day_id FROM day_members WHERE user_id = ?))';
            $args[] = (int)$userId;
            $args[] = (int)$userId;
        }
        $stmt = db()->prepare($sql . ' ORDER BY p.id LIMIT 8');
        $stmt->execute($args);
        $rows = $stmt->fetchAll();
        if ($rows) {
            $out[] = ['label' => $per[1], 'date' => $date, 'posts' => $rows];
        }
    }
    return $out;
}

/** Statistiques d'un groupe : totaux, contributions, rythme (30 derniers jours contre les 30 d'avant). */
function day_stats($dayId, $today)
{
    $dayId = (int)$dayId;
    $q = function ($sql, $args) {
        $stmt = db()->prepare($sql);
        $stmt->execute($args);
        return $stmt;
    };
    $st = [];
    $st['total'] = (int)$q('SELECT COUNT(*) FROM day_posts WHERE day_id = ?', [$dayId])->fetchColumn();
    $st['photos'] = (int)$q('SELECT COUNT(*) FROM day_posts WHERE day_id = ? AND photo_path IS NOT NULL', [$dayId])->fetchColumn();
    $st['messages'] = (int)$q("SELECT COUNT(*) FROM day_posts WHERE day_id = ? AND body IS NOT NULL AND body <> ''", [$dayId])->fetchColumn();
    $st['likes'] = (int)$q('SELECT COUNT(*) FROM day_reactions r JOIN day_posts p ON p.id = r.post_id WHERE p.day_id = ?', [$dayId])->fetchColumn();
    $st['by_member'] = $q('SELECT pr.first_name, COUNT(*) AS n FROM day_posts p LEFT JOIN profiles pr ON pr.user_id = p.user_id WHERE p.day_id = ? GROUP BY p.user_id, pr.first_name ORDER BY n DESC LIMIT 10', [$dayId])->fetchAll();
    $st['active_days'] = (int)$q('SELECT COUNT(DISTINCT DATE(created_at)) FROM day_posts WHERE day_id = ?', [$dayId])->fetchColumn();
    $best = $q('SELECT DATE(created_at) AS d, COUNT(*) AS n FROM day_posts WHERE day_id = ? GROUP BY DATE(created_at) ORDER BY n DESC, d DESC LIMIT 1', [$dayId])->fetch();
    $st['best_day'] = $best ? ['date' => $best['d'], 'n' => (int)$best['n']] : null;
    $first = $q('SELECT MIN(DATE(created_at)) FROM day_posts WHERE day_id = ?', [$dayId])->fetchColumn();
    $st['first'] = $first ?: null;
    $edge1 = gmdate('Y-m-d', strtotime($today . ' 12:00:00 UTC') - 30 * 86400);
    $edge2 = gmdate('Y-m-d', strtotime($today . ' 12:00:00 UTC') - 60 * 86400);
    $st['last30'] = (int)$q('SELECT COUNT(*) FROM day_posts WHERE day_id = ? AND DATE(created_at) > ?', [$dayId, $edge1])->fetchColumn();
    $st['prev30'] = (int)$q('SELECT COUNT(*) FROM day_posts WHERE day_id = ? AND DATE(created_at) > ? AND DATE(created_at) <= ?', [$dayId, $edge2, $edge1])->fetchColumn();
    return $st;
}

/** Affiche les cartes « souvenirs d'il y a… » (HTML). */
function day_memories_html(array $mem, $showTitle)
{
    $html = '';
    foreach ($mem as $m) {
        $html .= '<div class="card memory-card mt"><strong>📅 ' . e($m['label']) . '</strong> <span class="muted small capitalize">' . e(fr_date($m['date'])) . '</span><div class="memory-grid">';
        foreach ($m['posts'] as $p) {
            $link = e(url('journee.php?id=' . (int)$p['day_id']));
            $html .= '<a class="memory-item" href="' . $link . '">';
            if ($p['photo_path']) {
                $html .= '<img src="' . e(url('journee-photo.php?id=' . (int)$p['id'])) . '" alt="" loading="lazy">';
            }
            if ($p['body'] !== null && $p['body'] !== '') {
                $html .= '<span class="small">' . e(mb_substr($p['body'], 0, 80)) . '</span>';
            }
            if ($showTitle) {
                $html .= '<span class="muted small">' . e($p['kind'] === 'journal' ? 'Ma journée' : $p['title']) . '</span>';
            }
            $html .= '</a>';
        }
        $html .= '</div></div>';
    }
    return $html;
}

/** Texte « Disparaît dans N jours » pour une journée temporaire ('' si permanente). */
function day_expiry_text($expiresAt)
{
    if ($expiresAt === null || $expiresAt === '') {
        return '';
    }
    $left = strtotime($expiresAt . ' UTC') - time();
    if ($left <= 0) {
        return 'Disparaît bientôt';
    }
    $days = (int)ceil($left / 86400);
    return $days <= 1 ? "Disparaît d'ici demain" : 'Disparaît dans ' . $days . ' jours';
}

/** Fichiers envoyés pour un champ (multiple ou simple), en liste de tableaux [name, tmp_name, size, error, ...]. */
function day_uploaded_files($multiKey, $singleKey)
{
    $out = [];
    if (isset($_FILES[$multiKey]) && is_array($_FILES[$multiKey]['name'])) {
        $f = $_FILES[$multiKey];
        foreach (array_keys($f['name']) as $i) {
            if ((int)$f['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => $f['name'][$i], 'tmp_name' => $f['tmp_name'][$i], 'size' => $f['size'][$i], 'error' => $f['error'][$i], 'type' => $f['type'][$i]];
        }
    } elseif (isset($_FILES[$singleKey]) && is_array($_FILES[$singleKey]) && !is_array($_FILES[$singleKey]['name']) && (int)$_FILES[$singleKey]['error'] !== UPLOAD_ERR_NO_FILE) {
        $out[] = $_FILES[$singleKey];
    }
    return $out;
}

/** Enregistre une photo de journée (réduite à 1280 px, ré-encodée en JPEG). Retourne [nom|null, erreur|null]. */
function save_day_photo($file)
{
    $code = is_array($file) ? opt($file, 'error', UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
    if ($code === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
        return [null, 'Photo trop lourde (6 Mo maximum).'];
    }
    if ($code !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return [null, "L'envoi de la photo a échoué. Réessaie."];
    }
    if ($file['size'] > 6 * 1024 * 1024) {
        return [null, 'Photo trop lourde (6 Mo maximum).'];
    }
    if (!function_exists('imagecreatetruecolor') || !function_exists('getimagesize')) {
        return [null, "Le traitement d'images (extension GD de PHP) n'est pas activé sur ce serveur."];
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info) {
        return [null, "Ce fichier n'est pas une image valide."];
    }
    if ($info[2] === IMAGETYPE_JPEG) {
        $src = @imagecreatefromjpeg($file['tmp_name']);
    } elseif ($info[2] === IMAGETYPE_PNG) {
        $src = @imagecreatefrompng($file['tmp_name']);
    } elseif ($info[2] === 18 && function_exists('imagecreatefromwebp')) {
        $src = @imagecreatefromwebp($file['tmp_name']);
    } else {
        return [null, 'Format non accepté (JPG, PNG ou WebP).'];
    }
    if (!$src) {
        return [null, "Impossible de lire l'image."];
    }
    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 1 || $h < 1 || $w * $h > 40000000) {
        return [null, 'Image trop grande.'];
    }
    $scale = min(1.0, 1280 / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $name = bin2hex(random_bytes(12)) . '.jpg';
    if (!@imagejpeg($dst, storage_dir('days') . '/' . $name, 82)) {
        return [null, "Impossible d'enregistrer la photo."];
    }
    return [$name, null];
}
