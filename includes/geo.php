<?php
// Localisation : cercles de proches et partage de position volontaire, toujours limité dans le temps.
// Seule la dernière position est conservée ; elle est effacée à l'arrêt du partage ou à son expiration.

/** Date de fin des partages « sans limite » (le partage dure jusqu'à ce que la personne l'arrête). */
const GEO_FOREVER = '2099-12-31 23:59:59';

function geo_is_forever($utc)
{
    return $utc !== null && substr((string)$utc, 0, 4) >= '2090';
}

/** Date de fin d'un partage selon la durée choisie en heures (0 = sans limite). */
function geo_expiry_for($hours)
{
    $hours = (int)$hours;
    if ($hours === 0) {
        return GEO_FOREVER;
    }
    if (!in_array($hours, [1, 4, 8], true)) {
        $hours = 1;
    }
    return gmdate('Y-m-d H:i:s', time() + $hours * 3600);
}

function geo_purge()
{
    $now = gmdate('Y-m-d H:i:s');
    db()->prepare('DELETE FROM location_shares WHERE expires_at <= ?')->execute([$now]);
    db()->prepare('DELETE FROM location_links WHERE expires_at <= ?')->execute([$now]);
    // Trajet : 24 h maximum ; événements (arrivées, alertes) : 3 jours maximum.
    db()->prepare('DELETE FROM location_trail WHERE at <= ?')->execute([gmdate('Y-m-d H:i:s', time() - 86400)]);
    db()->prepare('DELETE FROM location_events WHERE created_at <= ?')->execute([gmdate('Y-m-d H:i:s', time() - 3 * 86400)]);
}

/** Réglages de personnalisation (thème de carte, épingle, trajet), avec valeurs par défaut. */
function geo_prefs($userId)
{
    $stmt = db()->prepare('SELECT pin_emoji, pin_color, map_theme, trail_on FROM location_prefs WHERE user_id = ?');
    $stmt->execute([(int)$userId]);
    $r = $stmt->fetch();
    return [
        'emoji' => $r && $r['pin_emoji'] !== null ? $r['pin_emoji'] : '',
        'color' => $r ? $r['pin_color'] : '#6c5ce7',
        'theme' => $r ? $r['map_theme'] : 'classic',
        'trail' => $r ? (int)$r['trail_on'] === 1 : false,
    ];
}

function geo_themes()
{
    return ['classic' => 'Classique', 'pastel' => 'Pastel', 'fun' => 'Joyeux', 'night' => 'Nuit'];
}

function geo_colors()
{
    return ['#6c5ce7', '#e84393', '#00b894', '#fd9644', '#0984e3', '#d63031', '#2d3436', '#fdcb6e'];
}

function geo_emojis()
{
    return ['😀', '😎', '🥳', '🦁', '🐯', '🦊', '🐼', '⚽', '🎧', '🚀', '🌴', '❤️'];
}

function geo_prefs_save($userId, $emoji, $color, $theme, $trail)
{
    if (!in_array($emoji, geo_emojis(), true)) {
        $emoji = '';
    }
    if (!in_array($color, geo_colors(), true)) {
        $color = '#6c5ce7';
    }
    if (!isset(geo_themes()[$theme])) {
        $theme = 'classic';
    }
    $has = db()->prepare('SELECT 1 FROM location_prefs WHERE user_id = ?');
    $has->execute([(int)$userId]);
    if ($has->fetchColumn()) {
        db()->prepare('UPDATE location_prefs SET pin_emoji = ?, pin_color = ?, map_theme = ?, trail_on = ? WHERE user_id = ?')
            ->execute([$emoji !== '' ? $emoji : null, $color, $theme, $trail ? 1 : 0, (int)$userId]);
    } else {
        db()->prepare('INSERT INTO location_prefs (user_id, pin_emoji, pin_color, map_theme, trail_on) VALUES (?, ?, ?, ?, ?)')
            ->execute([(int)$userId, $emoji !== '' ? $emoji : null, $color, $theme, $trail ? 1 : 0]);
    }
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
    foreach (['location_shares', 'circle_members', 'circle_invites', 'circle_places', 'location_events'] as $t) {
        db()->prepare('DELETE FROM ' . $t . ' WHERE circle_id = ?')->execute([$circleId]);
    }
    db()->prepare('DELETE FROM circles WHERE id = ?')->execute([$circleId]);
}

