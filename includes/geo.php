<?php
// Localisation : cercles de proches et partage de position volontaire, toujours limité dans le temps.
// Seule la dernière position est conservée ; elle est effacée à l'arrêt du partage ou à son expiration.

function geo_purge()
{
    db()->prepare('DELETE FROM location_shares WHERE expires_at <= ?')->execute([gmdate('Y-m-d H:i:s')]);
}

/** Cercle accessible à l'utilisateur (propriétaire ou membre), avec son rôle ; false sinon. */
function circle_access($userId, $id)
{
    $id = (int)$id;
    if ($id < 1) {
        return false;
    }
    $stmt = db()->prepare('SELECT * FROM circles WHERE id = ?');
    $stmt->execute([$id]);
    $c = $stmt->fetch();
    if (!$c) {
        return false;
    }
    if ((int)$c['user_id'] === (int)$userId) {
        $c['role'] = 'owner';
        return $c;
    }
    $stmt = db()->prepare('SELECT 1 FROM circle_members WHERE circle_id = ? AND user_id = ?');
    $stmt->execute([$id, (int)$userId]);
    if ($stmt->fetchColumn()) {
        $c['role'] = 'member';
        return $c;
    }
    return false;
}

function circle_remove($circleId)
{
    $circleId = (int)$circleId;
    foreach (['location_shares', 'circle_members', 'circle_invites'] as $t) {
        db()->prepare('DELETE FROM ' . $t . ' WHERE circle_id = ?')->execute([$circleId]);
    }
    db()->prepare('DELETE FROM circles WHERE id = ?')->execute([$circleId]);
}

/** Positions visibles dans un cercle (partages actifs). Tableau prêt pour JSON. */
function circle_points($circleId, $viewerId, $tz = 'Africa/Abidjan')
{
    $stmt = db()->prepare(
        'SELECT s.user_id, s.lat, s.lng, s.accuracy, s.updated_at, s.expires_at, p.first_name
           FROM location_shares s LEFT JOIN profiles p ON p.user_id = s.user_id
          WHERE s.circle_id = ? AND s.expires_at > ? ORDER BY s.user_id'
    );
    $stmt->execute([(int)$circleId, gmdate('Y-m-d H:i:s')]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $has = $r['lat'] !== null && $r['lng'] !== null;
        $out[] = [
            'name' => $r['first_name'] ? $r['first_name'] : 'Quelqu\'un',
            'me' => (int)$r['user_id'] === (int)$viewerId,
            'lat' => $has ? (float)$r['lat'] : null,
            'lng' => $has ? (float)$r['lng'] : null,
            'acc' => $r['accuracy'] !== null ? (int)$r['accuracy'] : null,
            'ago' => $r['updated_at'] ? max(0, time() - strtotime($r['updated_at'] . ' UTC')) : null,
            'until' => geo_local_time($r['expires_at'], $tz),
        ];
    }
    return $out;
}

/** « il y a 2 min » */
function ago_text($seconds)
{
    if ($seconds === null) {
        return 'en attente de position';
    }
    if ($seconds < 60) {
        return "à l'instant";
    }
    if ($seconds < 3600) {
        return 'il y a ' . (int)floor($seconds / 60) . ' min';
    }
    return 'il y a ' . (int)floor($seconds / 3600) . ' h';
}

/** Autorise les tuiles OpenStreetMap sur cette page uniquement (la politique de sécurité reste stricte ailleurs). */
function allow_map_tiles()
{
    header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self' data: https://tile.openstreetmap.org; form-action 'self'; frame-ancestors 'none'");
}

/** Heure locale (HH:MM) d'une date UTC « Y-m-d H:i:s ». */
function geo_local_time($utc, $tz)
{
    try {
        $d = new DateTime($utc, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone($tz ? $tz : 'Africa/Abidjan'));
        return $d->format('H:i');
    } catch (Exception $ex) {
        return substr($utc, 11, 5);
    }
}
