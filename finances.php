<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/money.php';

$user = require_login();
$userId = (int)$user['id'];
$today = today_for($user);
ensure_categories($userId);
$errors = [];
$open = '';

if (is_post()) {
    if (!csrf_valid()) {
        flash('error', 'Session expirée. Recharge la page et réessaie.');
        redirect('finances.php');
    }
    $action = (string)opt($_POST, 'action', '');

    if ($action === 'quick_account') {
        // Premier compte en un geste : « Où est ton argent ? »
        $types = account_types();
        $type = (string)opt($_POST, 'type', 'cash');
        $init = parse_amount_zero(opt($_POST, 'initial_balance', ''));
        if (!isset($types[$type])) {
            $type = 'cash';
        }
        if ($init === null) {
            flash('error', 'Écris le montant avec des chiffres seulement (ou laisse vide).');
        } elseif (once_valid()) {
            db()->prepare('INSERT INTO accounts (user_id, name, type, initial_balance) VALUES (?, ?, ?, ?)')
                ->execute([$userId, $types[$type], $type, $init]);
            flash('ok', $types[$type] . ' ajouté.');
        }
        redirect('finances.php');
    } elseif ($action === 'budget_set') {
        $amount = parse_amount(opt($_POST, 'monthly_amount', ''));
        $cat = owned_category($userId, opt($_POST, 'category_id', 0), 'expense');
        if (!$cat || $amount === null) {
            flash('error', 'Choisis une catégorie et un montant valide pour l\'enveloppe.');
        } else {
            $stmt = db()->prepare('SELECT id FROM budgets WHERE user_id = ? AND category_id = ?');
            $stmt->execute([$userId, $cat['id']]);
            if ($stmt->fetchColumn()) {
                db()->prepare('UPDATE budgets SET monthly_amount = ? WHERE user_id = ? AND category_id = ?')->execute([$amount, $userId, $cat['id']]);
            } else {
                db()->prepare('INSERT INTO budgets (user_id, category_id, monthly_amount) VALUES (?, ?, ?)')->execute([$userId, $cat['id'], $amount]);
            }
            flash('ok', 'Enveloppe « ' . $cat['name'] . ' » enregistrée.');
        }
        redirect('finances.php');
    } elseif ($action === 'budget_delete') {
        db()->prepare('DELETE FROM budgets WHERE id = ? AND user_id = ?')->execute([post_id(), $userId]);
        flash('ok', 'Enveloppe supprimée.');
        redirect('finances.php');
    }
}

$accounts = account_balances($userId);
$active = array_values(array_filter($accounts, function ($a) { return !$a['archived']; }));
$total = 0;
foreach ($active as $a) {
    $total += $a['balance'];
}
list($m1, $m2) = month_bounds($today);
$stmt = db()->prepare("SELECT kind, SUM(amount) FROM transactions WHERE user_id = ? AND occurred_on >= ? AND occurred_on < ? AND kind IN ('income','expense') GROUP BY kind");
$stmt->execute([$userId, $m1, $m2]);
$sums = ['income' => 0, 'expense' => 0];
foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $r) {
    $sums[$r[0]] = (int)$r[1];
}

$stmt = db()->prepare('SELECT * FROM categories WHERE user_id = ? ORDER BY kind, id');
$stmt->execute([$userId]);
$cats = ['expense' => [], 'income' => []];
foreach ($stmt->fetchAll() as $c) {
    $cats[$c['kind']][] = $c;
}

$stmt = db()->prepare(
    "SELECT b.id, b.monthly_amount, c.name,
            (SELECT COALESCE(SUM(t.amount), 0) FROM transactions t
              WHERE t.user_id = b.user_id AND t.category_id = b.category_id AND t.kind = 'expense'
                AND t.occurred_on >= ? AND t.occurred_on < ?) AS spent
       FROM budgets b JOIN categories c ON c.id = b.category_id WHERE b.user_id = ? ORDER BY c.name"
);
$stmt->execute([$m1, $m2, $userId]);
$budgets = $stmt->fetchAll();

$stmt = db()->prepare(
    'SELECT t.*, a.name AS account_name, c.name AS cat_name FROM transactions t
       JOIN accounts a ON a.id = t.account_id LEFT JOIN categories c ON c.id = t.category_id
      WHERE t.user_id = ? ORDER BY t.occurred_on DESC, t.id DESC LIMIT 8'
);
$stmt->execute([$userId]);
$recent = $stmt->fetchAll();

