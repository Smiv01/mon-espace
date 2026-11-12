<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/qr.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/money.php';
require __DIR__ . '/includes/days.php';

$user = require_login();
$userId = (int)$user['id'];
days_purge();
$day = day_access($userId, opt($_GET, 'id', 0));
if (!$day) {
    flash('error', 'Cette journée est introuvable (ou elle a disparu).');
    redirect('journees.php');
}
$did = (int)$day['id'];
$isOwner = $day['role'] === 'owner';
$tab = opt($_GET, 'tab', '') === 'partage' ? 'partage' : 'fil';
$self = 'journee.php?id=' . $did;
$title = $day['kind'] === 'journal' ? 'Ma journée' : $day['title'];

if (is_post()) {
    if (!csrf_valid()) {
        flash('error', 'Session expirée. Recharge la page et réessaie.');
        redirect($self);
    }
    $action = (string)opt($_POST, 'action', '');
    $back = $self;
    if ($action === 'post_add') {
        $body = trim((string)opt($_POST, 'body', ''));
        $body = mb_substr($body, 0, 1000);
        $count = db()->prepare('SELECT COUNT(*) FROM day_posts WHERE day_id = ?');
        $count->execute([$did]);
        if (!once_valid()) {
            // double envoi : on ignore
        } elseif ((int)$count->fetchColumn() >= 300) {
            flash('error', 'Cette journée a déjà 300 souvenirs.');
        } else {
            list($photo, $err) = save_day_photo(isset($_FILES['photo']) ? $_FILES['photo'] : null);
            if ($err !== null) {
                flash('error', $err);
            } elseif ($body === '' && $photo === null) {
                flash('error', 'Écris un message ou choisis une photo.');
            } else {
                db()->prepare('INSERT INTO day_posts (day_id, user_id, body, photo_path, created_at) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$did, $userId, $body !== '' ? $body : null, $photo, now()]);
            }
        }
    } elseif ($action === 'post_delete') {
        $stmt = db()->prepare('SELECT id, user_id, photo_path FROM day_posts WHERE id = ? AND day_id = ?');
        $stmt->execute([post_id(), $did]);
        $p = $stmt->fetch();
        if ($p && ($isOwner || (int)$p['user_id'] === $userId)) {
            db()->prepare('DELETE FROM day_reactions WHERE post_id = ?')->execute([$p['id']]);
            db()->prepare('DELETE FROM day_posts WHERE id = ? AND day_id = ?')->execute([$p['id'], $did]);
            if ($p['photo_path']) {
                @unlink(storage_dir('days') . '/' . basename($p['photo_path']));
            }
            flash('ok', 'Souvenir supprimé.');
        }
    } elseif ($action === 'react') {
        $pid = post_id('post_id');
        $stmt = db()->prepare('SELECT id FROM day_posts WHERE id = ? AND day_id = ?');
        $stmt->execute([$pid, $did]);
        if ($stmt->fetchColumn()) {
            $has = db()->prepare('SELECT id FROM day_reactions WHERE post_id = ? AND user_id = ?');
            $has->execute([$pid, $userId]);
            $rid = $has->fetchColumn();
            if ($rid) {
                db()->prepare('DELETE FROM day_reactions WHERE id = ?')->execute([$rid]);
            } else {
                db()->prepare('INSERT INTO day_reactions (post_id, user_id) VALUES (?, ?)')->execute([$pid, $userId]);
            }
        }
    } elseif ($action === 'day_delete' && $isOwner) {
        day_remove($did);
        flash('ok', 'Journée supprimée.');
        redirect('journees.php');
    } elseif ($action === 'day_leave' && !$isOwner) {
        db()->prepare('DELETE FROM day_members WHERE day_id = ? AND user_id = ?')->execute([$did, $userId]);
        flash('ok', 'Tu as quitté cette journée.');
        redirect('journees.php');
    } elseif ($action === 'invite_create' && $isOwner) {
        $back = $self . '&tab=partage';
        $n1 = db()->prepare('SELECT COUNT(*) FROM day_invites WHERE day_id = ? AND used_at IS NULL AND expires_at > ?');
        $n1->execute([$did, gmdate('Y-m-d H:i:s')]);
        $n2 = db()->prepare('SELECT COUNT(*) FROM day_members WHERE day_id = ?');
        $n2->execute([$did]);
        if ((int)$n1->fetchColumn() >= 5) {
            flash('error', "Tu as déjà 5 invitations en attente. Annule-en une ou attends qu'elle soit utilisée.");
        } elseif ((int)$n2->fetchColumn() >= 20) {
            flash('error', 'Cette journée a déjà 20 participants.');
        } elseif (once_valid()) {
            $token = bin2hex(random_bytes(16));
            db()->prepare('INSERT INTO day_invites (day_id, created_by, token_hash, expires_at) VALUES (?, ?, ?, ?)')
                ->execute([$did, $userId, hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + 7 * 86400)]);
            $_SESSION['new_day_invite'] = ['day' => $did, 'token' => $token];
        }
    } elseif ($action === 'invite_cancel' && $isOwner) {
        $back = $self . '&tab=partage';
        db()->prepare('DELETE FROM day_invites WHERE id = ? AND day_id = ? AND used_at IS NULL')->execute([post_id(), $did]);
        flash('ok', 'Invitation annulée.');
    } elseif ($action === 'member_remove' && $isOwner) {
        $back = $self . '&tab=partage';
        db()->prepare('DELETE FROM day_members WHERE day_id = ? AND user_id = ?')->execute([$did, post_id('user_id')]);
        flash('ok', 'Participant retiré.');
    }
    redirect($back);
}

