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
$tab = opt($_GET, 'tab', '') === 'partage' ? 'partage' : 'carte';
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
        if (!in_array($hours, [1, 4, 8], true)) {
            $hours = 1;
        }
        $exp = gmdate('Y-m-d H:i:s', time() + $hours * 3600);
        $has = db()->prepare('SELECT id FROM location_shares WHERE user_id = ? AND circle_id = ?');
        $has->execute([$userId, $cid]);
        $sid = $has->fetchColumn();
        if ($sid) {
            db()->prepare('UPDATE location_shares SET expires_at = ? WHERE id = ?')->execute([$exp, $sid]);
        } else {
            db()->prepare('INSERT INTO location_shares (user_id, circle_id, expires_at) VALUES (?, ?, ?)')->execute([$userId, $cid, $exp]);
        }
        flash('ok', 'Tu partages ta position avec ce cercle pendant ' . $hours . ' h. Garde cette page ouverte pour qu\'elle se mette à jour.');
    } elseif ($action === 'share_stop') {
        db()->prepare('DELETE FROM location_shares WHERE user_id = ? AND circle_id = ?')->execute([$userId, $cid]);
        flash('ok', 'Partage arrêté. Ta position a été effacée.');
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
</div>

<?php if ($tab === 'carte'): ?>
  <div class="card map-card">
    <div id="map" class="map" data-points="<?= e(json_encode($points)) ?>" data-url="<?= e(url('localisation-data.php?id=' . $cid)) ?>"
         data-update="<?= e(url('localisation-update.php')) ?>" data-csrf="<?= e(csrf_token()) ?>" data-sharing="<?= $iShare ? '1' : '0' ?>"
         data-tiles="https://tile.openstreetmap.org" role="img" aria-label="Carte des positions partagées"></div>
    <p class="map-credit small">© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a></p>
    <noscript><p class="muted small">La carte demande JavaScript. Les positions sont listées ci-dessous.</p></noscript>
  </div>
  <p class="muted small" id="geo-status" aria-live="polite"></p>

  <div class="card mt">
    <?php if ($iShare): ?>
      <p><strong>Tu partages ta position</strong> jusqu'à <?= e($myUntil) ?>. Garde cette page ouverte pour qu'elle se mette à jour.</p>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="share_stop"><button class="btn btn-block" type="submit">Arrêter le partage maintenant</button></form>
    <?php else: ?>
      <p><strong>Partager ma position avec ce cercle</strong></p>
      <p class="muted small">Ton navigateur te demandera l'autorisation. Tu choisis la durée ; le partage s'arrête tout seul.</p>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="share_start">
        <div class="chips">
          <label class="chip"><input type="radio" name="hours" value="1" checked><span>1 heure</span></label>
          <label class="chip"><input type="radio" name="hours" value="4"><span>4 heures</span></label>
          <label class="chip"><input type="radio" name="hours" value="8"><span>8 heures</span></label>
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
<?php else: ?>
  <div class="card">
    <h2>Qui est dans ce cercle ?</h2>
    <div class="member-row"><span class="mini-av"><?= e(c_initial($isOwner ? $user['first_name'] : $ownerName)) ?></span><span><strong><?= e($isOwner ? ($user['first_name'] ?: 'Toi') : ($ownerName ?: 'Créateur')) ?></strong><span class="sub muted"><?= $isOwner ? 'Toi · créateur' : 'Créateur' ?></span></span></div>
    <?php foreach ($members as $m): $isMe = (int)$m['id'] === $userId; ?>
      <div class="member-row"><span class="mini-av"><?= e(c_initial($m['first_name'])) ?></span>
        <span class="grow"><strong><?= e($m['first_name'] ?: 'Membre') ?></strong><span class="sub muted"><?= $isMe ? 'Toi · membre' : 'Membre' ?></span></span>
        <?php if ($isOwner): ?>
        <form method="post" class="inline" data-confirm="Retirer cette personne du cercle ?">
          <?= csrf_field() ?><input type="hidden" name="action" value="member_remove"><input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>">
          <button class="icon-btn" type="submit" aria-label="Retirer">✕</button>
        </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <p class="muted small mt">Chacun décide seul s'il partage sa position et pour combien de temps. Faire partie du cercle ne partage rien automatiquement.</p>
  </div>

  <?php if ($isOwner): ?>
  <div class="card mt">
    <h2>Inviter quelqu'un</h2>
    <p class="muted small">Crée un lien ou un code QR, envoie-le (WhatsApp par exemple). Il marche une seule fois et pendant 7 jours. La personne doit avoir un compte Mon Espace et accepter.</p>
    <?php if ($newLink !== ''): ?>
      <div class="invite-box">
        <p class="small"><strong>Voilà ton lien</strong> (il ne sera plus affiché après cette page) :</p>
        <?php $qr = qr_svg($newLink, 220); if ($qr !== ''): ?>
        <div class="qr-box"><?= $qr ?><p class="muted small">Ou fais scanner ce code QR avec le téléphone de la personne.</p></div>
        <?php endif; ?>
        <input class="input" id="invite-link" type="text" readonly value="<?= e($newLink) ?>" aria-label="Lien d'invitation">
        <button class="btn btn-block mt" type="button" data-copy="invite-link">Copier le lien</button>
        <a class="btn btn-primary btn-block mt" target="_blank" rel="noopener" href="<?= e('https://wa.me/?text=' . rawurlencode('Rejoins le cercle « ' . $c['name'] . ' » sur Mon Espace : ' . $newLink)) ?>">Envoyer sur WhatsApp</a>
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
    echo '<script src="' . e(url('assets/map.js')) . '" defer></script>';
}
app_close('localisation.php');
