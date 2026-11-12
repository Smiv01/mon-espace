<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/helpers.php';

$user = require_login();
$userId = (int)$user['id'];
$today = today_for($user);
$vue = opt($_GET, 'vue', 'taches') === 'evenements' ? 'evenements' : 'taches';
$errors = [];

if (is_post()) {
    $action = (string)opt($_POST, 'action', '');
    if (!csrf_valid()) {
        flash('error', 'Session expirée. Recharge la page et réessaie.');
        redirect('agenda.php');
    }

    if ($action === 'task_add') {
        $title = post_str('title', 160);
        $date = post_str('due_date', 10);
        $time = post_str('due_time', 5);
        $prio = (string)opt($_POST, 'priority', 'normal');
        // Raccourcis « Aujourd'hui / Demain / Sans date » (sinon la date choisie).
        $when = (string)opt($_POST, 'when', 'custom');
        if ($when === 'today') {
            $date = $today;
        } elseif ($when === 'tomorrow') {
            $date = DateTime::createFromFormat('Y-m-d', $today)->modify('+1 day')->format('Y-m-d');
        } elseif ($when === 'none') {
            $date = '';
            $time = '';
        }
        if ($title === '') {
            $errors['title'] = 'Donne un titre à la tâche.';
        }
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
            db()->prepare('INSERT INTO tasks (user_id, title, due_date, due_time, priority) VALUES (?, ?, ?, ?, ?)')
                ->execute([$userId, $title, $date !== '' ? $date : null, $time !== '' ? $time . ':00' : null, $prio]);
            flash('ok', 'Tâche ajoutée.');
            redirect('agenda.php');
        }
    } elseif ($action === 'task_toggle') {
        $id = post_id();
        $stmt = db()->prepare('SELECT status FROM tasks WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        $st = $stmt->fetchColumn();
        if ($st) {
            $new = $st === 'done' ? 'todo' : 'done';
            db()->prepare('UPDATE tasks SET status = ?, done_at = ? WHERE id = ? AND user_id = ?')
                ->execute([$new, $new === 'done' ? now() : null, $id, $userId]);
        }
        redirect('agenda.php');
    } elseif ($action === 'task_delete') {
        db()->prepare('DELETE FROM tasks WHERE id = ? AND user_id = ?')->execute([post_id(), $userId]);
        flash('ok', 'Tâche supprimée.');
        redirect('agenda.php');
    } elseif ($action === 'event_add') {
        $vue = 'evenements';
        $title = post_str('title', 160);
        $loc = post_str('location', 160);
        $date = post_str('date', 10);
        $start = post_str('start_time', 5);
        $end = post_str('end_time', 5);
        if ($title === '') {
            $errors['title'] = "Donne un titre à l'événement.";
        }
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
            db()->prepare('INSERT INTO events (user_id, title, location, starts_at, ends_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$userId, $title, $loc !== '' ? $loc : null, $date . ' ' . $start . ':00', $end !== '' ? $date . ' ' . $end . ':00' : null]);
            flash('ok', 'Événement ajouté.');
            redirect('agenda.php?vue=evenements');
        }
    } elseif ($action === 'event_delete') {
        db()->prepare('DELETE FROM events WHERE id = ? AND user_id = ?')->execute([post_id(), $userId]);
        flash('ok', 'Événement supprimé.');
        redirect('agenda.php?vue=evenements');
    }
}

app_open('Agenda', 'agenda.php', $user);
?>
<header class="page-hero">
  <img class="mod-ico" src="<?= e(url('assets/icons/agenda.svg')) ?>" alt="" width="56" height="56">
  <div><h1>Mon agenda</h1><p class="muted capitalize"><?= e(fr_date($today, true)) ?></p></div>
</header>

<div class="tabs" role="tablist">
  <a class="tab<?= $vue === 'taches' ? ' on' : '' ?>" href="<?= e(url('agenda.php')) ?>">Tâches</a>
  <a class="tab<?= $vue === 'evenements' ? ' on' : '' ?>" href="<?= e(url('agenda.php?vue=evenements')) ?>">Événements</a>
</div>

<?php if ($vue === 'taches'):
    $stmt = db()->prepare("SELECT * FROM tasks WHERE user_id = ? AND status = 'todo' ORDER BY (due_date IS NULL), due_date, due_time, id");
    $stmt->execute([$userId]);
    $todo = $stmt->fetchAll();
    $stmt = db()->prepare("SELECT * FROM tasks WHERE user_id = ? AND status = 'done' ORDER BY done_at DESC LIMIT 10");
    $stmt->execute([$userId]);
    $done = $stmt->fetchAll();
    $prioLabel = ['low' => 'Basse', 'normal' => 'Normale', 'high' => 'Haute'];
    $row = function ($t) use ($today, $prioLabel) { ?>
      <div class="task <?= $t['status'] === 'done' ? 'is-done' : '' ?>">
        <form method="post" class="inline">
          <?= csrf_field() ?><input type="hidden" name="action" value="task_toggle"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <button class="check" type="submit" aria-label="<?= $t['status'] === 'done' ? 'Remettre à faire' : 'Marquer comme terminée' ?>"><?= $t['status'] === 'done' ? '✓' : '' ?></button>
        </form>
        <a class="task-main" href="<?= e(url('agenda-edit.php?type=tache&id=' . (int)$t['id'])) ?>">
          <strong><?= e($t['title']) ?></strong>
          <span class="sub">
            <?php if ($t['due_date']): ?>
              <span class="<?= ($t['status'] === 'todo' && $t['due_date'] < $today) ? 'late' : '' ?>"><?= e(fr_date($t['due_date'])) ?><?= $t['due_time'] ? ' · ' . e(substr($t['due_time'], 0, 5)) : '' ?></span>
            <?php else: ?>Sans date<?php endif; ?>
            <?php if ($t['priority'] === 'high'): ?> · <span class="late">Priorité haute</span><?php endif; ?>
          </span>
        </a>
        <form method="post" class="inline" data-confirm="Supprimer cette tâche ?">
          <?= csrf_field() ?><input type="hidden" name="action" value="task_delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <button class="icon-btn" type="submit" aria-label="Supprimer">🗑</button>
        </form>
      </div>
    <?php };
