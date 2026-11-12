<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/stock.php';
require __DIR__ . '/includes/days.php';
require __DIR__ . '/includes/geo.php';

// Invitation à rejoindre un espace partagé : lien à usage unique, valable 7 jours, à accepter explicitement.
$code = (string)opt($_GET, 'code', opt($_POST, 'code', ''));
$valid = (bool)preg_match('/^[0-9a-f]{32}$/', $code);

$user = current_user();
if (!$user) {
    if ($valid) {
        $_SESSION['after_login'] = 'rejoindre.php?code=' . $code;
    }
    flash('ok', 'Connecte-toi (ou crée ton compte) pour répondre à l\'invitation.');
    redirect('connexion.php');
}
$userId = (int)$user['id'];

function find_invite($code)
{
    $stmt = db()->prepare(
        'SELECT i.id, i.space_id, i.used_at, i.expires_at, s.name AS space_name, s.user_id AS owner_id, p.first_name AS owner_name
           FROM space_invites i JOIN spaces s ON s.id = i.space_id LEFT JOIN profiles p ON p.user_id = s.user_id
          WHERE i.token_hash = ?'
    );
    $stmt->execute([hash('sha256', $code)]);
    return $stmt->fetch();
}

function find_day_invite($code)
{
    $stmt = db()->prepare(
        'SELECT i.id, i.day_id, i.used_at, i.expires_at, d.title AS space_name, d.kind, d.user_id AS owner_id, p.first_name AS owner_name
           FROM day_invites i JOIN days d ON d.id = i.day_id LEFT JOIN profiles p ON p.user_id = d.user_id
          WHERE i.token_hash = ?'
    );
    $stmt->execute([hash('sha256', $code)]);
    return $stmt->fetch();
}

function find_circle_invite($code)
{
    $stmt = db()->prepare(
        'SELECT i.id, i.circle_id, i.used_at, i.expires_at, c.name AS space_name, c.user_id AS owner_id, p.first_name AS owner_name
           FROM circle_invites i JOIN circles c ON c.id = i.circle_id LEFT JOIN profiles p ON p.user_id = c.user_id
          WHERE i.token_hash = ?'
    );
    $stmt->execute([hash('sha256', $code)]);
    return $stmt->fetch();
}

$inv = $valid ? find_invite($code) : false;
$isDay = false;
$isCircle = false;
if (!$inv && $valid) {
    $inv = find_day_invite($code);
    $isDay = (bool)$inv;
    if ($inv && $inv['kind'] === 'journal') {
        $inv['space_name'] = 'sa journée';
    }
}
if (!$inv && $valid) {
    $inv = find_circle_invite($code);
    $isCircle = (bool)$inv;
}
$problem = '';
if (!$inv) {
    $problem = "Ce lien d'invitation n'est pas valide.";
} elseif ($inv['used_at'] !== null) {
    $problem = 'Cette invitation a déjà été utilisée. Demande un nouveau lien.';
} elseif ($inv['expires_at'] <= gmdate('Y-m-d H:i:s')) {
    $problem = 'Cette invitation a expiré. Demande un nouveau lien.';
} elseif ($isCircle ? circle_access($userId, $inv['circle_id']) : ($isDay ? day_access($userId, $inv['day_id']) : space_access($userId, $inv['space_id']))) {
    $problem = 'Tu fais déjà partie de cet espace.';
}

if (!$problem && is_post()) {
    if (!csrf_valid()) {
        flash('error', 'Session expirée. Recharge la page et réessaie.');
        redirect('rejoindre.php?code=' . $code);
    }
    if ((string)opt($_POST, 'action', '') === 'decline') {
        flash('ok', 'Invitation refusée.');
        redirect('accueil.php');
    }
    // Usage unique : la ligne n'est « prise » que si elle est encore libre et valide (atomique).
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $now = gmdate('Y-m-d H:i:s');
        $upd = $pdo->prepare($isCircle ? 'UPDATE circle_invites SET used_at = ?, used_by = ? WHERE id = ? AND used_at IS NULL AND expires_at > ?' : ($isDay ? 'UPDATE day_invites SET used_at = ?, used_by = ? WHERE id = ? AND used_at IS NULL AND expires_at > ?' : 'UPDATE space_invites SET used_at = ?, used_by = ? WHERE id = ? AND used_at IS NULL AND expires_at > ?'));
        $upd->execute([$now, $userId, $inv['id'], $now]);
        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();
            flash('error', 'Cette invitation vient d\'être utilisée ou a expiré.');
            redirect('accueil.php');
        }
        if ($isCircle) {
            $pdo->prepare('INSERT INTO circle_members (circle_id, user_id) VALUES (?, ?)')->execute([$inv['circle_id'], $userId]);
        } elseif ($isDay) {
            $pdo->prepare('INSERT INTO day_members (day_id, user_id) VALUES (?, ?)')->execute([$inv['day_id'], $userId]);
        } else {
            $pdo->prepare('INSERT INTO space_members (space_id, user_id) VALUES (?, ?)')->execute([$inv['space_id'], $userId]);
        }
        $pdo->commit();
    } catch (Exception $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
    if ($isCircle) {
        flash('ok', 'Tu as rejoint le cercle « ' . $inv['space_name'] . ' ». Tu choisis quand partager ta position.');
        redirect('cercle.php?id=' . (int)$inv['circle_id']);
    }
    if ($isDay) {
        flash('ok', 'Bienvenue ! Tu peux maintenant ajouter tes photos et messages.');
        redirect('journee.php?id=' . (int)$inv['day_id']);
    }
    flash('ok', 'Bienvenue dans « ' . $inv['space_name'] . ' » !');
    redirect('espace.php?id=' . (int)$inv['space_id']);
}

app_open('Invitation', 'espaces.php', $user);
?>
<header class="page-head"><h1>Invitation</h1></header>
<div class="card">
<?php if ($problem): ?>
  <p><?= e($problem) ?></p>
  <a class="btn btn-block" href="<?= e(url('accueil.php')) ?>">Retour à l'accueil</a>
<?php else: ?>
  <p><strong><?= e($inv['owner_name'] ?: 'Quelqu\'un') ?></strong> t'invite à rejoindre <strong>« <?= e($inv['space_name']) ?> »</strong>.</p>
  <p class="muted small"><?= $isCircle ? 'Tu pourras voir les positions que les membres choisissent de partager. Rien de ta position n\'est partagé automatiquement : tu décides quand et pour combien de temps.' : ($isDay ? 'Tu pourras voir, ajouter des photos et des messages, et réagir. Tu pourras quitter quand tu veux.' : 'Tu pourras voir et modifier le stock et la liste de courses. Tu pourras quitter l\'espace quand tu veux.') ?></p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="code" value="<?= e($code) ?>">
    <button class="btn btn-primary btn-block btn-lg" type="submit" name="action" value="accept">Accepter et rejoindre</button>
    <button class="btn btn-block mt" type="submit" name="action" value="decline">Refuser</button>
  </form>
<?php endif; ?>
</div>
<?php app_close('espaces.php');
