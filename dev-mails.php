<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

// Boîte d'e-mails de développement : désactivée hors du mode dev.
if (cfg('env') !== 'dev') {
    http_response_code(404);
    exit('Page introuvable.');
}

if (is_post() && csrf_valid() && (opt($_POST, 'action', '')) === 'clear') {
    db()->exec('DELETE FROM dev_mails');
    redirect('dev-mails.php');
}

$mails = db()->query('SELECT to_email, subject, body, created_at FROM dev_mails ORDER BY id DESC LIMIT 30')->fetchAll();

html_open('Boîte de développement');
?>
<main class="content">
  <header class="page-head">
    <div>
      <h1>Boîte de développement</h1>
      <p class="muted">Aucun e-mail n'est réellement envoyé. Cette page n'existe qu'en mode « dev ».</p>
    </div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="clear"><button class="btn" type="submit">Vider</button></form>
  </header>
  <?php if (!$mails): ?>
    <div class="card"><p class="muted">Aucun message pour le moment.</p></div>
  <?php endif; ?>
  <div class="stack">
  <?php foreach ($mails as $m): ?>
    <article class="card">
      <h2><?= e($m['subject']) ?></h2>
      <p class="muted small">À : <?= e($m['to_email']) ?> · <?= e($m['created_at']) ?></p>
      <div class="mail"><?= e($m['body']) ?></div>
    </article>
  <?php endforeach; ?>
  </div>
</main>
<?php html_close();