$stmt = db()->prepare(
    'SELECT d.direction, COALESCE(SUM(d.amount - COALESCE((SELECT SUM(p.amount) FROM debt_payments p WHERE p.debt_id = d.id), 0)), 0)
       FROM debts d WHERE d.user_id = ? GROUP BY d.direction'
);
$stmt->execute([$userId]);
$debtSum = ['i_owe' => 0, 'owed_to_me' => 0];
foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $r) {
    $debtSum[$r[0]] = max(0, (int)$r[1]);
}

// Répartition des dépenses du mois par catégorie (graphique en anneau).
$stmt = db()->prepare(
    "SELECT COALESCE(c.name, 'Autre') AS name, SUM(t.amount) AS total
       FROM transactions t LEFT JOIN categories c ON c.id = t.category_id
      WHERE t.user_id = ? AND t.kind = 'expense' AND t.occurred_on >= ? AND t.occurred_on < ?
      GROUP BY COALESCE(c.name, 'Autre') ORDER BY total DESC"
);
$stmt->execute([$userId, $m1, $m2]);
$slices = [];
$rest = 0;
foreach ($stmt->fetchAll() as $i => $r) {
    if ($i < 7) {
        $slices[] = ['name' => $r['name'], 'total' => (int)$r['total']];
    } else {
        $rest += (int)$r['total'];
    }
}
if ($rest > 0) {
    $slices[] = ['name' => 'Autres', 'total' => $rest];
}
$sliceSum = 0;
foreach ($slices as $sl) {
    $sliceSum += $sl['total'];
}

app_open('Budget', 'finances.php', $user);
?>
<header class="page-hero">
  <img class="mod-ico" src="<?= e(url('assets/icons/budget.svg')) ?>" alt="" width="56" height="56">
  <div><h1>Mon budget</h1><p class="muted">Où va ton argent</p></div>
</header>

<div class="card">
  <span class="muted small">Solde total</span>
  <div class="big"><?= e(fcfa($total)) ?></div>
  <div class="two mt">
    <div class="pill pill-in"><span class="small">Entrées du mois</span><strong><?= e(fcfa($sums['income'])) ?></strong></div>
    <div class="pill pill-out"><span class="small">Dépenses du mois</span><strong><?= e(fcfa($sums['expense'])) ?></strong></div>
  </div>
</div>

<?php if (!$active): ?>
  <div class="card mt">
    <h2>Où est ton argent ?</h2>
    <p class="muted">Choisis un endroit pour commencer. Tu pourras en ajouter d'autres ensuite.</p>
    <form method="post" novalidate>
      <?= csrf_field() ?><?= once_field() ?><input type="hidden" name="action" value="quick_account">
      <div class="chips">
        <?php $first = true; foreach (account_types() as $k => $l): ?><label class="chip"><input type="radio" name="type" value="<?= e($k) ?>"<?= $first ? ' checked' : '' ?>><span><?= e($l) ?></span></label><?php $first = false; endforeach; ?>
      </div>
      <?php input_field('initial_balance', 'Combien as-tu dessus ? (facultatif)', 'text', ['attrs' => 'inputmode="numeric" placeholder="0 F"']); ?>
      <button class="btn btn-primary btn-block btn-lg" type="submit">Continuer</button>
    </form>
  </div>
<?php else: ?>
  <div class="actions mt">
    <a class="action" href="<?= e(url('ajouter.php?type=depense')) ?>"><span class="tile t-agenda"><?= icon_svg('up') ?></span>Dépense</a>
    <a class="action" href="<?= e(url('ajouter.php?type=entree')) ?>"><span class="tile t-budget"><?= icon_svg('down') ?></span>Argent reçu</a>
    <a class="action" href="<?= e(url('ajouter.php?type=transfert')) ?>"><span class="tile t-frigo"><?= icon_svg('swap') ?></span>Transfert</a>
    <a class="action" href="<?= e(url('epargne.php')) ?>"><span class="tile t-journee"><?= icon_svg('coin') ?></span>Épargne</a>
  </div>
  <div class="acc-strip mt">
    <?php foreach ($active as $a): ?>
      <a class="acc-pill" href="<?= e(url('compte.php?id=' . (int)$a['id'])) ?>"><span class="small muted"><?= e($a['name']) ?></span><strong><?= e(fcfa($a['balance'])) ?></strong></a>
    <?php endforeach; ?>
    <a class="acc-pill add" href="<?= e(url('comptes.php')) ?>">+ Compte</a>
  </div>
<?php endif; ?>

<a class="card debt-link mt" href="<?= e(url('dettes.php')) ?>">
  <div class="between"><strong>Mes dettes</strong><span class="small muted">Voir / ajouter ›</span></div>
  <div class="two mt">
    <div class="pill pill-out"><span class="small">Je dois</span><strong><?= e(fcfa($debtSum['i_owe'])) ?></strong></div>
    <div class="pill pill-in"><span class="small">On me doit</span><strong><?= e(fcfa($debtSum['owed_to_me'])) ?></strong></div>
  </div>
