<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/auth_flow.php';
require __DIR__ . '/includes/media.php';

$user = require_login();
$userId = (int)$user['id'];
$errors = [];

if (is_post()) {
    $action = (string)(opt($_POST, 'action', ''));

    if (!csrf_valid()) {
        flash('error', 'Session expirée. Recharge la page et réessaie.');
        redirect('profil.php');
    }

    if ($action === 'avatar') {
        $file = opt($_FILES, 'photo', null);
        $err = save_avatar($userId, $file);
        flash($err === null ? 'ok' : 'error', $err === null ? 'Photo de profil mise à jour.' : $err);
        redirect('profil.php');
    } elseif ($action === 'avatar_remove') {
        delete_avatar($userId);
        flash('ok', 'Photo supprimée.');
        redirect('profil.php');
    } elseif ($action === 'profile') {
        $first = trim((string)(opt($_POST, 'first_name', '')));
        $last = trim((string)(opt($_POST, 'last_name', '')));
        if ($first === '' || mb_strlen($first) > 60) {
            $errors['first_name'] = 'Le prénom est obligatoire (60 caractères maximum).';
        }
        if (mb_strlen($last) > 60) {
            $errors['last_name'] = 'Le nom est trop long (60 caractères maximum).';
        }
        if (!$errors) {
            db()->prepare('UPDATE profiles SET first_name = ?, last_name = ? WHERE user_id = ?')
                ->execute([$first, $last !== '' ? $last : null, $userId]);
            flash('ok', 'Profil enregistré.');
            redirect('profil.php');
        }
        $user['first_name'] = $first;
        $user['last_name'] = $last;
    } elseif ($action === 'password') {
        $current = (string)(opt($_POST, 'current_password', ''));
        $new = (string)(opt($_POST, 'password', ''));
        $confirm = (string)(opt($_POST, 'confirm_password', ''));

        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $hash = (string)$stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            $errors['current_password'] = 'Mot de passe actuel incorrect.';
        }
        $pwErrors = password_errors($new);
        if ($pwErrors) {
            $errors['password'] = $pwErrors[0];
        } elseif ($new !== $confirm) {
            $errors['confirm_password'] = 'Les mots de passe ne correspondent pas.';
        }
        if (!$errors) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
            revoke_all_sessions($userId, session_fingerprint());
            flash('ok', 'Mot de passe changé. Les autres appareils ont été déconnectés.');
            redirect('profil.php');
        }
    } elseif ($action === 'revoke') {
        // Ne peut fermer que ses propres sessions.
        $sid = (int)(opt($_POST, 'session_id', 0));
        $stmt = db()->prepare('SELECT session_hash FROM user_sessions WHERE id = ? AND user_id = ?');
        $stmt->execute([$sid, $userId]);
        $row = $stmt->fetch();
        if ($row) {
            db()->prepare('DELETE FROM user_sessions WHERE id = ? AND user_id = ?')->execute([$sid, $userId]);
            if (hash_equals($row['session_hash'], session_fingerprint())) {
                logout_user();
                redirect('connexion.php');
            }
            flash('ok', 'Session fermée.');
        }
        redirect('profil.php');
    } elseif ($action === 'revoke_all') {
        revoke_all_sessions($userId);
        $_SESSION = [];
        redirect('connexion.php');
    }
}

$stmt = db()->prepare(
    'SELECT id, session_hash, user_agent, ip_address, created_at, last_seen_at
       FROM user_sessions WHERE user_id = ? AND expires_at > ? ORDER BY last_seen_at DESC'
);
$stmt->execute([$userId, now()]);
$sessions = $stmt->fetchAll();
$me = session_fingerprint();

app_open('Profil et réglages', 'profil.php', $user);
?>
<div class="profile-head">
  <?= avatar_html($user, 'profile-avatar') ?>
  <h1><?= e(trim($user['first_name'] . ' ' . $user['last_name'])) ?></h1>
  <p class="muted"><?= e($user['email']) ?></p>
</div>

