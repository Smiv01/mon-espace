<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/admin.php';

// Page réservée à l'administrateur principal (adresses listées dans config.local.php, section 'admin').
// Pour tout autre visiteur, la page se comporte comme si elle n'existait pas.
$user = current_user();
if (!$user || !is_admin_user($user)) {
    http_response_code(404);
    exit('Page introuvable.');
}

if (is_post()) {
    if (!csrf_valid()) {
        flash('error', 'Session expirée. Recharge la page et réessaie.');
    } elseif ((string)opt($_POST, 'action', '') === 'verify') {
        $id = (int)opt($_POST, 'user_id', 0);
        $stmt = db()->prepare('UPDATE users SET email_verified_at = ? WHERE id = ? AND email_verified_at IS NULL');
        $stmt->execute([now(), $id]);
        flash($stmt->rowCount() > 0 ? 'ok' : 'error', $stmt->rowCount() > 0 ? 'Compte validé.' : 'Rien à valider pour ce compte.');
    }
    $back = 'admin.php';
    $q = trim((string)opt($_POST, 'q', ''));
    redirect($q !== '' ? $back . '?q=' . rawurlencode($q) : $back);
}

$pdo = db();
$count = function ($sql, array $params = []) use ($pdo) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
};
$d7 = date('Y-m-d H:i:s', time() - 7 * 86400);
$d30 = date('Y-m-d H:i:s', time() - 30 * 86400);

$stats = [
    'Inscrits' => $count('SELECT COUNT(*) FROM users'),
    'E-mail validé' => $count('SELECT COUNT(*) FROM users WHERE email_verified_at IS NOT NULL'),
    'En attente de validation' => $count('SELECT COUNT(*) FROM users WHERE email_verified_at IS NULL'),
    'Nouveaux (7 jours)' => $count('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$d7]),
    'Nouveaux (30 jours)' => $count('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$d30]),
    'Actifs (7 jours)' => $count('SELECT COUNT(DISTINCT user_id) FROM user_sessions WHERE last_seen_at >= ?', [$d7]),
];
// Totaux d'usage uniquement : jamais le contenu des utilisateurs.
$usage = [
    'Transactions' => $count('SELECT COUNT(*) FROM transactions'),
    'Tâches' => $count('SELECT COUNT(*) FROM tasks'),
    'Notes' => $count('SELECT COUNT(*) FROM notes'),
    'Frigos / espaces' => $count('SELECT COUNT(*) FROM spaces'),
    'Journées partagées' => $count('SELECT COUNT(*) FROM days'),
    'Cercles' => $count('SELECT COUNT(*) FROM circles'),
];

$q = trim((string)opt($_GET, 'q', ''));
$sql = 'SELECT u.id, u.email, u.email_verified_at, u.created_at, p.first_name, p.last_name,
               (SELECT MAX(s.last_seen_at) FROM user_sessions s WHERE s.user_id = u.id) AS last_seen
          FROM users u LEFT JOIN profiles p ON p.user_id = u.id';
$params = [];
if ($q !== '') {
    $like = '%' . str_replace(['%', '_'], ['', ''], $q) . '%';
    $sql .= ' WHERE u.email LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ?';
    $params = [$like, $like, $like];
}
$sql .= ' ORDER BY u.id DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

app_open('Administration', 'admin.php', $user);
?>
<h1>Administration</h1>
<p class="muted">Vue d'ensemble de Lyvo. Tu vois ici les inscrits et des totaux d'usage, jamais le contenu de leurs données.</p>

<section class="card">
  <h2>Inscriptions</h2>
  <table class="table">
    <tbody>
    <?php foreach ($stats as $label => $value): ?>
      <tr><td><?= e($label) ?></td><td><strong><?= (int)$value ?></strong></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="card">
  <h2>Utilisation</h2>
  <table class="table">
    <tbody>
    <?php foreach ($usage as $label => $value): ?>
      <tr><td><?= e($label) ?></td><td><strong><?= (int)$value ?></strong></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="card">
  <h2>Utilisateurs</h2>
  <form method="get" class="mt">
    <div class="field">
      <label for="q">Rechercher (nom ou e-mail)</label>
      <input class="input" id="q" name="q" value="<?= e($q) ?>" autocomplete="off">
    </div>
    <button class="btn btn-block" type="submit">Rechercher</button>
  </form>
  <p class="muted small mt"><?= count($rows) ?> résultat(s)<?= count($rows) === 200 ? ' (200 premiers)' : '' ?>.</p>
  <table class="table">
    <thead><tr><th>Utilisateur</th><th>Statut</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $name = trim($r['first_name'] . ' ' . $r['last_name']); ?>
      <tr>
        <td>
          <strong><?= e($name !== '' ? $name : 'Sans nom') ?></strong><br>
          <span class="small"><?= e($r['email']) ?></span><br>
          <span class="muted small">Inscrit le <?= e(substr((string)$r['created_at'], 0, 16)) ?><?= $r['last_seen'] ? ' · vu le ' . e(substr((string)$r['last_seen'], 0, 16)) : '' ?></span>
        </td>
        <td>
          <?php if ($r['email_verified_at'] !== null): ?>
            <span class="badge">Validé</span>
          <?php else: ?>
            <span class="badge">En attente</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($r['email_verified_at'] === null): ?>
            <form method="post" class="inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="verify">
              <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="q" value="<?= e($q) ?>">
              <button class="btn" type="submit">Valider</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="3" class="muted">Aucun utilisateur trouvé.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>
<?php app_close('admin.php');