</a>

<h2 class="section-h">Où va ton argent ce mois-ci</h2>
<div class="card">
  <?php if ($sliceSum <= 0): ?>
    <p class="muted center">Pas encore de dépense ce mois-ci. Dès que tu en ajoutes, le graphique apparaît ici.</p>
  <?php else: ?>
    <div class="donut-wrap">
      <svg class="donut" viewBox="0 0 40 40" role="img" aria-label="Répartition des dépenses du mois par catégorie">
        <circle class="ring" cx="20" cy="20" r="15.9155"></circle>
        <?php $acc = 0.0; foreach ($slices as $i => $sl): $pct = $sl['total'] * 100 / $sliceSum; $draw = $pct > 2 ? $pct - 0.8 : $pct; ?>
          <circle class="seg<?= (int)$i ?>" cx="20" cy="20" r="15.9155" transform="rotate(-90 20 20)" stroke-dasharray="<?= e(number_format($draw, 3, '.', '')) ?> <?= e(number_format(100 - $draw, 3, '.', '')) ?>" stroke-dashoffset="<?= e(number_format(-$acc, 3, '.', '')) ?>"></circle>
        <?php $acc += $pct; endforeach; ?>
        <text class="d-small" x="20" y="18.6">Dépensé</text>
        <text class="d-big" x="20" y="23.6"><?= e(fcfa($sliceSum)) ?></text>
      </svg>
      <ul class="legend">
        <?php foreach ($slices as $i => $sl): ?>
          <li><span class="sw sw<?= (int)$i ?>"></span><span class="nm"><?= e($sl['name']) ?></span><strong><?= e(fcfa($sl['total'])) ?></strong><span class="pc"><?= (int)round($sl['total'] * 100 / $sliceSum) ?>%</span></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</div>

<h2 class="section-h">Mes enveloppes (ce mois-ci)</h2>
<div class="task-list">
  <?php if (!$budgets): ?><div class="card muted">Aucune enveloppe. Fixe un plafond mensuel par catégorie ci-dessous.</div><?php endif; ?>
  <?php foreach ($budgets as $b): $spent = (int)$b['spent']; $lim = (int)$b['monthly_amount']; $over = $spent > $lim; $near = !$over && $lim > 0 && $spent * 100 >= $lim * 80; ?>
    <div class="card">
      <div class="between"><strong><?= e($b['name']) ?></strong>
        <span class="<?= $over ? 'late' : '' ?>"><?= e(fcfa($spent)) ?> / <?= e(fcfa($lim)) ?></span></div>
      <progress class="bar<?= $over ? ' over' : ($near ? ' near' : '') ?>" max="<?= $lim ?>" value="<?= min($spent, $lim) ?>"></progress>
      <?php if ($over): ?><p class="late small">Enveloppe dépassée de <?= e(fcfa($spent - $lim)) ?>.</p>
      <?php elseif ($near): ?><p class="small warn">Enveloppe presque épuisée : il reste <?= e(fcfa($lim - $spent)) ?>.</p><?php endif; ?>
      <form method="post" class="inline" data-confirm="Supprimer cette enveloppe ?">
        <?= csrf_field() ?><input type="hidden" name="action" value="budget_delete"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
        <button class="icon-btn" type="submit" aria-label="Supprimer l'enveloppe">🗑</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
<details class="card add-card mt">
  <summary>+ Définir une enveloppe</summary>
  <form method="post" novalidate class="mt">
    <?= csrf_field() ?><input type="hidden" name="action" value="budget_set">
    <div class="field"><label for="b_cat">Catégorie</label><select class="input" id="b_cat" name="category_id"><?php foreach ($cats['expense'] as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <?php input_field('monthly_amount', 'Plafond mensuel en F', 'text', ['attrs' => 'inputmode="numeric"', 'required' => true]); ?>
    <button class="btn btn-primary btn-block" type="submit">Enregistrer l'enveloppe</button>
  </form>
</details>

<h2 class="section-h">Dernières écritures</h2>
<div class="task-list">
  <?php if (!$recent): ?><div class="card muted">Aucune écriture pour le moment.</div><?php endif; ?>
  <?php foreach ($recent as $t): echo tx_row($t); endforeach; ?>
</div>
<p class="mt center"><a href="<?= e(url('transactions.php')) ?>">Tout l'historique</a> · <a href="<?= e(url('dettes.php')) ?>">Dettes</a> · <a href="<?= e(url('comptes.php')) ?>">Comptes</a></p>
<?php app_close('finances.php');
