<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/money.php';

$user = require_login();
$userId = (int)$user['id'];
$today = today_for($user);
$errors = [];
$openDebt = 0;

function owned_debt($userId, $id)
{
    $stmt = db()->prepare('SELECT * FROM debts WHERE id = ? AND user_id = ?');
    $stmt->execute([(int)$id, $userId]);
    return $stmt->fetch();
}

function debt_paid($debtId)
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(amount), 0) FROM debt_payments WHERE debt_id = ?');
    $stmt->execute([$debtId]);
    return (int)$stmt->fetchColumn();
}

if (is_post()) {
    if (!csrf_valid()) {
        flash('error', 'Session expirée. Recharge la page et réessaie.');
        redirect('dettes.php');
    }
    $action = (string)opt($_POST, 'action', '');

    if ($action === 'debt_add') {
        $dir = (string)opt($_POST, 'direction', 'i_owe') === 'owed_to_me' ? 'owed_to_me' : 'i_owe';
        $person = post_str('person', 100);
        $amount = parse_amount(opt($_POST, 'amount', ''));
        $due = post_str('due_date', 10);
        $note = post_str('note', 255);
        if ($person === '') {
            $errors['person'] = 'Indique la personne concernée.';
        }
        if ($amount === null) {
            $errors['amount'] = 'Montant invalide (chiffres, supérieur à 0).';
        }
        if ($due !== '' && !valid_date($due)) {
            $errors['due_date'] = 'Date invalide.';
        }
        if (!$errors) {
            if (once_valid()) {
                // Une dette n'touche pas la caisse : seul un remboursement confirmé le fait.
                db()->prepare('INSERT INTO debts (user_id, direction, person, amount, due_date, note) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$userId, $dir, $person, $amount, $due !== '' ? $due : null, $note !== '' ? $note : null]);
                flash('ok', 'Dette enregistrée. Elle ne modifie pas tes comptes.');
            }
            redirect('dettes.php');
        }
    } elseif ($action === 'pay') {
        $debt = owned_debt($userId, post_id('debt_id'));
        if (!$debt) {
            flash('error', 'Dette introuvable.');
            redirect('dettes.php');
        }
        $openDebt = (int)$debt['id'];
        $amount = parse_amount(opt($_POST, 'amount', ''));
        $date = post_str('date', 10);
        $accId = (int)opt($_POST, 'account_id', 0);
        $account = $accId > 0 ? owned_account($userId, $accId) : null;
        $remaining = (int)$debt['amount'] - debt_paid($debt['id']);
        if ($amount === null) {
            $errors['amount'] = 'Montant invalide (chiffres, supérieur à 0).';
        } elseif ($amount > $remaining) {
            $errors['amount'] = 'Ce montant dépasse le reste à payer (' . fcfa($remaining) . ').';
        }
        if (!valid_date($date)) {
            $errors['date'] = 'Date invalide.';
        }
        if ($accId > 0 && !$account) {
            $errors['account_id'] = 'Compte introuvable.';
        } elseif ($account && $debt['direction'] === 'i_owe' && $amount !== null && account_balance($userId, $account['id']) < $amount) {
            $errors['account_id'] = 'Solde insuffisant sur ce compte.';
        }
        if (!$errors) {
            if (once_valid()) {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('INSERT INTO debt_payments (debt_id, user_id, amount, paid_on) VALUES (?, ?, ?, ?)')
                        ->execute([$debt['id'], $userId, $amount, $date]);
                    if ($account) {
                        $isOut = $debt['direction'] === 'i_owe';
                        $pdo->prepare('INSERT INTO transactions (user_id, account_id, kind, amount, occurred_on, label) VALUES (?, ?, ?, ?, ?, ?)')
                            ->execute([$userId, $account['id'], $isOut ? 'expense' : 'income', $amount, $date,
                                ($isOut ? 'Remboursement à ' : 'Remboursement reçu de ') . $debt['person']]);
                    }
                    $pdo->commit();
                } catch (Exception $ex) {
                    $pdo->rollBack();
                    throw $ex;
                }
                flash('ok', 'Remboursement enregistré.' . ($account ? ' Il a aussi été ajouté à ton compte.' : ''));
            }
            redirect('dettes.php');
        }
    } elseif ($action === 'debt_delete') {
        db()->prepare('DELETE FROM debts WHERE id = ? AND user_id = ?')->execute([post_id(), $userId]);
        flash('ok', 'Dette supprimée (les écritures déjà ajoutées à tes comptes restent).');
        redirect('dettes.php');
    }
}

$stmt = db()->prepare('SELECT * FROM debts WHERE user_id = ? ORDER BY id DESC');
$stmt->execute([$userId]);
$debts = $stmt->fetchAll();
$accounts = array_values(array_filter(account_balances($userId), function ($a) { return !$a['archived']; }));
$sumOwe = 0;
$sumDue = 0;
foreach ($debts as $k => $d) {
    $debts[$k]['paid'] = debt_paid($d['id']);
    $debts[$k]['remaining'] = (int)$d['amount'] - $debts[$k]['paid'];
    if ($d['direction'] === 'i_owe') {
        $sumOwe += $debts[$k]['remaining'];
    } else {
        $sumDue += $debts[$k]['remaining'];
    }
}

