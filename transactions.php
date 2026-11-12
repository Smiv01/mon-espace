<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/money.php';

$user = require_login();
$userId = (int)$user['id'];
$today = today_for($user);

if (is_post()) {
    if (!csrf_valid()) {
        flash('error', 'Session expirée. Recharge la page et réessaie.');
        redirect('transactions.php');
    }
    if ((string)opt($_POST, 'action', '') === 'tx_delete') {
        $stmt = db()->prepare('SELECT id, transfer_group, goal_id FROM transactions WHERE id = ? AND user_id = ?');
        $stmt->execute([post_id(), $userId]);
        $t = $stmt->fetch();
        if ($t && $t['goal_id'] !== null) {
            flash('error', "Cette écriture appartient à un objectif d'épargne : gère-la depuis la page Épargne.");
        } elseif ($t) {
            // Un transfert entre comptes se supprime en entier (les deux écritures liées).
            if ($t['transfer_group'] !== null && $t['transfer_group'] !== '') {
                db()->prepare('DELETE FROM transactions WHERE transfer_group = ? AND user_id = ?')->execute([$t['transfer_group'], $userId]);
            } else {
                db()->prepare('DELETE FROM transactions WHERE id = ? AND user_id = ?')->execute([$t['id'], $userId]);
            }
            flash('ok', 'Écriture supprimée.');
        }
        redirect('transactions.php?' . http_build_query(['mois' => (string)opt($_POST, 'mois', '')]));
    }
}

$mois = (string)opt($_GET, 'mois', substr($today, 0, 7));
if (!preg_match('/^\d{4}-\d{2}$/', $mois) || !valid_date($mois . '-01')) {
    $mois = substr($today, 0, 7);
}
list($m1, $m2) = month_bounds($mois . '-01');
$prev = DateTime::createFromFormat('Y-m-d', $m1)->modify('-1 month')->format('Y-m');
$next = DateTime::createFromFormat('Y-m-d', $m1)->modify('+1 month')->format('Y-m');

$stmt = db()->prepare(
    'SELECT t.*, a.name AS account_name, c.name AS cat_name FROM transactions t
       JOIN accounts a ON a.id = t.account_id LEFT JOIN categories c ON c.id = t.category_id
      WHERE t.user_id = ? AND t.occurred_on >= ? AND t.occurred_on < ? ORDER BY t.occurred_on DESC, t.id DESC LIMIT 500'
);
$stmt->execute([$userId, $m1, $m2]);
$rows = $stmt->fetchAll();

$stmt = db()->prepare("SELECT kind, SUM(amount) FROM transactions WHERE user_id = ? AND occurred_on >= ? AND occurred_on < ? AND kind IN ('income','expense') GROUP BY kind");
$stmt->execute([$userId, $m1, $m2]);
$sums = ['income' => 0, 'expense' => 0];
foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $r) {
    $sums[$r[0]] = (int)$r[1];
}

// Dépenses par catégorie sur le mois (sans les transferts).
$stmt = db()->prepare(
    "SELECT COALESCE(c.name, 'Sans catégorie') AS name, SUM(t.amount) AS total
       FROM transactions t LEFT JOIN categories c ON c.id = t.category_id
      WHERE t.user_id = ? AND t.kind = 'expense' AND t.occurred_on >= ? AND t.occurred_on < ?
      GROUP BY COALESCE(c.name, 'Sans catégorie') ORDER BY total DESC"
);
$stmt->execute([$userId, $m1, $m2]);
$byCat = $stmt->fetchAll();

$months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$label = $months[(int)substr($mois, 5, 2) - 1] . ' ' . substr($mois, 0, 4);

app_open('Historique', 'finances.php', $user);
?>
<a class="back-link" href="<?= e(url('finances.php')) ?>">← Budget</a>
<header class="page-head"><h1>Historique</h1></header>
<div class="between mt">
  <a class="btn" href="<?= e(url('transactions.php?mois=' . $prev)) ?>" aria-label="Mois précédent">←</a>
  <strong class="capitalize"><?= e($label) ?></strong>
  <a class="btn" href="<?= e(url('transactions.php?mois=' . $next)) ?>" aria-label="Mois suivant">→</a>
</div>
<div class="two mt">
  <div class="pill pill-in"><span class="small">Entrées</span><strong><?= e(fcfa($sums['income'])) ?></strong></div>
  <div class="pill pill-out"><span class="small">Dépenses</span><strong><?= e(fcfa($sums['expense'])) ?></strong></div>
</div>
<p class="muted small">Solde du mois : <?= e(fcfa($sums['income'] - $sums['expense'])) ?>. Les transferts et l'épargne ne sont pas comptés.</p>

<?php if ($byCat): ?>
  <h2 class="section-h">Dépenses par catégorie</h2>
  <div class="card stack">
    <?php foreach ($byCat as $c): $tot = max(1, $sums['expense']); ?>
      <div><div class="between"><span><?= e($c['name']) ?></span><strong><?= e(fcfa($c['total'])) ?></strong></div>
        <progress class="bar" max="<?= (int)$tot ?>" value="<?= (int)$c['total'] ?>"></progress></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<h2 class="section-h">Écritures</h2>
<div class="task-list">
  <?php if (!$rows): ?><div class="card muted">Aucune écriture ce mois-ci.</div><?php endif; ?>
  <?php foreach ($rows as $t): ?>
    <?php if ($t['goal_id'] === null): echo tx_row($t, true); else: echo tx_row($t, false); endif; ?>
  <?php endforeach; ?>
</div>
<?php app_close('finances.php');