/** Positions visibles dans un cercle (partages actifs). Tableau prêt pour JSON. */
function circle_points($circleId, $viewerId, $tz = 'Africa/Abidjan')
{
    $stmt = db()->prepare(
        'SELECT s.user_id, s.lat, s.lng, s.accuracy, s.updated_at, s.expires_at, p.first_name, lp.pin_emoji, lp.pin_color
           FROM location_shares s LEFT JOIN profiles p ON p.user_id = s.user_id LEFT JOIN location_prefs lp ON lp.user_id = s.user_id
          WHERE s.circle_id = ? AND s.expires_at > ?
            AND NOT EXISTS (SELECT 1 FROM location_hidden h WHERE h.user_id = s.user_id AND h.from_user_id = ?)
          ORDER BY s.user_id'
    );
    $stmt->execute([(int)$circleId, gmdate('Y-m-d H:i:s'), (int)$viewerId]);
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
            'until' => geo_is_forever($r['expires_at']) ? '' : geo_local_time($r['expires_at'], $tz),
            'emoji' => (string)$r['pin_emoji'],
            'color' => $r['pin_color'] ? $r['pin_color'] : '#6c5ce7',
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
    $host = parse_url(geo_tiles_base(), PHP_URL_SCHEME) . '://' . parse_url(geo_tiles_base(), PHP_URL_HOST);
    header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self' data: " . $host . "; form-action 'self'; frame-ancestors 'none'");
}

/** Cercles de l'utilisateur (créés par lui ou rejoints) : [id => nom]. */
function geo_my_circles($userId)
{
    $stmt = db()->prepare('SELECT c.id, c.name FROM circles c WHERE c.user_id = ? OR c.id IN (SELECT circle_id FROM circle_members WHERE user_id = ?) ORDER BY c.id');
    $stmt->execute([(int)$userId, (int)$userId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int)$r['id']] = $r['name'];
    }
    return $out;
}

/** Positions visibles par l'utilisateur dans tous ses cercles (une seule ligne par personne, la plus récente). */
function geo_visible_points($userId, $tz = 'Africa/Abidjan')
{
    $ids = array_keys(geo_my_circles($userId));
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        'SELECT s.user_id, s.lat, s.lng, s.accuracy, s.updated_at, s.expires_at, p.first_name, lp.pin_emoji, lp.pin_color
           FROM location_shares s LEFT JOIN profiles p ON p.user_id = s.user_id LEFT JOIN location_prefs lp ON lp.user_id = s.user_id
          WHERE s.circle_id IN (' . $in . ') AND s.expires_at > ?
            AND NOT EXISTS (SELECT 1 FROM location_hidden h WHERE h.user_id = s.user_id AND h.from_user_id = ?)
          ORDER BY s.user_id'
    );
    $stmt->execute(array_merge($ids, [gmdate('Y-m-d H:i:s'), (int)$userId]));
    $best = [];
    foreach ($stmt->fetchAll() as $r) {
        $u = (int)$r['user_id'];
        if (!isset($best[$u]) || (string)$r['updated_at'] > (string)$best[$u]['updated_at']) {
            $best[$u] = $r;
        }
    }
    $out = [];
    foreach ($best as $r) {
        $has = $r['lat'] !== null && $r['lng'] !== null;
        $out[] = [
            'name' => $r['first_name'] ? $r['first_name'] : 'Quelqu\'un',
            'me' => (int)$r['user_id'] === (int)$userId,
            'lat' => $has ? (float)$r['lat'] : null,
            'lng' => $has ? (float)$r['lng'] : null,
            'acc' => $r['accuracy'] !== null ? (int)$r['accuracy'] : null,
            'ago' => $r['updated_at'] ? max(0, time() - strtotime($r['updated_at'] . ' UTC')) : null,
            'until' => geo_is_forever($r['expires_at']) ? '' : geo_local_time($r['expires_at'], $tz),
            'emoji' => (string)$r['pin_emoji'],
            'color' => $r['pin_color'] ? $r['pin_color'] : '#6c5ce7',
        ];
    }
    return $out;
}

/** Mes partages actifs : ['circles' => [id => nom], 'forever' => bool, 'until' => 'HH:MM'|'']. */
function geo_my_status($userId, $tz = 'Africa/Abidjan')
{
    $stmt = db()->prepare('SELECT s.circle_id, s.expires_at, c.name FROM location_shares s JOIN circles c ON c.id = s.circle_id WHERE s.user_id = ? AND s.expires_at > ? ORDER BY s.circle_id');
    $stmt->execute([(int)$userId, gmdate('Y-m-d H:i:s')]);
    $circles = [];
    $latest = '';
    foreach ($stmt->fetchAll() as $r) {
        $circles[(int)$r['circle_id']] = $r['name'];
        if ((string)$r['expires_at'] > $latest) {
            $latest = (string)$r['expires_at'];
        }
    }
    return ['on' => (bool)$circles, 'circles' => $circles, 'forever' => $latest !== '' && geo_is_forever($latest), 'until' => ($latest !== '' && !geo_is_forever($latest)) ? geo_local_time($latest, $tz) : ''];
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


/** Lieux et rendez-vous des cercles de l'utilisateur. */
function geo_places($userId)
{
    $ids = array_keys(geo_my_circles($userId));
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare('SELECT id, circle_id, kind, name, emoji, lat, lng FROM circle_places WHERE circle_id IN (' . $in . ') ORDER BY id');
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[] = ['id' => (int)$r['id'], 'circle' => (int)$r['circle_id'], 'kind' => $r['kind'], 'name' => $r['name'],
            'emoji' => $r['emoji'] !== null && $r['emoji'] !== '' ? $r['emoji'] : ($r['kind'] === 'meeting' ? '🚩' : '📍'),
            'lat' => (float)$r['lat'], 'lng' => (float)$r['lng']];
    }
    return $out;
}

