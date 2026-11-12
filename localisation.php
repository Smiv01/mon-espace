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
    if ((string)opt($_POST, 'action', '') === 'circle_add' && once_valid()) {
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

app_open('Localisation', 'localisation.php', $user);
?>
<header class="page-hero">
  <img class="mod-ico" src="<?= e(url('assets/icons/loc.svg')) ?>" alt="" width="56" height="56">
  <div><h1>Localisation</h1><p class="muted">Ta position, seulement quand tu le décides</p></div>
</header>

<div class="card muted small">🔒 Rien n'est partagé par défaut. Tu choisis un cercle de proches, une durée (1 h, 4 h ou 8 h), et tu peux arrêter à tout moment. Seule ta dernière position est gardée, puis effacée à la fin du partage.</div>

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
<?php app_close('localisation.php');
