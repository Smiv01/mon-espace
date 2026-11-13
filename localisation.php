<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/money.php';
require __DIR__ . '/includes/geo.php';

$user = require_login();
$userId = (int)$user['id'];
geo_purge();

if (is_post()) {
    if (!csrf_valid()) {
        flash('error', 'Session expirée. Recharge la page et réessaie.');
        redirect('localisation.php');
    }
    $action = (string)opt($_POST, 'action', '');
    if ($action === 'share_on') {
        $mine = geo_my_circles($userId);
        $picked = [];
        $raw = isset($_POST['circles']) && is_array($_POST['circles']) ? $_POST['circles'] : [];
        foreach ($raw as $cid) {
            if (isset($mine[(int)$cid])) {
                $picked[(int)$cid] = true;
            }
        }
        if (!$mine) {
            flash('error', 'Crée d\'abord un cercle de proches (ou rejoins-en un) pour choisir avec qui partager.');
        } elseif (!$picked) {
            flash('error', 'Choisis au moins un cercle avec qui partager ta position.');
        } else {
            $exp = geo_expiry_for((int)opt($_POST, 'hours', 1));
            // Les cercles décochés ne voient plus rien ; les autres reçoivent la nouvelle durée.
            foreach (array_keys($mine) as $cid) {
                if (!isset($picked[$cid])) {
                    db()->prepare('DELETE FROM location_shares WHERE user_id = ? AND circle_id = ?')->execute([$userId, $cid]);
                    continue;
                }
                $has = db()->prepare('SELECT id FROM location_shares WHERE user_id = ? AND circle_id = ?');
                $has->execute([$userId, $cid]);
                $sid = $has->fetchColumn();
                if ($sid) {
                    db()->prepare('UPDATE location_shares SET expires_at = ? WHERE id = ?')->execute([$exp, $sid]);
                } else {
                    db()->prepare('INSERT INTO location_shares (user_id, circle_id, expires_at) VALUES (?, ?, ?)')->execute([$userId, $cid, $exp]);
                }
            }
            flash('ok', 'Localisation activée. Tes proches te voient en direct. Garde l\'appli ouverte pour que ta position se mette à jour.');
        }
        redirect('localisation.php');
    }
    if ($action === 'prefs_save') {
        geo_prefs_save($userId, (string)opt($_POST, 'emoji', ''), (string)opt($_POST, 'color', ''), (string)opt($_POST, 'theme', 'classic'), !empty($_POST['trail']));
        flash('ok', 'Ta carte est personnalisée.');
        redirect('localisation.php');
    }
    if ($action === 'trail_clear') {
        db()->prepare('DELETE FROM location_trail WHERE user_id = ?')->execute([$userId]);
        flash('ok', 'Ton trajet a été effacé.');
        redirect('localisation.php');
    }
    if ($action === 'link_create' && once_valid()) {
        $n = db()->prepare('SELECT COUNT(*) FROM location_links WHERE user_id = ? AND expires_at > ?');
        $n->execute([$userId, gmdate('Y-m-d H:i:s')]);
        $hours = (int)opt($_POST, 'hours', 1);
        if (!in_array($hours, [1, 4, 8], true)) {
            $hours = 1;
        }
        if ((int)$n->fetchColumn() >= 5) {
            flash('error', "Tu as déjà 5 liens actifs. Arrête-en un avant d'en créer un autre.");
        } else {
            $token = bin2hex(random_bytes(16));
            db()->prepare('INSERT INTO location_links (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
                ->execute([$userId, hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + $hours * 3600)]);
            $_SESSION['new_track_link'] = $token;
            flash('ok', "Lien créé pour $hours h. Envoie-le : la personne n'a pas besoin de compte.");
        }
        redirect('localisation.php');
    }
    if ($action === 'link_cancel') {
        db()->prepare('DELETE FROM location_links WHERE id = ? AND user_id = ?')->execute([post_id(), $userId]);
        flash('ok', 'Lien arrêté.');
        redirect('localisation.php');
    }
    if ($action === 'share_off') {
        db()->prepare('DELETE FROM location_shares WHERE user_id = ?')->execute([$userId]);
        flash('ok', 'Localisation désactivée. Plus personne ne te voit, et ta position a été effacée.');
        redirect('localisation.php');
    }
    if ($action === 'circle_add' && once_valid()) {
        $name = post_str('name', 80);
        $n = db()->prepare('SELECT COUNT(*) FROM circles WHERE user_id = ?');
        $n->execute([$userId]);
        if ($name === '') {
            flash('error', 'Donne un nom à ton cercle (par exemple « Famille »).');
        } elseif ((int)$n->fetchColumn() >= 10) {
            flash('error', 'Tu as déjà 10 cercles. Supprime-en un pour en créer un autre.');
        } else {
            db()->prepare('INSERT INTO circles (user_id, name) VALUES (?, ?)')->execute([$userId, $name]);
            flash('ok', 'Cercle créé. Invite tes proches depuis l\'onglet Partage.');
            redirect('cercle.php?id=' . (int)db()->lastInsertId() . '&tab=partage');
        }
    }
    redirect('localisation.php');
}