<div class="stack narrow">
  <section class="card">
    <h2>Photo de profil</h2>
    <form method="post" enctype="multipart/form-data" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="avatar">
      <input class="input file-input" type="file" name="photo" accept="image/jpeg,image/png,image/webp" required aria-label="Choisir une photo">
      <p class="muted small">JPG, PNG ou WebP, 3 Mo maximum. Elle est recadrée en carré.</p>
      <button class="btn btn-primary btn-block" type="submit">Enregistrer la photo</button>
    </form>
    <?php if (!empty($user['avatar_path'])): ?>
      <form method="post" class="mt">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="avatar_remove">
        <button class="btn btn-block" type="submit">Supprimer la photo</button>
      </form>
    <?php endif; ?>
  </section>

  <section class="card" id="install-card">
    <h2>Installer l'application</h2>
    <p class="muted small">Ajoute Lyvo à l'écran d'accueil de ton téléphone : il s'ouvre en plein écran, comme une vraie application.</p>
    <button class="btn btn-primary btn-block" type="button" id="install-btn" hidden>Installer Lyvo</button>
    <p class="muted small" id="install-help">Sur iPhone : ouvre ce site dans Safari, touche <strong>Partager</strong>, puis <strong>Sur l'écran d'accueil</strong>. Sur Android (Chrome) : menu ⋮, puis <strong>Installer l'application</strong>. L'installation demande une adresse https une fois le site en ligne.</p>
  </section>

  <section class="card">
    <h2>Sauvegarder mes données</h2>
    <p class="muted small">Télécharge un fichier avec tes comptes, transactions, dettes, tâches, notes, agenda et frigos. Les photos et les positions ne sont pas incluses.</p>
    <form method="post" action="<?= e(url('export.php')) ?>">
      <?= csrf_field() ?>
      <button class="btn btn-block" type="submit">Télécharger mes données</button>
    </form>
  </section>

  <section class="card">
    <h2>Mes informations</h2>
    <p class="muted"><?= e($user['email']) ?></p>
    <form method="post" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="profile">
      <?php input_field('first_name', 'Prénom', 'text', ['value' => (string)$user['first_name'], 'autocomplete' => 'given-name', 'required' => true, 'errors' => $errors]); ?>
      <?php input_field('last_name', 'Nom', 'text', ['value' => (string)$user['last_name'], 'autocomplete' => 'family-name', 'errors' => $errors]); ?>
      <button class="btn btn-primary btn-block" type="submit">Enregistrer</button>
    </form>
  </section>

  <section class="card">
    <h2>Changer le mot de passe</h2>
    <form method="post" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <?php input_field('current_password', 'Mot de passe actuel', 'password', ['autocomplete' => 'current-password', 'required' => true, 'errors' => $errors]); ?>
      <?php input_field('password', 'Nouveau mot de passe', 'password', ['autocomplete' => 'new-password', 'required' => true, 'errors' => $errors]); ?>
      <?php input_field('confirm_password', 'Confirmer le nouveau mot de passe', 'password', ['autocomplete' => 'new-password', 'required' => true, 'errors' => $errors]); ?>
      <button class="btn btn-primary btn-block" type="submit">Changer le mot de passe</button>
    </form>
    <p class="muted small mt">Les autres appareils seront déconnectés après le changement.</p>
  </section>

  <section class="card">
    <h2>Appareils connectés</h2>
    <table class="table">
      <thead><tr><th>Appareil</th><th>Dernière activité</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($sessions as $s): $isMe = hash_equals($s['session_hash'], $me); ?>
        <tr>
          <td><?= e(mb_substr((string)$s['user_agent'], 0, 60)) ?: 'Appareil inconnu' ?><br>
              <span class="muted small"><?= e($s['ip_address']) ?></span>
              <?= $isMe ? '<span class="badge">Cet appareil</span>' : '' ?></td>
          <td class="small"><?= e($s['last_seen_at']) ?></td>
          <td>
            <form method="post" class="inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="revoke">
              <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
              <button class="btn" type="submit"><?= $isMe ? 'Quitter' : 'Fermer' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <form method="post" class="mt">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="revoke_all">
      <button class="btn btn-block" type="submit">Me déconnecter partout</button>
    </form>
  </section>

  <section class="card">
    <form method="post" action="<?= e(url('deconnexion.php')) ?>">
      <?= csrf_field() ?>
      <button class="btn btn-block" type="submit">Se déconnecter</button>
    </form>
  </section>

  <section class="card">
    <h2>Données et suppression du compte</h2>
    <p class="muted">Exportation des données et suppression du compte : pas encore développées.</p>
  </section>
</div>
<?php app_close('profil.php');
