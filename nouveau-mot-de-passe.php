<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/auth_flow.php';

$token = (string)opt($_GET, 'token', opt($_POST, 'token', ''));
$reset = find_valid_reset($token);

if (!$reset) {
    auth_open('Lien invalide', 'Ce lien est invalide, expiré ou déjà utilisé.');
    auth_close('<a href="' . e(url('mot-de-passe-oublie.php')) . '">Demander un nouveau lien</a>');
    exit;
}

$errors = [];
if (is_post()) {
    $password = (string)(opt($_POST, 'password', ''));
    $confirm = (string)(opt($_POST, 'confirm_password', ''));
    if (!csrf_valid()) {
        $errors['_'] = 'Session expirée. Recharge la page et réessaie.';
    } else {
        $pwErrors = password_errors($password);
        if ($pwErrors) {
            $errors['password'] = $pwErrors[0];
        } elseif ($password !== $confirm) {
            $errors['confirm_password'] = 'Les mots de passe ne correspondent pas.';
        }
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        // Consommation atomique du jeton : un seul usage possible.
        $use = $pdo->prepare('UPDATE password_resets SET used_at = ? WHERE id = ? AND used_at IS NULL AND expires_at > ?');
        $use->execute([now(), $reset['id'], now()]);
        if ($use->rowCount() !== 1) {
            $pdo->rollBack();
            redirect('nouveau-mot-de-passe.php?token=' . urlencode($token));
        }
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);
        $pdo->prepare('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL')
            ->execute([now(), $reset['user_id']]);
        $pdo->commit();

        revoke_all_sessions((int)$reset['user_id']);
        flash('ok', 'Mot de passe changé. Connecte-toi avec le nouveau.');
        redirect('connexion.php');
    }
}

auth_open('Nouveau mot de passe', 'Au moins 10 caractères, avec une lettre et un chiffre.');
if (!empty($errors['_'])) {
    error_list([$errors['_']]);
}
?>
<form method="post" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <?php input_field('password', 'Nouveau mot de passe', 'password', ['autocomplete' => 'new-password', 'required' => true, 'errors' => $errors]); ?>
  <?php input_field('confirm_password', 'Confirmer le mot de passe', 'password', ['autocomplete' => 'new-password', 'required' => true, 'errors' => $errors]); ?>
  <button class="btn btn-primary btn-block" type="submit">Changer le mot de passe</button>
</form>
<?php auth_close();
