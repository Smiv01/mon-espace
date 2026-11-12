<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/money.php';

$user = require_login();
$userId = (int)$user['id'];
$errors = [];

if (is_post()) {
    if (!csrf_valid()) {
        flash('error', 'Session expirée. Recharge la page et réessaie.');
        redirect('epargne.php');
    }
    if ((string)opt($_POST, 'action', '') === 'goal_add') {
        $name = post_str('name', 80);
        $target = parse_amount(opt($_POST, 'target_amount', ''));
        $due = post_str('due_date', 10);
        if ($name === '') {
            $errors['name'] = 'Donne un nom à ton objectif (ex. Voyage).';
        }
        if ($target === null) {
            $errors['target_amount'] = 'Écris un montant (chiffres seulement).';
        }
        if ($due !== '' && !valid_date($due)) {
            $errors['due_date'] = 'Date invalide.';
        }
        if (!$errors) {
            if (once_valid()) {
                db()->prepare('INSERT INTO savings_goals (user_id, name, target_amount, due_date) VALUES (?, ?, ?, ?)')
                    ->execute([$userId, $name, $target, $due !== '' ? $due : null]);
                $id = (int)db()->lastInsertId();
                flash('ok', 'Objectif créé. Mets de l\'argent de côté quand tu veux.');
                redirect('objectif.php?id=' . $id);
            }
            redirect('epargne.php');
        }
    }
}

$stmt = db()->prepare('SELECT * FROM savings_goals WHERE user_id = ? ORDER BY id');
$stmt->execute([$userId]);
$goals = $stmt->fetchAll();
$totalSaved = 0;
foreach ($goals as $k => $g) {
    $goals[$k]['saved'] = goal_saved($userId, $g['id']);
    $totalSaved += $goals[$k]['saved'];
}

app_open('Épargne', 'finances.php', $user);
?>
<a class="back-link" href="<?= e(url('finances.php')) ?>">← Budget</a>
<header class="page-head"><h1>Épargne</h1></header>
<div class="card center"><span class="muted small">Tu as mis de côté</span><div class="big"><?= e(fcfa($totalSaved)) ?></div>
  <p class="muted small">C'est un suivi : ton argent reste sur tes comptes (Wave, espèces…).</p></div>

<div class="task-list mt">
  <?php foreach ($goals as $g): $pct = (int)$g['target_amount'] > 0 ? min(100, (int)floor($g['saved'] * 100 / $g['target_amount'])) : 0; ?>
    <a class="card goal-card" href="<?= e(url('objectif.php?id=' . (int)$g['id'])) ?>">
      <div class="between"><strong><?= e($g['name']) ?></strong><span class="badge"><?= $pct ?> %</span></div>
      <progress class="bar" max="<?= (int)$g['target_amount'] ?>" value="<?= min((int)$g['saved'], (int)$g['target_amount']) ?>"></progress>
      <span class="small muted"><?= e(fcfa($g['saved'])) ?> sur <?= e(fcfa($g['target_amount'])) ?></span>
    </a>
  <?php endforeach; ?>
  <?php if (!$goals): ?><div class="card muted center">Pas encore d'objectif. Crée le premier : un voyage, un téléphone, une urgence…</div><?php endif; ?>
</div>

<details class="card add-card mt"<?= $errors ? ' open' : '' ?>>
  <summary>+ Nouvel objectif</summary>
  <form method="post" novalidate class="mt">
    <?= csrf_field() ?><?= once_field() ?><input type="hidden" name="action" value="goal_add">
    <?php input_field('name', 'Pour quoi ?', 'text', ['value' => (string)opt($_POST, 'name', ''), 'required' => true, 'errors' => $errors, 'attrs' => 'maxlength="80" placeholder="Voyage, téléphone…"']); ?>
    <?php input_field('target_amount', 'Combien veux-tu mettre de côté ? (en F)', 'text', ['value' => (string)opt($_POST, 'target_amount', ''), 'required' => true, 'errors' => $errors, 'attrs' => 'inputmode="numeric" placeholder="200 000"']); ?>
    <details class="more"><summary class="link">Ajouter une date limite</summary>
      <?php input_field('due_date', 'Avant le', 'date', ['value' => (string)opt($_POST, 'due_date', ''), 'errors' => $errors]); ?></details>
    <button class="btn btn-primary btn-block btn-lg" type="submit">Créer l'objectif</button>
  </form>
</details>
<?php app_close('finances.php');
