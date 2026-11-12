<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$user = require_login();
app_open('Explorer', 'explorer.php', $user);
?>
<header class="page-head"><h1>Explorer</h1></header>
<?php $img = ['agenda.php' => 'agenda', 'finances.php' => 'budget', 'espaces.php' => 'frigo', 'journees.php' => 'journee', 'localisation.php' => 'loc', 'notes.php' => 'notes']; ?>
<div class="mod-grid explore">
  <?php foreach (module_list() as $m): ?>
    <a class="mod-card" href="<?= e(url($m[1])) ?>">
      <img class="mod-ico" src="<?= e(url('assets/icons/' . $img[$m[1]] . '.svg')) ?>" alt="" width="64" height="64">
      <strong><?= e($m[0]) ?></strong>
      <span class="sub"><?= e($m[4]) ?></span>
    </a>
  <?php endforeach; ?>
</div>
<?php app_close('explorer.php');
