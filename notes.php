<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/helpers.php';

$user = require_login();
$userId = (int)$user['id'];
$errors = [];
$archived = opt($_GET, 'vue', '') === 'archives';
$q = mb_substr(trim((string)opt($_GET, 'q', '')), 0, 80);

if (is_post()) {
    if (!csrf_valid()) {
        flash('error', 'Session expirée. Recharge la page et réessaie.');
        redirect('notes.php');
    }
    $action = (string)opt($_POST, 'action', '');
    if ($action === 'note_add') {
        $title = post_str('title', 160);
        $body = post_str('body', 20000);
        if ($title === '') {
            $errors['title'] = 'Donne un titre à la note.';
        } else {
            db()->prepare('INSERT INTO notes (user_id, title, body) VALUES (?, ?, ?)')
                ->execute([$userId, $title, $body !== '' ? $body : null]);
            flash('ok', 'Note créée.');
            redirect('notes.php');
        }
    }
}

app_open('Notes', 'notes.php', $user);
?>
<header class="page-hero">
  <img class="mod-ico" src="<?= e(url('assets/icons/notes.svg')) ?>" alt="" width="56" height="56">
  <div><h1>Mes notes</h1><p class="muted">Idées et mémos</p></div>
</header>

<form class="search" method="get" role="search">
  <?php if ($archived): ?><input type="hidden" name="vue" value="archives"><?php endif; ?>
  <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Rechercher une note…" aria-label="Rechercher une note" maxlength="80">
  <button class="btn" type="submit">Chercher</button>
</form>

<div class="tabs">
  <a class="tab<?= !$archived ? ' on' : '' ?>" href="<?= e(url('notes.php')) ?>">Notes</a>
  <a class="tab<?= $archived ? ' on' : '' ?>" href="<?= e(url('notes.php?vue=archives')) ?>">Archives</a>
</div>

<?php if (!$archived): ?>
<details class="card add-card"<?= $errors ? ' open' : '' ?>>
  <summary>+ Nouvelle note</summary>
  <form method="post" novalidate class="mt">
    <?= csrf_field() ?><input type="hidden" name="action" value="note_add">
    <?php input_field('title', 'Titre', 'text', ['value' => (string)opt($_POST, 'title', ''), 'required' => true, 'errors' => $errors, 'attrs' => 'maxlength="160"']); ?>
    <div class="field"><label for="body">Texte</label>
      <textarea class="input area" id="body" name="body" maxlength="20000"><?= e((string)opt($_POST, 'body', '')) ?></textarea></div>
    <button class="btn btn-primary btn-block" type="submit">Créer la note</button>
  </form>
</details>
<?php endif;

$sql = 'SELECT id, title, body, pinned, updated_at FROM notes WHERE user_id = ? AND archived = ?';
$params = [$userId, $archived ? 1 : 0];
if ($q !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $sql .= ' AND (title LIKE ? OR body LIKE ?)';
    $params[] = $like;
    $params[] = $like;
}
$sql .= ' ORDER BY pinned DESC, updated_at DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$notes = $stmt->fetchAll();
?>
<div class="task-list mt">
  <?php if (!$notes): ?><div class="card muted"><?= $q !== '' ? 'Aucune note ne correspond.' : ($archived ? 'Aucune note archivée.' : 'Aucune note pour le moment.') ?></div><?php endif; ?>
  <?php foreach ($notes as $n): ?>
    <a class="note-card" href="<?= e(url('note.php?id=' . (int)$n['id'])) ?>">
      <strong><?= $n['pinned'] ? '<span class="pin" aria-label="Épinglée">★</span> ' : '' ?><?= e($n['title']) ?></strong>
      <span class="sub"><?= e(mb_substr(preg_replace('/\s+/', ' ', (string)$n['body']), 0, 90)) ?></span>
    </a>
  <?php endforeach; ?>
</div>
<?php app_close('notes.php');