$stmt = db()->prepare(
    'SELECT p.id, p.user_id, p.body, p.photo_path, p.created_at, pr.first_name,
            (SELECT COUNT(*) FROM day_reactions r WHERE r.post_id = p.id) AS n_likes,
            (SELECT COUNT(*) FROM day_reactions r WHERE r.post_id = p.id AND r.user_id = ?) AS i_liked
       FROM day_posts p LEFT JOIN profiles pr ON pr.user_id = p.user_id
      WHERE p.day_id = ? ORDER BY p.id DESC LIMIT 300'
);
$stmt->execute([$userId, $did]);
$posts = $stmt->fetchAll();

$stmt = db()->prepare('SELECT u.id, p.first_name FROM day_members m JOIN users u ON u.id = m.user_id LEFT JOIN profiles p ON p.user_id = u.id WHERE m.day_id = ? ORDER BY m.id');
$stmt->execute([$did]);
$members = $stmt->fetchAll();
$stmt = db()->prepare('SELECT first_name FROM profiles WHERE user_id = ?');
$stmt->execute([(int)$day['user_id']]);
$ownerName = (string)$stmt->fetchColumn();
$shared = count($members) > 0 || !$isOwner;
$exp = day_expiry_text($day['expires_at']);

$invites = [];
$newLink = '';
if ($tab === 'partage' && $isOwner) {
    $stmt = db()->prepare('SELECT id, expires_at FROM day_invites WHERE day_id = ? AND used_at IS NULL AND expires_at > ? ORDER BY id DESC');
    $stmt->execute([$did, gmdate('Y-m-d H:i:s')]);
    $invites = $stmt->fetchAll();
    if (!empty($_SESSION['new_day_invite']) && (int)$_SESSION['new_day_invite']['day'] === $did) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $newLink = $scheme . '://' . (isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', $_SERVER['HTTP_HOST']) : 'localhost') . url('rejoindre.php?code=' . $_SESSION['new_day_invite']['token']);
        unset($_SESSION['new_day_invite']);
    }
}

function initial_of($name)
{
    return mb_strtoupper(mb_substr((string)$name !== '' ? (string)$name : '?', 0, 1));
}

app_open($title, 'journees.php', $user);
?>
<a class="back-link" href="<?= e(url('journees.php')) ?>">← Ma Journée</a>
<header class="page-head"><div>
  <h1><?= e($title) ?><?php if ($shared): ?> <span class="badge-shared">Partagé</span><?php endif; ?></h1>
  <p class="muted capitalize"><?= e(fr_date($day['day_date'], true)) ?><?= $day['place'] ? ' · ' . e($day['place']) : '' ?></p>
  <?php if ($exp !== ''): ?><p class="small late"><?= e($exp) ?></p><?php endif; ?>
</div></header>

<div class="tabs" role="tablist">
  <a class="tab<?= $tab === 'fil' ? ' on' : '' ?>" href="<?= e(url($self)) ?>">Souvenirs (<?= count($posts) ?>)</a>
  <a class="tab<?= $tab === 'partage' ? ' on' : '' ?>" href="<?= e(url($self . '&tab=partage')) ?>">Partage</a>
</div>

