<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/qr.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/money.php';
require __DIR__ . '/includes/geo.php';

$user = require_login();
$userId = (int)$user['id'];
geo_purge();
$c = circle_access($userId, opt($_GET, 'id', 0));
if (!$c) {
    flash('error', 'Ce cercle est introuvable.');
    redirect('localisation.php');
}
$cid = (int)$c['id'];
$isOwner = $c['role'] === 'owner';
$tabIn = (string)opt($_GET, 'tab', '');
$tab = in_array($tabIn, ['partage', 'lieux'], true) ? $tabIn : 'carte';
$self = 'cercle.php?id=' . $cid;

if (is_post()) {
    if (!csrf_valid()) {
        flash('error', 'Session expirée. Recharge la page et réessaie.');
        redirect($self);
    }
    $action = (string)opt($_POST, 'action', '');
    $back = $self;
    if ($action === 'share_start') {
        $hours = (int)opt($_POST, 'hours', 1);
        if (!in_array($hours, [0, 1, 4, 8], true)) {
            $hours = 1;
        }
        $exp = geo_expiry_for($hours);
        $has = db()->prepare('SELECT id FROM location_shares WHERE user_id = ? AND circle_id = ?');
        $has->execute([$userId, $cid]);
        $sid = $has->fetchColumn();
        if ($sid) {
            db()->prepare('UPDATE location_shares SET expires_at = ? WHERE id = ?')->execute([$exp, $sid]);
        } else {
            db()->prepare('INSERT INTO location_shares (user_id, circle_id, expires_at) VALUES (?, ?, ?)')->execute([$userId, $cid, $exp]);
        }
        flash('ok', 'Tu partages ta position avec ce cercle' . ($hours === 0 ? ' jusqu\'à ce que tu arrêtes' : ' pendant ' . $hours . ' h') . '. Garde l\'appli ouverte pour qu\'elle se mette à jour.');
    } elseif ($action === 'share_stop') {
        db()->prepare('DELETE FROM location_shares WHERE user_id = ? AND circle_id = ?')->execute([$userId, $cid]);
        flash('ok', 'Partage arrêté. Ta position a été effacée.');
    } elseif ($action === 'place_add') {
        $back = $self . '&tab=lieux';
        $kind = opt($_POST, 'kind', 'place') === 'meeting' ? 'meeting' : 'place';
        $name = trim((string)opt($_POST, 'name', ''));
        $emoji = trim((string)opt($_POST, 'emoji', ''));
        $lat = opt($_POST, 'lat', '');
        $lng = opt($_POST, 'lng', '');
        $cnt = db()->prepare('SELECT COUNT(*) FROM circle_places WHERE circle_id = ?');
        $cnt->execute([$cid]);
        if ($name === '' || mb_strlen($name) > 60) {
            flash('error', 'Donne un nom au lieu (60 caractères maximum).');
        } elseif (!is_numeric($lat) || !is_numeric($lng) || abs((float)$lat) > 90 || abs((float)$lng) > 180 || ((float)$lat == 0 && (float)$lng == 0)) {
            flash('error', 'Position introuvable. Appuie sur « Utiliser ma position » et autorise la localisation.');
        } elseif ((int)$cnt->fetchColumn() >= 30) {
            flash('error', 'Ce cercle a déjà 30 lieux.');
        } elseif (once_valid()) {
            if (mb_strlen($emoji) > 8) {
                $emoji = '';
            }
            db()->prepare('INSERT INTO circle_places (circle_id, created_by, kind, name, emoji, lat, lng) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$cid, $userId, $kind, $name, $emoji !== '' ? $emoji : null, round((float)$lat, 6), round((float)$lng, 6)]);
            flash('ok', $kind === 'meeting' ? 'Point de rendez-vous ajouté.' : 'Lieu ajouté.');
        }
    } elseif ($action === 'place_delete') {
        $back = $self . '&tab=lieux';
        if ($isOwner) {
            db()->prepare('DELETE FROM circle_places WHERE id = ? AND circle_id = ?')->execute([post_id(), $cid]);
        } else {
            db()->prepare('DELETE FROM circle_places WHERE id = ? AND circle_id = ? AND created_by = ?')->execute([post_id(), $cid, $userId]);
        }
        flash('ok', 'Lieu supprimé.');
    } elseif ($action === 'hide_toggle') {
        $back = $self . '&tab=partage';
        $who = post_id('user_id');
        $inCircle = db()->prepare('SELECT 1 FROM circle_members WHERE circle_id = ? AND user_id = ? UNION SELECT 1 FROM circles WHERE id = ? AND user_id = ?');
        $inCircle->execute([$cid, $who, $cid, $who]);
        if ($who !== $userId && $inCircle->fetchColumn()) {
            $ex = db()->prepare('SELECT id FROM location_hidden WHERE user_id = ? AND from_user_id = ?');
            $ex->execute([$userId, $who]);
            if ($ex->fetchColumn()) {
                db()->prepare('DELETE FROM location_hidden WHERE user_id = ? AND from_user_id = ?')->execute([$userId, $who]);
                flash('ok', 'Cette personne revoit ta position.');
            } else {
                db()->prepare('INSERT INTO location_hidden (user_id, from_user_id) VALUES (?, ?)')->execute([$userId, $who]);
                flash('ok', 'Mode fantôme : cette personne ne voit plus ta position (elle ne sera pas prévenue).');
            }
        }
    } elseif ($action === 'circle_delete' && $isOwner) {
        circle_remove($cid);
        flash('ok', 'Cercle supprimé.');
        redirect('localisation.php');
    } elseif ($action === 'circle_leave' && !$isOwner) {
        db()->prepare('DELETE FROM circle_members WHERE circle_id = ? AND user_id = ?')->execute([$cid, $userId]);
        db()->prepare('DELETE FROM location_shares WHERE circle_id = ? AND user_id = ?')->execute([$cid, $userId]);
        flash('ok', 'Tu as quitté ce cercle.');
        redirect('localisation.php');
    } elseif ($action === 'invite_create' && $isOwner) {
        $back = $self . '&tab=partage';
        $n1 = db()->prepare('SELECT COUNT(*) FROM circle_invites WHERE circle_id = ? AND used_at IS NULL AND expires_at > ?');
        $n1->execute([$cid, gmdate('Y-m-d H:i:s')]);
        $n2 = db()->prepare('SELECT COUNT(*) FROM circle_members WHERE circle_id = ?');
        $n2->execute([$cid]);
        if ((int)$n1->fetchColumn() >= 5) {
            flash('error', "Tu as déjà 5 invitations en attente. Annule-en une ou attends qu'elle soit utilisée.");
        } elseif ((int)$n2->fetchColumn() >= 20) {
            flash('error', 'Ce cercle a déjà 20 membres.');
        } elseif (once_valid()) {
            $token = bin2hex(random_bytes(16));
            db()->prepare('INSERT INTO circle_invites (circle_id, created_by, token_hash, expires_at) VALUES (?, ?, ?, ?)')
                ->execute([$cid, $userId, hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + 7 * 86400)]);
            $_SESSION['new_circle_invite'] = ['circle' => $cid, 'token' => $token];
        }
    } elseif ($action === 'invite_cancel' && $isOwner) {
        $back = $self . '&tab=partage';
        db()->prepare('DELETE FROM circle_invites WHERE id = ? AND circle_id = ? AND used_at IS NULL')->execute([post_id(), $cid]);
        flash('ok', 'Invitation annulée.');
    } elseif ($action === 'member_remove' && $isOwner) {
        $back = $self . '&tab=partage';
        $uid = post_id('user_id');
        db()->prepare('DELETE FROM circle_members WHERE circle_id = ? AND user_id = ?')->execute([$cid, $uid]);
        db()->prepare('DELETE FROM location_shares WHERE circle_id = ? AND user_id = ?')->execute([$cid, $uid]);
        flash('ok', 'Membre retiré.');
    }
    redirect($back);
}