app_open('Dettes', 'finances.php', $user);
?>
<a class="back-link" href="<?= e(url('finances.php')) ?>">← Budget</a>
<header class="page-head"><h1>Dettes et remboursements</h1></header>
<div class="two">
  <div class="pill pill-out"><span class="small">Je dois</span><strong><?= e(fcfa($sumOwe)) ?></strong></div>
  <div class="pill pill-in"><span class="small">On me doit</span><strong><?= e(fcfa($sumDue)) ?></strong></div>
</div>

<details class="card add-card mt"<?= ($errors && !$openDebt) ? ' open' : '' ?>>
  <summary>+ Ajouter une dette</summary>
  <form method="post" novalidate class="mt">
    <?= csrf_field() ?><?= once_field() ?><input type="hidden" name="action" value="debt_add">
    <div class="field"><span class="lbl">C'est quoi ?</span><div class="chips">
      <label class="chip"><input type="radio" name="direction" value="i_owe" checked><span>Je dois</span></label>
      <label class="chip"><input type="radio" name="direction" value="owed_to_me"><span>On me doit</span></label>
    </div></div>
    <?= amount_input($openDebt ? '' : (string)opt($_POST, 'amount', ''), $openDebt ? '' : (string)opt($errors, 'amount', ''), 'debt_amount') ?>
    <?php input_field('person', 'Quelle personne ?', 'text', ['value' => $openDebt ? '' : (string)opt($_POST, 'person', ''), 'required' => true, 'errors' => $openDebt ? [] : $errors, 'attrs' => 'maxlength="100"']); ?>
    <details class="more"><summary class="link">Plus d'options (échéance, note)</summary>
      <?php input_field('due_date', 'À rembourser avant le', 'date', ['value' => $openDebt ? '' : (string)opt($_POST, 'due_date', ''), 'errors' => $openDebt ? [] : $errors]); ?>
      <?php input_field('note', 'Note', 'text', ['value' => $openDebt ? '' : (string)opt($_POST, 'note', ''), 'attrs' => 'maxlength="255"']); ?>
    </details>
    <button class="btn btn-primary btn-block btn-lg" type="submit">Enregistrer</button>
  </form>
</details>

<div class="task-list mt">
  <?php if (!$debts): ?><div class="card muted">Aucune dette enregistrée.</div><?php endif; ?>
  <?php foreach ($debts as $d): $mine = $openDebt === (int)$d['id']; $settled = $d['remaining'] <= 0; ?>
    <div class="card">
      <div class="between"><strong><?= e($d['person']) ?></strong>
        <span class="badge"><?= $d['direction'] === 'i_owe' ? 'Je dois' : 'On me doit' ?><?= $settled ? ' · soldée' : '' ?></span></div>
      <p class="small muted">Montant initial <?= e(fcfa($d['amount'])) ?> · reste <strong><?= e(fcfa(max(0, $d['remaining']))) ?></strong><?= $d['due_date'] ? ' · échéance ' . e(fr_date($d['due_date'], true)) : '' ?><?= $d['note'] ? '<br>' . e($d['note']) : '' ?></p>
      <progress class="bar" max="<?= (int)$d['amount'] ?>" value="<?= min((int)$d['paid'], (int)$d['amount']) ?>"></progress>
      <?php if (!$settled): ?>
      <details class="mt"<?= $mine ? ' open' : '' ?>>
        <summary class="link">Enregistrer un remboursement</summary>
        <form method="post" novalidate class="mt">
          <?= csrf_field() ?><?= once_field() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="debt_id" value="<?= (int)$d['id'] ?>">
          <?= amount_input($mine ? (string)opt($_POST, 'amount', '') : '', $mine ? (string)opt($errors, 'amount', '') : '', 'pay_' . (int)$d['id']) ?>
          <div class="field"><span class="lbl">Le noter aussi dans mes comptes ?</span><div class="chips">
            <label class="chip"><input type="radio" name="account_id" value="0" checked><span>Non</span></label>
            <?php foreach ($accounts as $a): ?><label class="chip"><input type="radio" name="account_id" value="<?= (int)$a['id'] ?>"><span><?= e($d['direction'] === 'i_owe' ? 'Payé avec ' : 'Reçu sur ') . e($a['name']) ?></span></label><?php endforeach; ?>
          </div><?php if ($mine) { field_error($errors, 'account_id'); } ?></div>
          <details class="more"><summary class="link">Ce n'était pas aujourd'hui ?</summary>
            <?php input_field('date', 'Date', 'date', ['id' => 'pd_' . (int)$d['id'], 'value' => $mine ? (string)opt($_POST, 'date', $today) : $today, 'errors' => $mine ? $errors : []]); ?>
          </details>
          <button class="btn btn-primary btn-block btn-lg" type="submit">Enregistrer</button>
        </form>
      </details>
      <?php endif; ?>
      <form method="post" class="mt" data-confirm="Supprimer cette dette et son historique de remboursements ?">
        <?= csrf_field() ?><input type="hidden" name="action" value="debt_delete"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
        <button class="btn btn-block" type="submit">Supprimer</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
<?php app_close('finances.php');
