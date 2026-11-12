<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/helpers.php';

$user = require_login();
$userId = (int)$user['id'];
$isTask = opt($_GET, 'type', 'tache') !== 'evenement';
$id = (int)opt($_GET, 'id', 0);
$table = $isTask ? 'tasks' : 'events';
$back = $isTask ? 'agenda.php' : 'agenda.php?vue=evenements';

$stmt = db()->prepare("SELECT * FROM $table WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $userId]);
$row = $stmt->fetch();
if (!$row) {
    flash('error', 'Élément introuvable.');
    redirect($back);
}

$errors = [];
if (is_post()) {
    if (!csrf_valid()) {
        flash('error', 'Session expirée. Recharge la page et réessaie.');
        redirect($back);
    }
    $title = post_str('title', 160);
    if ($title === '') {
        $errors['title'] = 'Donne un titre.';
    }
    if ($isTask) {
        $date = post_str('due_date', 10);
        $time = post_str('due_time', 5);
        $prio = (string)opt($_POST, 'priority', 'normal');
        $details = post_str('details', 2000);
        if ($date !== '' && !valid_date($date)) {
            $errors['due_date'] = 'Date invalide.';
        }
        if ($time !== '' && (!valid_time($time) || $date === '')) {
            $errors['due_time'] = $date === '' ? "Choisis d'abord une date." : 'Heure invalide.';
        }
        if (!in_array($prio, ['low', 'normal', 'high'], true)) {
            $prio = 'normal';
        }
        if (!$errors) {
            db()->prepare('UPDATE tasks SET title = ?, details = ?, due_date = ?, due_time = ?, priority = ? WHERE id = ? AND user_id = ?')
                ->execute([$title, $details !== '' ? $details : null, $date !== '' ? $date : null, $time !== '' ? $time . ':00' : null, $prio, $id, $userId]);
        }
    } else {
        $loc = post_str('location', 160);
        $date = post_str('date', 10);
        $start = post_str('start_time', 5);
        $end = post_str('end_time', 5);
        if (!valid_date($date)) {
            $errors['date'] = 'Date invalide.';
        }
        if (!valid_time($start)) {
            $errors['start_time'] = 'Heure de début invalide.';
        }
        if ($end !== '' && !valid_time($end)) {
            $errors['end_time'] = 'Heure de fin invalide.';
        } elseif ($end !== '' && !$errors && $end <= $start) {
            $errors['end_time'] = 'La fin doit être après le début.';
        }
        if (!$errors) {
            db()->prepare('UPDATE events SET title = ?, location = ?, starts_at = ?, ends_at = ? WHERE id = ? AND user_id = ?')
                ->execute([$title, $loc !== '' ? $loc : null, $date . ' ' . $start . ':00', $end !== '' ? $date . ' ' . $end . ':00' : null, $id, $userId]);
        }
    }
    if (!$errors) {
        flash('ok', 'Modifications enregistrées.');
        redirect($back);
    }
    $row = array_merge($row, ['title' => $title]);
}

app_open('Modifier', 'agenda.php', $user);
?>
<a class="back-link" href="<?= e(url($back)) ?>">← Retour à l'agenda</a>
<header class="page-head"><h1><?= $isTask ? 'Modifier la tâche' : "Modifier l'événement" ?></h1></header>
<div class="card narrow">
<form method="post" novalidate>
  <?= csrf_field() ?>
  <?php input_field('title', 'Titre', 'text', ['value' => (string)$row['title'], 'required' => true, 'errors' => $errors, 'attrs' => 'maxlength="160"']); ?>
  <?php if ($isTask): ?>
    <div class="two">
      <?php input_field('due_date', 'Date', 'date', ['value' => (string)opt($_POST, 'due_date', (string)$row['due_date']), 'errors' => $errors]); ?>
      <?php input_field('due_time', 'Heure', 'time', ['value' => (string)opt($_POST, 'due_time', substr((string)$row['due_time'], 0, 5)), 'errors' => $errors]); ?>
    </div>
    <div class="field"><label for="priority">Priorité</label>
      <select class="input" id="priority" name="priority">
        <?php foreach (['low' => 'Basse', 'normal' => 'Normale', 'high' => 'Haute'] as $k => $l): ?>
          <option value="<?= $k ?>"<?= $row['priority'] === $k ? ' selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="field"><label for="details">Détails</label>
      <textarea class="input area" id="details" name="details" maxlength="2000"><?= e((string)opt($_POST, 'details', (string)$row['details'])) ?></textarea></div>
  <?php else: ?>
    <?php input_field('location', 'Lieu', 'text', ['value' => (string)opt($_POST, 'location', (string)$row['location']), 'errors' => $errors, 'attrs' => 'maxlength="160"']); ?>
    <?php input_field('date', 'Date', 'date', ['value' => (string)opt($_POST, 'date', substr($row['starts_at'], 0, 10)), 'required' => true, 'errors' => $errors]); ?>
    <div class="two">
      <?php input_field('start_time', 'Début', 'time', ['value' => (string)opt($_POST, 'start_time', substr($row['starts_at'], 11, 5)), 'required' => true, 'errors' => $errors]); ?>
      <?php input_field('end_time', 'Fin', 'time', ['value' => (string)opt($_POST, 'end_time', $row['ends_at'] ? substr($row['ends_at'], 11, 5) : ''), 'errors' => $errors]); ?>
    </div>
  <?php endif; ?>
  <button class="btn btn-primary btn-block" type="submit">Enregistrer</button>
</form>
</div>
<?php app_close('agenda.php');