$stmt = db()->prepare('SELECT u.id, p.first_name FROM circle_members m JOIN users u ON u.id = m.user_id LEFT JOIN profiles p ON p.user_id = u.id WHERE m.circle_id = ? ORDER BY m.id');
$stmt->execute([$cid]);
$members = $stmt->fetchAll();
$stmt = db()->prepare('SELECT first_name FROM profiles WHERE user_id = ?');
$stmt->execute([(int)$c['user_id']]);
$ownerName = (string)$stmt->fetchColumn();

$points = circle_points($cid, $userId, opt($user, 'timezone', 'Africa/Abidjan'));
$iShare = false;
$myUntil = '';
foreach ($points as $p) {
    if ($p['me']) {
        $iShare = true;
        $myUntil = $p['until'];
    }
}

$hiddenFrom = [];
$hq = db()->prepare('SELECT from_user_id FROM location_hidden WHERE user_id = ?');
$hq->execute([$userId]);
foreach ($hq->fetchAll() as $hr) {
    $hiddenFrom[(int)$hr['from_user_id']] = true;
}
$places = [];
if ($tab === 'lieux') {
    $pq = db()->prepare('SELECT id, kind, name, emoji, lat, lng, created_by FROM circle_places WHERE circle_id = ? ORDER BY kind, id');
    $pq->execute([$cid]);
    $places = $pq->fetchAll();
}
$invites = [];
$newLink = '';
if ($tab === 'partage' && $isOwner) {
    $stmt = db()->prepare('SELECT id, expires_at FROM circle_invites WHERE circle_id = ? AND used_at IS NULL AND expires_at > ? ORDER BY id DESC');
    $stmt->execute([$cid, gmdate('Y-m-d H:i:s')]);
    $invites = $stmt->fetchAll();
    if (!empty($_SESSION['new_circle_invite']) && (int)$_SESSION['new_circle_invite']['circle'] === $cid) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $newLink = $scheme . '://' . (isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', $_SERVER['HTTP_HOST']) : 'localhost') . url('rejoindre.php?code=' . $_SESSION['new_circle_invite']['token']);
        unset($_SESSION['new_circle_invite']);
    }
}

