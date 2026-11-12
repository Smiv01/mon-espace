<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/money.php';
require __DIR__ . '/includes/stock.php';

$user = require_login();
$userId = (int)$user['id'];
$today = today_for($user);
$errors = [];
$kinds = space_kinds();

if (is_post()) {
    if (!csrf_valid()) {
        flash('error', 'Session expirée. Recharge la page et réessaie.');
        redirect('espaces.php');
    }
    if ((string)opt($_POST, 'action', '') === 'space_add') {
        $kind = (string)opt($_POST, 'kind', 'fridge');
        if (!isset($kinds[$kind])) {
            $kind = 'fridge';
        }
        $name = post_str('name', 80);
        if ($name === '') {
            $name = $kinds[$kind];
        }
        if (once_valid()) {
            db()->prepare('INSERT INTO spaces (user_id, name, kind) VALUES (?, ?, ?)')->execute([$userId, $name, $kind]);
            $newId = (int)db()->lastInsertId();
            flash('ok', '« ' . $name . ' » est prêt. Ajoute ce qu\'il contient.');
            redirect('espace.php?id=' . $newId);
        }
        redirect('espaces.php');
    }
}

$soonDate = date('Y-m-d', strtotime($today . ' 12:00:00 UTC') + 3 * 86400);
$stmt = db()->prepare(
    'SELECT s.id, s.name, s.kind, s.user_id,
            (SELECT COUNT(*) FROM space_items i WHERE i.space_id = s.id) AS n_items,
            (SELECT COUNT(*) FROM space_items i WHERE i.space_id = s.id AND i.expires_on IS NOT NULL AND i.expires_on <= ?) AS n_watch,
            (SELECT COUNT(*) FROM shopping_items c WHERE c.space_id = s.id) AS n_shop,
            (SELECT COUNT(*) FROM space_members m WHERE m.space_id = s.id) AS n_members
       FROM spaces s
      WHERE s.user_id = ? OR s.id IN (SELECT space_id FROM space_members WHERE user_id = ?)
      ORDER BY s.id'
);
$stmt->execute([$soonDate, $userId, $userId]);
$spaces = $stmt->fetchAll();

app_open('Frigos', 'espaces.php', $user);
?>
<header class="page-head"><h1>Mes frigos</h1></header>

<div class="task-list">
  <?php if (!$spaces): ?><div class="card muted">Rien pour le moment. Crée ton premier frigo, congélateur ou placard ci-dessous.</div><?php endif; ?>
  <?php foreach ($spaces as $sp): ?>
    <a class="note-card space-card" href="<?= e(url('espace.php?id=' . (int)$sp['id'])) ?>">
      <img class="space-ico" src="<?= e(url('assets/icons/frigo.svg')) ?>" alt="" width="48" height="48">
      <span class="space-body">
        <strong><?= e($sp['name']) ?><?php if ($sp['n_members'] > 0 || (int)$sp['user_id'] !== $userId): ?> <span class="badge-shared">Partagé</span><?php endif; ?></strong>
        <span class="sub"><?= e($kinds[$sp['kind']]) ?> · <?= (int)$sp['n_items'] ?> article<?= $sp['n_items'] > 1 ? 's' : '' ?><?= $sp['n_shop'] > 0 ? ' · ' . (int)$sp['n_shop'] . ' à acheter' : '' ?></span>
        <?php if ($sp['n_watch'] > 0): ?><span class="sub late"><?= (int)$sp['n_watch'] ?> à surveiller (bientôt périmé)</span><?php endif; ?>
      </span>
      <span class="chev">›</span>
    </a>
  <?php endforeach; ?>
</div>

<details class="card add-card mt"<?= !$spaces ? ' open' : '' ?>>
  <summary>+ Nouveau frigo / placard</summary>
  <form method="post" novalidate class="mt">
    <?= csrf_field() ?><?= once_field() ?><input type="hidden" name="action" value="space_add">
    <div class="field"><span class="lbl">C'est quoi ?</span><div class="chips">
      <?php $first = true; foreach ($kinds as $k => $l): ?><label class="chip"><input type="radio" name="kind" value="<?= e($k) ?>"<?= $first ? ' checked' : '' ?>><span><?= e($l) ?></span></label><?php $first = false; endforeach; ?>
    </div></div>
    <?php input_field('name', 'Nom (facultatif)', 'text', ['value' => '', 'attrs' => 'maxlength="80" placeholder="Ex. Frigo de la cuisine"']); ?>
    <button class="btn btn-primary btn-block btn-lg" type="submit">Créer</button>
  </form>
</details>
<?php app_close('espaces.php');
