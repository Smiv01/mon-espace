<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/money.php';

$user = require_login();

$tz = new DateTimeZone($user['timezone'] ?: 'Africa/Abidjan');
$nowLocal = new DateTime('now', $tz);
$days = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
$months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$today = $days[(int)$nowLocal->format('w')] . ' ' . $nowLocal->format('j') . ' '
    . $months[(int)$nowLocal->format('n') - 1] . ' ' . $nowLocal->format('Y');
$greeting = (int)$nowLocal->format('G') < 18 ? 'Bonjour' : 'Bonsoir';
$firstName = $user['first_name'] ?: 'toi';

// Vrais chiffres, uniquement quand il y a des données (rien d'inventé).
$uid = (int)$user['id'];
$todayYmd = today_for($user);
$subs = [];
$st = db()->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status = 'todo' AND due_date IS NOT NULL AND due_date <= ?");
$st->execute([$uid, $todayYmd]);
$nTasks = (int)$st->fetchColumn();
$st = db()->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status = 'todo'");
$st->execute([$uid]);
$nOpen = (int)$st->fetchColumn();
if ($nTasks > 0) {
    $subs['agenda.php'] = $nTasks . ($nTasks > 1 ? ' tâches aujourd\'hui' : ' tâche aujourd\'hui');
} elseif ($nOpen > 0) {
    $subs['agenda.php'] = $nOpen . ($nOpen > 1 ? ' tâches à faire' : ' tâche à faire');
}
$balances = account_balances($uid);
$act = array_filter($balances, function ($a) { return !$a['archived']; });
if ($act) {
    $tot = 0;
    foreach ($act as $a) {
        $tot += $a['balance'];
    }
    $subs['finances.php'] = 'Solde : ' . fcfa($tot);
}
$st = db()->prepare('SELECT COUNT(*) FROM notes WHERE user_id = ? AND archived = 0');
$st->execute([$uid]);
$nNotes = (int)$st->fetchColumn();
if ($nNotes > 0) {
    $subs['notes.php'] = $nNotes . ($nNotes > 1 ? ' notes' : ' note');
}

// Programme du jour : vraies tâches à faire aujourd'hui (ou en retard).
$st = db()->prepare("SELECT title, due_date, due_time FROM tasks WHERE user_id = ? AND status = 'todo' AND due_date IS NOT NULL AND due_date <= ? ORDER BY due_date, due_time IS NULL, due_time, id LIMIT 4");
$st->execute([$uid, $todayYmd]);
$plan = $st->fetchAll();
$soon = [];
$mods = ['agenda.php' => 'agenda', 'finances.php' => 'budget', 'espaces.php' => 'frigo', 'journees.php' => 'journee', 'localisation.php' => 'loc', 'notes.php' => 'notes'];

$soonDate = date('Y-m-d', strtotime($todayYmd . ' 12:00:00 UTC') + 3 * 86400);
$mySpaces = '(SELECT id FROM spaces WHERE user_id = ' . $uid . ' UNION SELECT space_id FROM space_members WHERE user_id = ' . $uid . ')';
$st = db()->prepare('SELECT COUNT(*) FROM space_items WHERE space_id IN ' . $mySpaces . ' AND expires_on IS NOT NULL AND expires_on <= ?');
$st->execute([$soonDate]);
$nWatch = (int)$st->fetchColumn();
$st = db()->prepare('SELECT COUNT(*) FROM space_items WHERE space_id IN ' . $mySpaces);
$st->execute([]);
$nStock = (int)$st->fetchColumn();
if ($nWatch > 0) {
    $subs['espaces.php'] = $nWatch . ($nWatch > 1 ? ' produits à surveiller' : ' produit à surveiller');
} elseif ($nStock > 0) {
    $subs['espaces.php'] = $nStock . ($nStock > 1 ? ' produits en stock' : ' produit en stock');
}

app_open('Accueil', 'accueil.php', $user, true);
?>
<header class="home-head">
  <a href="<?= e(url('profil.php')) ?>" aria-label="Ouvrir le profil"><?= avatar_html($user, 'hero-avatar') ?></a>
  <div class="hero-grow">
    <p class="muted small">Bonjour,</p>
    <h1><?= e($firstName) ?></h1>
  </div>
  <p class="capitalize small muted home-date"><?= e($today) ?></p>
</header>

<?php render_flashes(); ?>
<section class="card today" aria-label="Programme du jour">
  <div class="between"><h2>Aujourd'hui</h2><a class="small" href="<?= e(url('agenda.php')) ?>">Voir l'agenda ›</a></div>
  <?php if (!$plan): ?>
    <p class="muted">Rien de prévu pour aujourd'hui. <a href="<?= e(url('agenda.php')) ?>">Ajouter une tâche</a></p>
  <?php else: foreach ($plan as $t): $late = $t['due_date'] < $todayYmd; ?>
    <div class="plan-row"><span class="plan-dot<?= $late ? ' late' : '' ?>"></span><span class="plan-title"><?= e($t['title']) ?></span>
      <span class="small <?= $late ? 'late' : 'muted' ?>"><?= $late ? 'En retard' : e($t['due_time'] ? substr($t['due_time'], 0, 5) : 'Dans la journée') ?></span></div>
  <?php endforeach; endif; ?>
</section>

<h2 class="section-h">Mes espaces</h2>
<section class="mod-grid" aria-label="Modules">
  <?php foreach (module_list() as $m): $ico = $mods[$m[1]]; ?>
    <a class="mod-card" href="<?= e(url($m[1])) ?>">
      <img class="mod-ico" src="<?= e(url('assets/icons/' . $ico . '.svg')) ?>" alt="" width="64" height="64">
      <strong><?= e($m[0]) ?></strong>
      <span class="sub"><?= isset($soon[$m[1]]) ? 'Bientôt' : e(opt($subs, $m[1], $m[4])) ?></span>
    </a>
  <?php endforeach; ?>
</section>
<?php app_close('accueil.php', true);