function c_initial($name)
{
    return mb_strtoupper(mb_substr((string)$name !== '' ? (string)$name : '?', 0, 1));
}

if ($tab === 'carte') {
    allow_map_tiles();
}
$mapPlaces = [];
if ($tab === 'carte') {
    foreach (geo_places($userId) as $pp) {
        if ($pp['circle'] === $cid) {
            $mapPlaces[] = $pp;
        }
    }
}
app_open($c['name'], 'localisation.php', $user);
?>
<a class="back-link" href="<?= e(url('localisation.php')) ?>">← Localisation</a>
<header class="page-head"><div>
  <h1><?= e($c['name']) ?></h1>
  <p class="muted"><?= count($members) + 1 ?> personne<?= count($members) + 1 > 1 ? 's' : '' ?> dans ce cercle</p>
</div></header>

<div class="tabs" role="tablist">
  <a class="tab<?= $tab === 'carte' ? ' on' : '' ?>" href="<?= e(url($self)) ?>">Carte</a>
  <a class="tab<?= $tab === 'partage' ? ' on' : '' ?>" href="<?= e(url($self . '&tab=partage')) ?>">Membres</a>
  <a class="tab<?= $tab === 'lieux' ? ' on' : '' ?>" href="<?= e(url($self . '&tab=lieux')) ?>">Lieux</a>
</div>

<?php if ($tab === 'carte'): ?>
  <div class="card map-card">
    <div id="map" class="map" data-points="<?= e(json_encode($points)) ?>" data-url="<?= e(url('localisation-data.php?id=' . $cid)) ?>"
         data-update="<?= e(url('localisation-update.php')) ?>" data-csrf="<?= e(csrf_token()) ?>" data-sharing="<?= $iShare ? '1' : '0' ?>"
         data-places="<?= e(json_encode($mapPlaces)) ?>" data-tiles="<?= e(geo_tiles_base()) ?>" role="img" aria-label="Carte des positions partagées"></div>
    <p class="map-credit small">© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a></p>
    <noscript><p class="muted small">La carte demande JavaScript. Les positions sont listées ci-dessous.</p></noscript>
  </div>
  <p class="muted small" id="geo-status" aria-live="polite"></p>

  <div class="card mt">
    <?php if ($iShare): ?>
      <p><strong>Tu partages ta position</strong> <?= $myUntil === '' ? 'jusqu\'à ce que tu arrêtes' : 'jusqu\'à ' . e($myUntil) ?>. Garde l'appli ouverte pour qu'elle se mette à jour.</p>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="share_stop"><button class="btn btn-block" type="submit">Arrêter le partage maintenant</button></form>
    <?php else: ?>
      <p><strong>Partager ma position avec ce cercle</strong></p>
      <p class="muted small">Ton navigateur te demandera l'autorisation. Tu choisis la durée, ou « sans limite » jusqu'à ce que tu arrêtes.</p>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="share_start">
        <div class="chips">
          <label class="chip"><input type="radio" name="hours" value="1" checked><span>1 heure</span></label>
          <label class="chip"><input type="radio" name="hours" value="4"><span>4 heures</span></label>
          <label class="chip"><input type="radio" name="hours" value="8"><span>8 heures</span></label>
          <label class="chip"><input type="radio" name="hours" value="0"><span>Sans limite</span></label>
        </div>
        <button class="btn btn-primary btn-block mt" type="submit">Partager ma position</button>
      </form>
    <?php endif; ?>
  </div>

  <h2 class="section-h">En direct</h2>
  <div class="task-list" id="people">
    <?php if (!$points): ?><div class="card muted">Personne ne partage sa position pour le moment.</div><?php endif; ?>
    <?php foreach ($points as $p): ?>
      <div class="task"><span class="mini-av"><?= e(c_initial($p['name'])) ?></span>
        <span class="task-main"><strong><?= e($p['name']) ?><?= $p['me'] ? ' (toi)' : '' ?></strong><span class="sub"><?= e(ago_text($p['ago'])) ?></span></span></div>
    <?php endforeach; ?>
  </div>
