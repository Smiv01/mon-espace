<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/money.php';

$user = require_login();
$userId = (int)$user['id'];
$errors = [];
$types = account_types();

if (is_post()) {
    if (!csrf_valid()) {
        flash('error', 'Session expirée. Recharge la page et réessaie.');
        redirect('comptes.php');
    }
    $action = (string)opt($_POST, 'action', '');
    if ($action === 'add') {
        $name = post_str('name', 80);
        $type = (string)opt($_POST, 'type', 'cash');
        $init = parse_amount_zero(opt($_POST, 'initial_balance', '0'));
        if ($name === '') {
            $errors['name'] = 'Donne un nom au compte.';
        }
        if (!isset($types[$type])) {
            $type = 'other';
        }
        if ($init === null) {
            $errors['initial_balance'] = 'Montant invalide (chiffres uniquement).';
        }
        if (!$errors && once_valid()) {
            db()->prepare('INSERT INTO accounts (user_id, name, type, initial_balance) VALUES (?, ?, ?, ?)')
                ->execute([$userId, $name, $type, $init]);
            flash('ok', 'Compte créé.');
            redirect('comptes.php');
        } elseif (!$errors) {
            redirect('comptes.php');
        }
    } elseif ($action === 'rename') {
        $name = post_str('name', 80);
        if ($name !== '') {
            db()->prepare('UPDATE accounts SET name = ? WHERE id = ? AND user_id = ?')->execute([$name, post_id(), $userId]);
            flash('ok', 'Compte renommé.');
        }
        redirect('comptes.php');
    } elseif ($action === 'archive') {
        db()->prepare('UPDATE accounts SET archived = 1 - archived WHERE id = ? AND user_id = ?')->execute([post_id(), $userId]);
        flash('ok', 'Compte mis à jour.');
        redirect('comptes.php');
    }
}

$accounts = account_balances($userId);
$total = 0;
foreach ($accounts as $a) {
    if (!$a['archived']) {
        $total += $a['balance'];
    }
}

app_open('Comptes', 'finances.php', $user);
?>
<a class="back-link" href="<?= e(url('finances.php')) ?>">← Budget</a>
<header class="page-head"><h1>Mes comptes</h1></header>

<div class="card"><span class="muted small">Solde total (comptes actifs)</span><div class="big"><?= e(fcfa($total)) ?></div></div>

<details class="card add-card mt"<?= $errors ? ' open' : '' ?>>
  <summary>+ Nouveau compte</summary>
  <form method="post" novalidate class="mt">
    <?= csrf_field() ?><?= once_field() ?><input type="hidden" name="action" value="add">
    <?php input_field('name', 'Nom (ex. Espèces, Orange Money)', 'text', ['value' => (string)opt($_POST, 'name', ''), 'required' => true, 'errors' => $errors, 'attrs' => 'maxlength="80"']); ?>
    <div class="field"><label for="type">Type</label>
      <select class="input" id="type" name="type"><?php foreach ($types as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <?php input_field('initial_balance', 'Solde de départ en F (facultatif)', 'text', ['value' => (string)opt($_POST, 'initial_balance', ''), 'errors' => $errors, 'attrs' => 'inputmode="numeric"']); ?>
    <button class="btn btn-primary btn-block" type="submit">Créer le compte</button>
  </form>
</details>

<div class="task-list mt">
  <?php if (!$accounts): ?><div class="card muted">Aucun compte. Crée « Espèces » pour commencer à suivre ton argent.</div><?php endif; ?>
  <?php foreach ($accounts as $a): ?>
    <div class="card<?= $a['archived'] ? ' is-archived' : '' ?>">
      <div class="between"><a href="<?= e(url('compte.php?id=' . (int)$a['id'])) ?>"><strong><?= e($a['name']) ?></strong></a><span class="badge"><?= e(opt($types, $a['type'], 'Autre')) ?><?= $a['archived'] ? ' · archivé' : '' ?></span></div>
      <div class="big small-big"><?= e(fcfa($a['balance'])) ?></div>
      <form method="post" class="row mt">
        <?= csrf_field() ?><input type="hidden" name="action" value="rename"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
        <input class="input" name="name" value="<?= e($a['name']) ?>" maxlength="80" aria-label="Nom du compte">
        <button class="btn" type="submit">Renommer</button>
      </form>
      <form method="post" class="mt">
        <?= csrf_field() ?><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
        <button class="btn btn-block" type="submit"><?= $a['archived'] ? 'Réactiver' : 'Archiver' ?></button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
<?php app_close('finances.php');