?>
  <details class="card add-card"<?= $errors ? ' open' : '' ?>>
    <summary>+ Nouvelle tâche</summary>
    <form method="post" novalidate class="mt">
      <?= csrf_field() ?><input type="hidden" name="action" value="task_add">
      <?php input_field('title', 'Que dois-tu faire ?', 'text', ['value' => (string)opt($_POST, 'title', ''), 'required' => true, 'errors' => $errors, 'attrs' => 'maxlength="160"']); ?>
      <div class="field"><span class="lbl">Pour quand ?</span><div class="chips">
        <label class="chip"><input type="radio" name="when" value="today" checked><span>Aujourd'hui</span></label>
        <label class="chip"><input type="radio" name="when" value="tomorrow"><span>Demain</span></label>
        <label class="chip"><input type="radio" name="when" value="none"><span>Sans date</span></label>
        <label class="chip"><input type="radio" name="when" value="custom"><span>Autre date</span></label>
      </div></div>
      <?php input_field('due_date', 'Si « Autre date » : choisis le jour', 'date', ['value' => (string)opt($_POST, 'due_date', ''), 'errors' => $errors]); ?>
      <details class="more"><summary class="link">Plus d'options (heure, priorité)</summary>
        <?php input_field('due_time', 'Heure', 'time', ['value' => (string)opt($_POST, 'due_time', ''), 'errors' => $errors]); ?>
        <div class="field"><span class="lbl">Priorité</span><div class="chips">
          <label class="chip"><input type="radio" name="priority" value="low"><span>Basse</span></label>
          <label class="chip"><input type="radio" name="priority" value="normal" checked><span>Normale</span></label>
          <label class="chip"><input type="radio" name="priority" value="high"><span>Haute</span></label>
        </div></div>
      </details>
      <button class="btn btn-primary btn-block btn-lg" type="submit">Ajouter la tâche</button>
    </form>
  </details>

  <h2 class="section-h">À faire (<?= count($todo) ?>)</h2>
  <?php if (!$todo): ?><div class="card muted">Aucune tâche en cours. Ajoute ta première tâche ci-dessus.</div><?php endif; ?>
  <div class="task-list"><?php foreach ($todo as $t) { $row($t); } ?></div>

  <?php if ($done): ?>
    <h2 class="section-h">Terminées récemment</h2>
    <div class="task-list"><?php foreach ($done as $t) { $row($t); } ?></div>
  <?php endif; ?>

<?php else:
    $stmt = db()->prepare('SELECT * FROM events WHERE user_id = ? AND starts_at >= ? ORDER BY starts_at LIMIT 100');
    $stmt->execute([$userId, $today . ' 00:00:00']);
    $events = $stmt->fetchAll();
?>
  <details class="card add-card"<?= $errors ? ' open' : '' ?>>
    <summary>+ Nouvel événement</summary>
    <form method="post" novalidate class="mt">
      <?= csrf_field() ?><input type="hidden" name="action" value="event_add">
      <?php input_field('title', 'Nom de l\'événement', 'text', ['value' => (string)opt($_POST, 'title', ''), 'required' => true, 'errors' => $errors, 'attrs' => 'maxlength="160"']); ?>
      <?php input_field('date', 'Quel jour ?', 'date', ['value' => (string)opt($_POST, 'date', $today), 'required' => true, 'errors' => $errors]); ?>
      <?php input_field('start_time', 'À quelle heure ?', 'time', ['value' => (string)opt($_POST, 'start_time', ''), 'required' => true, 'errors' => $errors]); ?>
      <details class="more"><summary class="link">Plus d'options (fin, lieu)</summary>
        <?php input_field('end_time', 'Heure de fin', 'time', ['value' => (string)opt($_POST, 'end_time', ''), 'errors' => $errors]); ?>
        <?php input_field('location', 'Lieu', 'text', ['value' => (string)opt($_POST, 'location', ''), 'errors' => $errors, 'attrs' => 'maxlength="160"']); ?>
      </details>
      <button class="btn btn-primary btn-block btn-lg" type="submit">Ajouter l'événement</button>
    </form>
  </details>

  <h2 class="section-h">À venir</h2>
  <?php if (!$events): ?><div class="card muted">Aucun événement à venir.</div><?php endif; ?>
  <div class="task-list">
  <?php foreach ($events as $ev): $d = substr($ev['starts_at'], 0, 10); ?>
    <div class="task">
      <span class="tile t-agenda ev-date"><?= e(substr($d, 8, 2)) ?></span>
      <a class="task-main" href="<?= e(url('agenda-edit.php?type=evenement&id=' . (int)$ev['id'])) ?>">
        <strong><?= e($ev['title']) ?></strong>
        <span class="sub capitalize"><?= e(fr_date($d)) ?> · <?= e(substr($ev['starts_at'], 11, 5)) ?><?= $ev['ends_at'] ? '–' . e(substr($ev['ends_at'], 11, 5)) : '' ?><?= $ev['location'] ? ' · ' . e($ev['location']) : '' ?></span>
      </a>
      <form method="post" class="inline" data-confirm="Supprimer cet événement ?">
        <?= csrf_field() ?><input type="hidden" name="action" value="event_delete"><input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
        <button class="icon-btn" type="submit" aria-label="Supprimer">🗑</button>
      </form>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php app_close('agenda.php');