$stmt = db()->prepare(
    'SELECT c.id, c.name, c.user_id,
            (SELECT COUNT(*) FROM circle_members m WHERE m.circle_id = c.id) AS n_members,
            (SELECT COUNT(*) FROM location_shares s WHERE s.circle_id = c.id AND s.expires_at > ?) AS n_live,
            (SELECT COUNT(*) FROM location_shares s WHERE s.circle_id = c.id AND s.user_id = ? AND s.expires_at > ?) AS i_share
       FROM circles c
      WHERE c.user_id = ? OR c.id IN (SELECT circle_id FROM circle_members WHERE user_id = ?)
      ORDER BY c.id'
);
$now = gmdate('Y-m-d H:i:s');
$stmt->execute([$now, $userId, $now, $userId, $userId]);
$circles = $stmt->fetchAll();

$tz = opt($user, 'timezone', 'Africa/Abidjan');
$my = geo_my_status($userId, $tz);
$points = geo_visible_points($userId, $tz);
$myCircles = geo_my_circles($userId);
$prefs = geo_prefs($userId);
$places = geo_places($userId);
$events = geo_events($userId, 12);
$links = geo_my_links($userId, $tz);
$trail = $prefs['trail'] ? geo_trail($userId) : [];
$newTrackLink = '';
if (!empty($_SESSION['new_track_link'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', $_SERVER['HTTP_HOST']) : 'localhost';
    $newTrackLink = $scheme . '://' . $host . url('suivre.php?code=' . $_SESSION['new_track_link']);
    unset($_SESSION['new_track_link']);
}

allow_map_tiles();
app_open('Localisation', 'localisation.php', $user);
?>
<header class="page-hero">
  <img class="mod-ico" src="<?= e(url('assets/icons/loc.svg')) ?>" alt="" width="56" height="56">
  <div><h1>Localisation</h1><p class="muted">Ta position, seulement quand tu le décides</p></div>
</header>

<div class="card map-card">
  <div id="map" class="map" data-points="<?= e(json_encode($points)) ?>" data-url="<?= e(url('localisation-data.php?all=1')) ?>"
       data-update="<?= e(url('localisation-update.php')) ?>" data-csrf="<?= e(csrf_token()) ?>" data-sharing="<?= ($my['on'] || $links) ? '1' : '0' ?>" data-follow="1"
       data-theme="<?= e($prefs['theme']) ?>" data-me-emoji="<?= e($prefs['emoji']) ?>" data-me-color="<?= e($prefs['color']) ?>"
       data-places="<?= e(json_encode($places)) ?>" data-trail="<?= e(json_encode($trail)) ?>" data-trail-on="<?= $prefs['trail'] ? '1' : '0' ?>"
       data-event-url="<?= e(url('localisation-event.php')) ?>" data-my-share="<?= $my['on'] ? '1' : '0' ?>"
       data-tiles="<?= e(geo_tiles_base()) ?>" role="img" aria-label="Carte : ma position et celle de mes proches"></div>
  <p class="map-credit small">© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a></p>
  <noscript><p class="muted small">La carte demande JavaScript.</p></noscript>
</div>
<p class="muted small" id="geo-status" aria-live="polite"></p>

<?php
ob_start();
if (!$myCircles): ?>
  <div class="muted small mt">Pour partager, crée un cercle (Famille, Amis…) ci-dessous et invite tes proches.</div>
<?php else: ?>
  <form method="post" class="mt">
    <?= csrf_field() ?><input type="hidden" name="action" value="share_on">
    <p class="muted small">Pendant combien de temps ?</p>
    <div class="chips">
      <label class="chip"><input type="radio" name="hours" value="1" checked><span>1 heure</span></label>
      <label class="chip"><input type="radio" name="hours" value="4"><span>4 heures</span></label>
      <label class="chip"><input type="radio" name="hours" value="8"><span>8 heures</span></label>
      <label class="chip"><input type="radio" name="hours" value="0"><span>Jusqu'à ce que j'arrête</span></label>
    </div>
    <p class="muted small mt">Avec qui ?</p>
    <div class="chips">
      <?php foreach ($myCircles as $cid => $cname): ?>
        <label class="chip"><input type="checkbox" name="circles[]" value="<?= (int)$cid ?>" <?= (!$my['on'] || isset($my['circles'][$cid])) ? 'checked' : '' ?>><span><?= e($cname) ?></span></label>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-primary btn-block mt" type="submit"><?= $my['on'] ? 'Enregistrer' : 'Activer ma localisation' ?></button>
  </form>
<?php endif;
$shareForm = ob_get_clean(); ?>
<div class="card mt">
  <?php if ($my['on']): ?>
    <p><strong>🟢 Ta localisation est activée</strong></p>
    <p class="muted small">Vue en direct par : <?= e(implode(', ', $my['circles'])) ?>. <?= $my['forever'] ? 'Jusqu\'à ce que tu la désactives.' : 'Elle s\'arrête toute seule à ' . e($my['until']) . '.' ?> Garde l'appli ouverte pour que ta position se mette à jour.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="share_off"><button class="btn btn-block" type="submit">Désactiver ma localisation</button></form>
    <details class="mt">
      <summary class="small"><strong>Changer la durée ou les cercles</strong></summary>
      <?= $shareForm ?>
    </details>
  <?php else: ?>
    <p><strong>⚪ Ta localisation est désactivée</strong></p>
    <p class="muted small">Personne ne voit où tu es. Ta position sur la carte ci-dessus reste sur ton téléphone. Active-la pour que tes proches te voient en direct.</p>
    <?= $shareForm ?>
  <?php endif; ?>
</div>

<h2 class="section-h">Mes proches en direct</h2>
<div class="task-list" id="people">
  <?php if (!$points): ?><div class="card muted">Personne ne partage sa position pour le moment.</div><?php endif; ?>
  <?php foreach ($points as $p): ?>
    <div class="task"><span class="mini-av"><?= e(mb_strtoupper(mb_substr($p['name'], 0, 1))) ?></span>
      <span class="task-main"><strong><?= e($p['name']) ?><?= $p['me'] ? ' (toi)' : '' ?></strong><span class="sub"><?= e(ago_text($p['ago'])) ?></span></span></div>
  <?php endforeach; ?>
</div>

<?php if ($myCircles): ?>
<h2 class="section-h">Sécurité</h2>
<div class="card">
  <p><strong>✅ Je suis bien arrivé(e)</strong></p>
  <p class="muted small">Préviens tes proches en un clic. Choisis les cercles :</p>
  <div class="chips" id="safe-circles">
    <?php foreach ($myCircles as $cid => $cname): ?>
      <label class="chip"><input type="checkbox" value="<?= (int)$cid ?>" checked><span><?= e($cname) ?></span></label>
    <?php endforeach; ?>
  </div>
  <button class="btn btn-primary btn-block mt" type="button" data-geo-event="safe">Je suis bien arrivé(e)</button>
  <hr class="sep">
  <p><strong>🆘 Alerte SOS</strong></p>
  <p class="muted small">Envoie une alerte avec ta position à <strong>tous tes cercles</strong>. À utiliser en cas de danger. Ils la voient en ouvrant Lyvo.</p>
  <button class="btn btn-block sos-btn" type="button" data-geo-event="sos" data-confirm-text="Envoyer une alerte SOS à tous tes cercles ?">Envoyer une alerte SOS</button>
  <p class="muted small mt" id="event-status" aria-live="polite"></p>
</div>
<?php endif; ?>

<?php if ($events): ?>
<h2 class="section-h">Activité récente</h2>
<div class="task-list">
  <?php foreach ($events as $ev): ?>
    <div class="task<?= $ev['kind'] === 'sos' ? ' sos-row' : '' ?>"><span class="task-main"><strong><?= e(geo_event_text($ev)) ?></strong>
      <span class="sub"><?= e($ev['circle']) ?> · <?= e(ago_text($ev['ago'])) ?></span></span>
      <?php if ($ev['lat'] !== null): ?><a class="btn" target="_blank" rel="noopener" href="<?= e('https://www.openstreetmap.org/?mlat=' . $ev['lat'] . '&mlon=' . $ev['lng'] . '#map=17/' . $ev['lat'] . '/' . $ev['lng']) ?>">Voir</a><?php endif; ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<h2 class="section-h">Ma carte</h2>
<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="action" value="prefs_save">
  <p><strong>🎨 Personnaliser</strong></p>
  <p class="muted small">Style de la carte</p>
  <div class="chips">
    <?php foreach (geo_themes() as $k => $label): ?>
      <label class="chip"><input type="radio" name="theme" value="<?= e($k) ?>" <?= $prefs['theme'] === $k ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
    <?php endforeach; ?>
  </div>
  <p class="muted small mt">Mon avatar sur la carte (tes proches le voient)</p>
  <div class="chips">
    <label class="chip"><input type="radio" name="emoji" value="" <?= $prefs['emoji'] === '' ? 'checked' : '' ?>><span>Mon initiale</span></label>
    <?php foreach (geo_emojis() as $em): ?>
      <label class="chip"><input type="radio" name="emoji" value="<?= e($em) ?>" <?= $prefs['emoji'] === $em ? 'checked' : '' ?>><span><?= e($em) ?></span></label>
    <?php endforeach; ?>
  </div>
  <p class="muted small mt">Ma couleur</p>
  <div class="chips">
    <?php foreach (geo_colors() as $col): ?>
      <label class="chip c-<?= e(substr($col, 1)) ?>"><input type="radio" name="color" value="<?= e($col) ?>" <?= $prefs['color'] === $col ? 'checked' : '' ?>><span>●</span></label>
    <?php endforeach; ?>
  </div>
  <label class="check mt"><input type="checkbox" name="trail" value="1" <?= $prefs['trail'] ? 'checked' : '' ?>> <span>Enregistrer mon trajet du jour <span class="muted small">(visible par moi seul, effacé après 24 h)</span></span></label>
  <button class="btn btn-primary btn-block mt" type="submit">Enregistrer</button>
</form>
<?php if ($prefs['trail']): ?>
<form method="post" class="mt" data-confirm="Effacer ton trajet ?"><?= csrf_field() ?><input type="hidden" name="action" value="trail_clear"><button class="btn btn-block" type="submit">Effacer mon trajet (<?= count($trail) ?> points)</button></form>
<?php endif; ?>

<h2 class="section-h">Lien de suivi temporaire</h2>
<div class="card">
  <p class="muted small">Pour quelqu'un qui n'a pas Lyvo : il ouvre le lien et voit ta position en direct, sans compte, pendant la durée choisie. Tu peux l'arrêter à tout moment. Garde l'appli ouverte pour que ta position se mette à jour.</p>
  <?php if ($newTrackLink !== ''): ?>
    <div class="invite-box">
      <p class="small"><strong>Voilà ton lien</strong> (il ne sera plus affiché après cette page) :</p>
      <input class="input" id="track-link" type="text" readonly value="<?= e($newTrackLink) ?>" aria-label="Lien de suivi">
      <button class="btn btn-block mt" type="button" data-copy="track-link">Copier le lien</button>
      <a class="btn btn-primary btn-block mt" target="_blank" rel="noopener" href="<?= e('https://wa.me/?text=' . rawurlencode('Suis ma position en direct : ' . $newTrackLink)) ?>">Envoyer sur WhatsApp</a>
    </div>
  <?php endif; ?>
  <form method="post" class="mt">
    <?= csrf_field() ?><?= once_field() ?><input type="hidden" name="action" value="link_create">
    <div class="chips">
      <label class="chip"><input type="radio" name="hours" value="1" checked><span>1 heure</span></label>
      <label class="chip"><input type="radio" name="hours" value="4"><span>4 heures</span></label>
      <label class="chip"><input type="radio" name="hours" value="8"><span>8 heures</span></label>
    </div>
    <button class="btn btn-primary btn-block mt" type="submit">Créer un lien</button>
  </form>
  <?php foreach ($links as $lk): ?>
    <div class="member-row"><span class="grow small muted">Lien actif jusqu'à <?= e($lk['until']) ?></span>
      <form method="post" class="inline" data-confirm="Arrêter ce lien ?">
        <?= csrf_field() ?><input type="hidden" name="action" value="link_cancel"><input type="hidden" name="id" value="<?= (int)$lk['id'] ?>">
        <button class="icon-btn" type="submit" aria-label="Arrêter le lien">✕</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>

<div class="card muted small mt">🔒 Rien n'est partagé par défaut. Tu actives et tu désactives quand tu veux, avec la durée de ton choix. Seule ta dernière position est gardée, puis effacée dès que tu désactives.</div>

<h2 class="section-h">Mes cercles</h2>
<div class="task-list">
  <?php if (!$circles): ?><div class="card muted">Aucun cercle pour le moment. Crée « Famille » ou « Amis » ci-dessous.</div><?php endif; ?>
  <?php foreach ($circles as $c): $mine = (int)$c['user_id'] === $userId; ?>
    <a class="task day-row" href="<?= e(url('cercle.php?id=' . (int)$c['id'])) ?>">
      <span class="task-main"><strong><?= e($c['name']) ?><?php if ((int)$c['i_share'] > 0): ?> <span class="badge-shared live">Je partage</span><?php endif; ?></strong>
        <span class="sub"><?= (int)$c['n_members'] + 1 ?> personne<?= (int)$c['n_members'] + 1 > 1 ? 's' : '' ?> · <?= (int)$c['n_live'] ?> en direct<?= $mine ? '' : ' · invité' ?></span></span>
    </a>
  <?php endforeach; ?>
</div>

<details class="card mt">
  <summary><strong>+ Nouveau cercle</strong></summary>
  <form method="post" class="mt">
    <?= csrf_field() ?><?= once_field() ?><input type="hidden" name="action" value="circle_add">
    <label class="field"><span>Nom du cercle</span><input class="input" type="text" name="name" maxlength="80" placeholder="Famille" required></label>
    <button class="btn btn-primary btn-block mt" type="submit">Créer le cercle</button>
  </form>
</details>
<script src="<?= e(url('assets/map.js')) ?>" defer></script>
<?php app_close('localisation.php');
