<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/money.php';
require __DIR__ . '/includes/notifs.php';

$user = require_login();
$today = today_for($user);
$list = notifications_for($user, $today);
$groups = [];
foreach ($list as $n) {
    $groups[$n[0]][] = $n;
}
$order = ['Frigos', 'Agenda', 'Budget'];
$icons = ['Frigos' => 'frigo', 'Agenda' => 'agenda', 'Budget' => 'budget'];

app_open('Notifications', 'notifications.php', $user);
?>
<header class="page-hero">
  <div><h1>Notifications</h1><p class="muted">Ce qui demande ton attention</p></div>
</header>

<?php if (!$list): ?>
  <div class="card muted">Rien à signaler. Tes produits, tes tâches et ton budget sont à jour.</div>
<?php endif; ?>

<?php foreach ($order as $g): if (empty($groups[$g])) { continue; } ?>
  <h2 class="section-h notif-h"><img src="<?= e(url('assets/icons/' . $icons[$g] . '.svg')) ?>" alt="" width="22" height="22"><?= e($g) ?></h2>
  <div class="task-list">
    <?php foreach ($groups[$g] as $n): ?>
      <a class="task notif notif-<?= e($n[1]) ?>" href="<?= e(url($n[4])) ?>">
        <span class="plan-dot<?= $n[1] === 'late' ? ' late' : '' ?>"></span>
        <span class="task-main"><strong><?= e($n[2]) ?></strong><span class="sub <?= $n[1] === 'late' ? 'late' : '' ?>"><?= e($n[3]) ?></span></span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<?php
app_close('notifications.php');