<?php if ($tab === 'fil'): ?>
  <form method="post" enctype="multipart/form-data" class="card compose">
    <?= csrf_field() ?><?= once_field() ?><input type="hidden" name="action" value="post_add">
    <textarea class="input" name="body" rows="2" maxlength="1000" placeholder="Un message, un souvenir…" aria-label="Message"></textarea>
    <div class="compose-row">
      <label class="btn compose-photo">📷 Photo<input class="file-hidden" type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label>
      <button class="btn btn-primary" type="submit">Publier</button>
    </div>
    <p class="muted small" id="photo-name"></p>
  </form>

  <div class="feed mt">
    <?php if (!$posts): ?><div class="card muted">Rien pour l'instant. Ajoute une photo ou un message.</div><?php endif; ?>
    <?php foreach ($posts as $p): $mine = (int)$p['user_id'] === $userId; ?>
      <article class="card post">
        <div class="post-head"><span class="mini-av"><?= e(initial_of($p['first_name'])) ?></span>
          <span class="grow"><strong><?= e($p['first_name'] ?: 'Quelqu\'un') ?><?= $mine ? ' (toi)' : '' ?></strong><span class="sub muted"><?= e(substr($p['created_at'], 11, 5)) ?> · <?= e(fr_date(substr($p['created_at'], 0, 10))) ?></span></span>
          <?php if ($mine || $isOwner): ?>
          <form method="post" class="inline" data-confirm="Supprimer ce souvenir ?">
            <?= csrf_field() ?><input type="hidden" name="action" value="post_delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="icon-btn" type="submit" aria-label="Supprimer">🗑</button>
          </form>
          <?php endif; ?>
        </div>
        <?php if ($p['photo_path']): ?><img class="post-photo" src="<?= e(url('journee-photo.php?id=' . (int)$p['id'])) ?>" alt="Photo de <?= e($p['first_name'] ?: 'la journée') ?>" loading="lazy"><?php endif; ?>
        <?php if ($p['body'] !== null && $p['body'] !== ''): ?><p class="post-body"><?= nl2br(e($p['body'])) ?></p><?php endif; ?>
        <form method="post" class="inline">
          <?= csrf_field() ?><input type="hidden" name="action" value="react"><input type="hidden" name="post_id" value="<?= (int)$p['id'] ?>">
          <button class="like<?= (int)$p['i_liked'] > 0 ? ' on' : '' ?>" type="submit" aria-label="J'aime">❤️ <span><?= (int)$p['n_likes'] ?></span></button>
        </form>
      </article>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="card">
    <h2>Qui participe ?</h2>
    <div class="member-row"><span class="mini-av"><?= e(initial_of($isOwner ? $user['first_name'] : $ownerName)) ?></span><span><strong><?= e($isOwner ? ($user['first_name'] ?: 'Toi') : ($ownerName ?: 'Créateur')) ?></strong><span class="sub muted"><?= $isOwner ? 'Toi · créateur' : 'Créateur' ?></span></span></div>
    <?php foreach ($members as $m): $isMe = (int)$m['id'] === $userId; ?>
      <div class="member-row"><span class="mini-av"><?= e(initial_of($m['first_name'])) ?></span>
        <span class="grow"><strong><?= e($m['first_name'] ?: 'Invité') ?></strong><span class="sub muted"><?= $isMe ? 'Toi · invité' : 'Invité' ?></span></span>
        <?php if ($isOwner): ?>
        <form method="post" class="inline" data-confirm="Retirer cette personne ?">
          <?= csrf_field() ?><input type="hidden" name="action" value="member_remove"><input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>">
          <button class="icon-btn" type="submit" aria-label="Retirer">✕</button>
        </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <p class="muted small mt">Tous les participants peuvent ajouter des photos et des messages et réagir. Chacun supprime ses propres souvenirs ; le créateur peut tout supprimer.</p>
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
        <a class="btn btn-primary btn-block mt" target="_blank" rel="noopener" href="<?= e('https://wa.me/?text=' . rawurlencode('Rejoins « ' . $title . ' » sur Lyvo : ' . $newLink)) ?>">Envoyer sur WhatsApp</a>
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
  <form method="post" class="mt" data-confirm="Supprimer cette journée avec toutes ses photos, pour tout le monde ?">
    <?= csrf_field() ?><input type="hidden" name="action" value="day_delete">
    <button class="btn btn-block" type="submit">Supprimer cette journée</button>
  </form>
  <?php else: ?>
  <form method="post" class="mt" data-confirm="Quitter cette journée ?">
    <?= csrf_field() ?><input type="hidden" name="action" value="day_leave">
    <button class="btn btn-block" type="submit">Quitter cette journée</button>
  </form>
  <?php endif; ?>
<?php endif; ?>
<?php app_close('journees.php');