<?php elseif ($tab === 'lieux'): ?>
  <div class="card">
    <h2>Lieux et rendez-vous</h2>
    <p class="muted small">Enregistre des lieux (Maison, École…) : quand quelqu'un qui partage sa position y arrive, le cercle est prévenu. Un point de rendez-vous apparaît sur la carte avec un lien d'itinéraire.</p>
    <form method="post" id="place-form" class="mt">
      <?= csrf_field() ?><?= once_field() ?><input type="hidden" name="action" value="place_add">
      <input type="hidden" name="lat" id="place-lat"><input type="hidden" name="lng" id="place-lng">
      <div class="chips">
        <label class="chip"><input type="radio" name="kind" value="place" checked><span>📍 Lieu</span></label>
        <label class="chip"><input type="radio" name="kind" value="meeting"><span>🚩 Rendez-vous</span></label>
      </div>
      <label class="field mt"><span>Nom</span><input class="input" type="text" name="name" maxlength="60" required placeholder="Maison, École, Parking…"></label>
      <label class="field mt"><span>Emoji (facultatif)</span><input class="input" type="text" name="emoji" maxlength="8" placeholder="🏠"></label>
      <button class="btn btn-block mt" type="button" id="place-locate">📍 Utiliser ma position actuelle</button>
      <p class="muted small" id="place-status" aria-live="polite">Va à l'endroit voulu, puis appuie sur le bouton.</p>
      <button class="btn btn-primary btn-block mt" type="submit" id="place-save" disabled>Ajouter</button>
    </form>
  </div>
  <div class="task-list mt">
    <?php if (!$places): ?><div class="card muted">Aucun lieu pour le moment.</div><?php endif; ?>
    <?php foreach ($places as $pl): $canDel = $isOwner || (int)$pl['created_by'] === $userId; ?>
      <div class="task"><span class="mini-av"><?= e($pl['emoji'] !== null && $pl['emoji'] !== '' ? $pl['emoji'] : ($pl['kind'] === 'meeting' ? '🚩' : '📍')) ?></span>
        <span class="task-main"><strong><?= e($pl['name']) ?></strong><span class="sub"><?= $pl['kind'] === 'meeting' ? 'Rendez-vous' : 'Lieu' ?></span></span>
        <a class="btn" target="_blank" rel="noopener" href="<?= e('https://www.google.com/maps/dir/?api=1&destination=' . $pl['lat'] . ',' . $pl['lng']) ?>">Itinéraire</a>
        <?php if ($canDel): ?>
        <form method="post" class="inline" data-confirm="Supprimer ce lieu ?"><?= csrf_field() ?><input type="hidden" name="action" value="place_delete"><input type="hidden" name="id" value="<?= (int)$pl['id'] ?>"><button class="icon-btn" type="submit" aria-label="Supprimer">✕</button></form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="card">
    <h2>Qui est dans ce cercle ?</h2>
    <div class="member-row"><span class="mini-av"><?= e(c_initial($isOwner ? $user['first_name'] : $ownerName)) ?></span><span><strong><?= e($isOwner ? ($user['first_name'] ?: 'Toi') : ($ownerName ?: 'Créateur')) ?></strong><span class="sub muted"><?= $isOwner ? 'Toi · créateur' : 'Créateur' ?></span></span>
      <?php if (!$isOwner): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="hide_toggle"><input type="hidden" name="user_id" value="<?= (int)$c['user_id'] ?>"><button class="btn" type="submit"><?= isset($hiddenFrom[(int)$c['user_id']]) ? '👻 Caché' : '👁 Visible' ?></button></form><?php endif; ?></div>
    <?php foreach ($members as $m): $isMe = (int)$m['id'] === $userId; ?>
      <div class="member-row"><span class="mini-av"><?= e(c_initial($m['first_name'])) ?></span>
        <span class="grow"><strong><?= e($m['first_name'] ?: 'Membre') ?></strong><span class="sub muted"><?= $isMe ? 'Toi · membre' : 'Membre' ?></span></span>
        <?php if (!$isMe): ?>
        <form method="post" class="inline">
          <?= csrf_field() ?><input type="hidden" name="action" value="hide_toggle"><input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>">
          <button class="btn" type="submit"><?= isset($hiddenFrom[(int)$m['id']]) ? '👻 Caché' : '👁 Visible' ?></button>
        </form>
        <?php endif; ?>
        <?php if ($isOwner): ?>
        <form method="post" class="inline" data-confirm="Retirer cette personne du cercle ?">
          <?= csrf_field() ?><input type="hidden" name="action" value="member_remove"><input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>">
          <button class="icon-btn" type="submit" aria-label="Retirer">✕</button>
        </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <p class="muted small mt">👻 <strong>Mode fantôme</strong> : « Caché » veut dire que cette personne ne voit plus ta position, sans être prévenue. Appuie encore pour la remontrer.</p>
    <p class="muted small mt">Chacun décide seul s'il partage sa position et pour combien de temps. Faire partie du cercle ne partage rien automatiquement.</p>
  </div>

  <?php if ($isOwner): ?>
  <div class="card mt">
    <h2>Inviter quelqu'un</h2>
    <p class="muted small">Crée un lien ou un code QR, envoie-le (WhatsApp par exemple). Il marche une seule fois et pendant 7 jours. La personne doit avoir un compte Lyvo et accepter.</p>
    <?php if ($newLink !== ''): ?>
      <div class="invite-box">
        <p class="small"><strong>Voilà ton lien</strong> (il ne sera plus affiché après cette page) :</p>
        <?php $qr = qr_svg($newLink, 220); if ($qr !== ''): ?>
        <div class="qr-box"><?= $qr ?><p class="muted small">Ou fais scanner ce code QR avec le téléphone de la personne.</p></div>
        <?php endif; ?>
        <input class="input" id="invite-link" type="text" readonly value="<?= e($newLink) ?>" aria-label="Lien d'invitation">
        <button class="btn btn-block mt" type="button" data-copy="invite-link">Copier le lien</button>
        <a class="btn btn-primary btn-block mt" target="_blank" rel="noopener" href="<?= e('https://wa.me/?text=' . rawurlencode('Rejoins le cercle « ' . $c['name'] . ' » sur Lyvo : ' . $newLink)) ?>">Envoyer sur WhatsApp</a>
      </div>
    <?php endif; ?>
    <form method="post" class="mt">
      <?= csrf_field() ?><?= once_field() ?><input type="hidden" name="action" value="invite_create">
      <button class="btn btn-primary btn-block" type="submit">Créer un lien d'invitation</button>
    </form>
    <?php foreach ($invites as $inv): ?>
      <div class="member-row"><span class="grow small muted">Invitation en attente · expire le <?= e(fr_date(substr($inv['expires_at'], 0, 10))) ?></span>
        <form method="post" class="inline" data-confirm="Annuler cette invitation ?">
          <?= csrf_field() ?><input type="hidden" name="action" value="invite_cancel"><input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
          <button class="icon-btn" type="submit" aria-label="Annuler l'invitation">✕</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
  <form method="post" class="mt" data-confirm="Supprimer ce cercle ? Les positions partagées seront effacées.">
    <?= csrf_field() ?><input type="hidden" name="action" value="circle_delete">
    <button class="btn btn-block" type="submit">Supprimer ce cercle</button>
  </form>
  <?php else: ?>
  <form method="post" class="mt" data-confirm="Quitter ce cercle ?">
    <?= csrf_field() ?><input type="hidden" name="action" value="circle_leave">
    <button class="btn btn-block" type="submit">Quitter ce cercle</button>
  </form>
  <?php endif; ?>
<?php endif; ?>
<?php
if ($tab === 'carte') {
    echo '<script src="' . e(asset_url('assets/map.js')) . '" defer></script>';
} elseif ($tab === 'lieux') {
    echo '<script src="' . e(asset_url('assets/places.js')) . '" defer></script>';
}
app_close('localisation.php');
