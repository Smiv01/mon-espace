<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/money.php';

$user = require_login();
$userId = (int)$user['id'];
$today = today_for($user);
$id = (int)opt($_GET, 'id', 0);

$stmt = db()->prepare('SELECT * FROM savings_goals WHERE id = ? AND user_id = ?');
$stmt->execute([$id, $userId]);
$goal = $stmt->fetch();
if (!$goal) {
    flash('error', 'Objectif introuvable.');
    redirect('epargne.php');
}
$accounts = active_accounts($userId);
$saved = goal_saved($userId, $id);
$errors = [];

if (is_post()) {
    if (!csrf_valid()) {
        flash('error', 'Session expirée. Recharge la page et réessaie.');
        redirect('objectif.php?id=' . $id);
    }
    $action = (string)opt($_POST, 'action', '');

    if ($action === 'deposit' || $action === 'withdraw') {
        $amount = parse_amount(opt($_POST, 'amount', ''));
        $account = owned_account($userId, opt($_POST, 'account_id', 0));
        if ($amount === null) {
            $errors['amount'] = 'Écris un montant (chiffres seulement).';
        }
        if (!$account) {
            $errors['account_id'] = 'Choisis un compte.';
        }
        if (!$errors) {
            if ($action === 'deposit' && account_balance($userId, $account['id']) < $amount) {
                $errors['amount'] = 'Il n\'y a pas assez d\'argent sur ' . $account['name'] . '.';
            } elseif ($action === 'withdraw' && $saved < $amount) {
                $errors['amount'] = 'Tu as seulement ' . fcfa($saved) . ' de côté.';
            }
        }
        if (!$errors) {
            if (once_valid()) {
                // Mettre de côté / reprendre : l'argent change de place, ce n'est ni un revenu ni une dépense.
                db()->prepare('INSERT INTO transactions (user_id, account_id, kind, amount, occurred_on, label, goal_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$userId, $account['id'], $action === 'deposit' ? 'transfer_out' : 'transfer_in', $amount, $today,
                        ($action === 'deposit' ? 'Épargne : ' : 'Retrait épargne : ') . $goal['name'], $id]);
                flash('ok', $action === 'deposit' ? fcfa($amount) . ' mis de côté.' : fcfa($amount) . ' remis sur ' . $account['name'] . '.');
            }
            redirect('objectif.php?id=' . $id);
        }
    } elseif ($action === 'goal_delete') {
        if ($saved !== 0) {
            flash('error', 'Reprends d\'abord l\'argent mis de côté avant de supprimer cet objectif.');
            redirect('objectif.php?id=' . $id);
        }
        db()->prepare('DELETE FROM savings_goals WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
        flash('ok', 'Objectif supprimé.');
        redirect('epargne.php');
    }
}

$pct = (int)$goal['target_amount'] > 0 ? min(100, (int)floor($saved * 100 / $goal['target_amount'])) : 0;
app_open($goal['name'], 'finances.php', $user);
?>
<a class="back-link" href="<?= e(url('epargne.php')) ?>">← Épargne</a>
<div class="card center">
  <h1><?= e($goal['name']) ?></h1>
  <div class="big"><?= e(fcfa($saved)) ?></div>
  <p class="muted small">sur <?= e(fcfa($goal['target_amount'])) ?><?= $goal['due_date'] ? ' · avant le ' . e(fr_date($goal['due_date'], true)) : '' ?></p>
  <progress class="bar" max="<?= (int)$goal['target_amount'] ?>" value="<?= min($saved, (int)$goal['target_amount']) ?>"></progress>
  <p class="small"><?= $pct >= 100 ? 'Objectif atteint, bravo !' : 'Encore ' . e(fcfa((int)$goal['target_amount'] - $saved)) . ' pour y arriver.' ?></p>
</div>

<?php if (!$accounts): ?>
  <div class="card mt center">Pour mettre de l'argent de côté, dis d'abord où il se trouve.
    <div class="mt"><a class="btn btn-primary btn-block" href="<?= e(url('finances.php')) ?>">Ajouter un compte</a></div></div>
<?php else: ?>
  <form method="post" novalidate class="send-form mt">
    <?= csrf_field() ?><?= once_field() ?>
    <?= amount_input((string)opt($_POST, 'amount', ''), (string)opt($errors, 'amount', '')) ?>
    <?= account_picker($accounts, 'account_id', (int)opt($_POST, 'account_id', 0)) ?>
    <?php field_error($errors, 'account_id'); ?>
    <div class="two">
      <button class="btn btn-primary btn-lg" type="submit" name="action" value="deposit">Mettre de côté</button>
      <button class="btn btn-lg" type="submit" name="action" value="withdraw">Reprendre</button>
    </div>
  </form>
<?php endif; ?>

<form method="post" class="mt" data-confirm="Supprimer cet objectif ?">
  <?= csrf_field() ?><input type="hidden" name="action" value="goal_delete">
  <button class="btn btn-ghost btn-block" type="submit">Supprimer cet objectif</button>
</form>
<?php app_close('finances.php');
