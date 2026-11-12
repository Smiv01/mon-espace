<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/money.php';
require __DIR__ . '/includes/days.php';

$user = require_login();
$userId = (int)$user['id'];
$today = today_for($user);
days_purge();

if (is_post()) {
    if (!csrf_valid()) {
        flash('error', 'Session expirée. Recharge la page et réessaie.');
        redirect('journees.php');
    }
    $action = (string)opt($_POST, 'action', '');
    if ($action === 'journal_open') {
        redirect('journee.php?id=' . day_journal_today($userId, $today));
    } elseif ($action === 'day_add' && once_valid()) {
        $title = post_str('title', 100);
        $date = post_str('day_date', 10);
        $place = post_str('place', 100);
        $temp = (string)opt($_POST, 'keep', 'forever') === 'temp';
        if ($date === '') {
            $date = $today;
        }
        $count = db()->prepare('SELECT COUNT(*) FROM days WHERE user_id = ?');
        $count->execute([$userId]);
        if ($title === '') {
            flash('error', 'Donne un nom à ta journée (par exemple « Sortie à la plage »).');
        } elseif (!valid_date($date)) {
            flash('error', 'Date invalide.');
        } elseif ((int)$count->fetchColumn() >= 200) {
            flash('error', 'Tu as atteint la limite de 200 journées. Supprime-en une ancienne.');
        } else {
            db()->prepare('INSERT INTO days (user_id, kind, title, day_date, place, expires_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$userId, 'album', $title, $date, $place !== '' ? $place : null, $temp ? gmdate('Y-m-d H:i:s', time() + 30 * 86400) : null]);
            flash('ok', 'Journée créée. Ajoute ta première photo ou ton premier message.');
            redirect('journee.php?id=' . (int)db()->lastInsertId());
        }
    }
    redirect('journees.php');
}

$days = days_visible($userId);
app_open('Ma Journée', 'journees.php', $user);
?>
<header class="page-hero">
  <img class="mod-ico" src="<?= e(url('assets/icons/journee.svg')) ?>" alt="" width="56" height="56">
  <div><h1>Ma Journée</h1><p class="muted">Tes souvenirs, seul ou à plusieurs</p></div>
</header>

<form method="post" class="card journal-card">
  <?= csrf_field() ?><input type="hidden" name="action" value="journal_open">
  <div><strong>Aujourd'hui</strong><p class="muted small capitalize"><?= e(fr_date($today, true)) ?></p></div>
  <button class="btn btn-primary" type="submit">Écrire ma journée</button>
</form>

<details class="card mt">
  <summary><strong>+ Nouvelle journée partagée</strong></summary>
  <form method="post" class="mt">
    <?= csrf_field() ?><?= once_field() ?><input type="hidden" name="action" value="day_add">
    <label class="field"><span>Nom de la journée</span><input class="input" type="text" name="title" maxlength="100" placeholder="Sortie à la plage" required></label>
    <label class="field"><span>Date</span><input class="input" type="date" name="day_date" value="<?= e($today) ?>"></label>
    <label class="field"><span>Lieu (facultatif)</span><input class="input" type="text" name="place" maxlength="100" placeholder="Grand-Bassam"></label>
    <div class="chips mt">
      <label class="chip"><input type="radio" name="keep" value="forever" checked><span>Garder pour toujours</span></label>
      <label class="chip"><input type="radio" name="keep" value="temp"><span>Disparaît après 30 jours</span></label>
    </div>
    <button class="btn btn-primary btn-block mt" type="submit">Créer la journée</button>
  </form>
</details>

<h2 class="section-h">Mes journées</h2>
<div class="task-list">
  <?php if (!$days): ?><div class="card muted">Aucune journée pour le moment. Écris ta journée d'aujourd'hui ou crée un album à partager.</div><?php endif; ?>
  <?php foreach ($days as $d): $mine = (int)$d['user_id'] === $userId; $shared = (int)$d['n_members'] > 0 || !$mine; $exp = day_expiry_text($d['expires_at']); ?>
    <a class="task day-row" href="<?= e(url('journee.php?id=' . (int)$d['id'])) ?>">
      <span class="task-main"><strong><?= e($d['kind'] === 'journal' ? 'Ma journée' : $d['title']) ?><?php if ($shared): ?> <span class="badge-shared">Partagé</span><?php endif; ?></strong>
        <span class="sub"><?= e(fr_date($d['day_date'])) ?><?= $d['place'] ? ' · ' . e($d['place']) : '' ?> · <?= (int)$d['n_posts'] ?> souvenir<?= (int)$d['n_posts'] > 1 ? 's' : '' ?><?= $exp !== '' ? ' · ' . e($exp) : '' ?></span></span>
    </a>
  <?php endforeach; ?>
</div>
<?php app_close('journees.php');