/** Distance en mètres entre deux points (formule de haversine). */
function geo_distance($lat1, $lng1, $lat2, $lng2)
{
    $r = M_PI / 180;
    $dLat = ($lat2 - $lat1) * $r;
    $dLng = ($lng2 - $lng1) * $r;
    $h = sin($dLat / 2) * sin($dLat / 2) + cos($lat1 * $r) * cos($lat2 * $r) * sin($dLng / 2) * sin($dLng / 2);
    return 12742000 * asin(min(1, sqrt($h)));
}

/** Événements récents (arrivées, « bien arrivé », alertes) des cercles de l'utilisateur, sans les siens. */
function geo_events($userId, $limit = 15)
{
    $ids = array_keys(geo_my_circles($userId));
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        'SELECT ev.id, ev.circle_id, ev.user_id, ev.kind, ev.place_name, ev.lat, ev.lng, ev.created_at, p.first_name, c.name AS circle_name
           FROM location_events ev LEFT JOIN profiles p ON p.user_id = ev.user_id JOIN circles c ON c.id = ev.circle_id
          WHERE ev.circle_id IN (' . $in . ') AND ev.created_at > ?
            AND NOT EXISTS (SELECT 1 FROM location_hidden h WHERE h.user_id = ev.user_id AND h.from_user_id = ? AND ev.kind <> \'sos\')
          ORDER BY ev.id DESC LIMIT ' . (int)$limit
    );
    $stmt->execute(array_merge($ids, [gmdate('Y-m-d H:i:s', time() - 2 * 86400), (int)$userId]));
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[] = ['kind' => $r['kind'], 'who' => $r['first_name'] ? $r['first_name'] : 'Quelqu\'un', 'mine' => (int)$r['user_id'] === (int)$userId,
            'circle' => $r['circle_name'], 'place' => $r['place_name'], 'lat' => $r['lat'] !== null ? (float)$r['lat'] : null,
            'lng' => $r['lng'] !== null ? (float)$r['lng'] : null, 'ago' => max(0, time() - strtotime($r['created_at'] . ' UTC'))];
    }
    return $out;
}

function geo_event_text(array $ev)
{
    $who = $ev['mine'] ? 'Toi' : $ev['who'];
    if ($ev['kind'] === 'sos') {
        return '🆘 ' . $who . ($ev['mine'] ? ' as envoyé' : ' a envoyé') . ' une alerte SOS';
    }
    if ($ev['kind'] === 'safe') {
        return '✅ ' . $who . ($ev['mine'] ? ' es bien arrivé(e)' : ' est bien arrivé(e)');
    }
    return '📍 ' . $who . ($ev['mine'] ? ' es arrivé(e)' : ' est arrivé(e)') . ($ev['place'] ? ' : ' . $ev['place'] : '');
}

/** Crée un événement dans chaque cercle indiqué (déjà vérifié accessible). */
function geo_event_add($userId, array $circleIds, $kind, $placeId, $placeName, $lat, $lng)
{
    foreach ($circleIds as $cid) {
        db()->prepare('INSERT INTO location_events (circle_id, user_id, kind, place_id, place_name, lat, lng, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([(int)$cid, (int)$userId, $kind, $placeId, $placeName, $lat, $lng, gmdate('Y-m-d H:i:s')]);
    }
}

/** Liens de suivi temporaires actifs de l'utilisateur (sans le jeton, qui n'est montré qu'une fois). */
function geo_my_links($userId, $tz = 'Africa/Abidjan')
{
    $stmt = db()->prepare('SELECT id, expires_at FROM location_links WHERE user_id = ? AND expires_at > ? ORDER BY id DESC');
    $stmt->execute([(int)$userId, gmdate('Y-m-d H:i:s')]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[] = ['id' => (int)$r['id'], 'until' => geo_local_time($r['expires_at'], $tz)];
    }
    return $out;
}

/** Trajet du jour de l'utilisateur (jamais visible par les autres). */
function geo_trail($userId)
{
    $stmt = db()->prepare('SELECT lat, lng FROM location_trail WHERE user_id = ? AND at > ? ORDER BY id LIMIT 400');
    $stmt->execute([(int)$userId, gmdate('Y-m-d H:i:s', time() - 86400)]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[] = [round((float)$r['lat'], 5), round((float)$r['lng'], 5)];
    }
    return $out;
}

/** Autorise les tuiles de la carte (OpenStreetMap par défaut, ou le fournisseur choisi dans la configuration). */
function geo_tiles_base()
{
    $u = (string)cfg('map.tiles_url', 'https://tile.openstreetmap.org');
    return preg_match('#^https://[A-Za-z0-9.\-]+(/[A-Za-z0-9._~\-/]*)?$#', $u) ? rtrim($u, '/') : 'https://tile.openstreetmap.org';
}
