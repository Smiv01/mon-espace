<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/helpers.php';

$user = require_login();
$userId = (int)$user['id'];
$id = (int)opt($_GET, 'id', 0);

$stmt = db()->prepare('SELECT * FROM notes WHERE id = ? AND user_id = ?');
$stmt->execute([$id, $userId]);
$note = $stmt->fetch();
if (!$note) {
    flash('error', 'Note introuvable.');
    redirect('notes.php');
}

$errors = [];
if (is_post()) {
    if (!csrf_valid()) {
        flash('error', 'Session expirée. Recharge la page et réessaie.');
        redirect('note.php?id=' . $id);
    }
    $action = (string)opt($_POST, 'action', '');
    if ($action === 'save') {
        $title = post_str('title', 160);
        $body = post_str('body', 20000);
        if ($title === '') {
            $errors['title'] = 'Donne un titre à la note.';
            $note['title'] = $title;
            $note['body'] = $body;
        } else {
            db()->prepare('UPDATE notes SET title = ?, body = ? WHERE id = ? AND user_id = ?')
                ->execute([$title, $body !== '' ? $body : null, $id, $userId]);
            flash('ok', 'Note enregistrée.');
            redirect('note.php?id=' . $id);
        }
    } elseif ($action === 'pin') {
        db()->prepare('UPDATE notes SET pinned = 1 - pinned WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
        redirect('note.php?id=' . $id);
    } elseif ($action === 'archive') {
        db()->prepare('UPDATE notes SET archived = 1 - archived, pinned = 0 WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
        flash('ok', $note['archived'] ? 'Note désarchivée.' : 'Note archivée.');
        redirect('notes.php');
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM notes WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
        flash('ok', 'Note supprimée.');
        redirect('notes.php');
    } elseif ($action === 'to_task') {
        // Crée une tâche à partir de la note (la note est conservée).
        db()->prepare('INSERT INTO tasks (user_id, title, details) VALUES (?, ?, ?)')
            ->execute([$userId, mb_substr($note['title'], 0, 160), $note['body'] !== null ? mb_substr($note['body'], 0, 2000) : null]);
        flash('ok', 'Tâche créée à partir de la note.');
        redirect('agenda.php');
    }
}

app_open('Note', 'notes.php', $user);
?>
<a class="back-link" href="<?= e(url('notes.php')) ?>">← Mes notes</a>
<div class="card narrow">
  <form method="post" novalidate>
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <?php input_field('title', 'Titre', 'text', ['value' => (string)$note['title'], 'required' => true, 'errors' => $errors, 'attrs' => 'maxlength="160"']); ?>
    <div class="field"><label for="body">Texte</label>
      <textarea class="input area" id="body" name="body" maxlength="20000"><?= e((string)$note['body']) ?></textarea></div>
    <button class="btn btn-primary btn-block" type="submit">Enregistrer</button>
  </form>
</div>
<div class="card narrow mt stack">
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="pin"><button class="btn btn-block" type="submit"><?= $note['pinned'] ? 'Désépingler' : 'Épingler' ?></button></form>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="to_task"><button class="btn btn-block" type="submit">Transformer en tâche</button></form>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="archive"><button class="btn btn-block" type="submit"><?= $note['archived'] ? 'Désarchiver' : 'Archiver' ?></button></form>
  <form method="post" data-confirm="Supprimer définitivement cette note ?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn btn-block" type="submit">Supprimer</button></form>
</div>
<?php app_close('notes.php');
