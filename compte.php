<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/money.php';

// Page d'un compte : on y ajoute de l'argent ou une dépense sans jamais créer un nouveau compte.
$user = require_login();
$userId = (int)$user['id'];
$id = (int)opt($_GET, 'id', 0);
$account = null;
$balance = 0;
foreach (account_balances($userId) as $a) {
    if ((int)$a['id'] === $id) {
        $account = $a;
        $balance = (int)$a['balance'];
    }
}
if (!$account) {
    flash('error', 'Compte introuvable.');
    redirect('comptes.php');
}

$stmt = db()->prepare(
    'SELECT t.*, a.name AS account_name, c.name AS cat_name FROM transactions t
       JOIN accounts a ON a.id = t.account_id LEFT JOIN categories c ON c.id = t.category_id
      WHERE t.user_id = ? AND t.account_id = ? ORDER BY t.occurred_on DESC, t.id DESC LIMIT 15'
);
$stmt->execute([$userId, $id]);
$rows = $stmt->fetchAll();
$types = account_types();

app_open($account['name'], 'finances.php', $user);
?>
<a class="back-link" href="<?= e(url('finances.php')) ?>">← Budget</a>
<div class="card center">
  <span class="badge"><?= e(opt($types, $account['type'], 'Autre')) ?></span>
  <h1><?= e($account['name']) ?></h1>
  <div class="big"><?= e(fcfa($balance)) ?></div>
</div>
<?php if (!$account['archived']): ?>
<div class="actions three mt">
  <a class="action" href="<?= e(url('ajouter.php?type=entree&compte=' . $id)) ?>"><span class="tile t-budget"><?= icon_svg('down') ?></span>Ajouter de l'argent</a>
  <a class="action" href="<?= e(url('ajouter.php?type=depense&compte=' . $id)) ?>"><span class="tile t-agenda"><?= icon_svg('up') ?></span>Dépense</a>
  <a class="action" href="<?= e(url('ajouter.php?type=transfert&compte=' . $id)) ?>"><span class="tile t-frigo"><?= icon_svg('swap') ?></span>Transfert</a>
</div>
<?php endif; ?>
<h2 class="section-h">Derniers mouvements</h2>
<div class="task-list">
  <?php if (!$rows): ?><div class="card muted">Aucun mouvement sur ce compte.</div><?php endif; ?>
  <?php foreach ($rows as $t) { echo tx_row($t); } ?>
</div>
<p class="mt center"><a href="<?= e(url('comptes.php')) ?>">Tous mes comptes</a></p>
<?php app_close('finances.php');
