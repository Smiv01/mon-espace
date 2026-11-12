<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/auth_flow.php';

if (current_user()) {
    redirect('accueil.php');
}

$errors = [];
$email = '';

if (is_post()) {
    $email = normalize_email((string)(opt($_POST, 'email', '')));
    $password = (string)(opt($_POST, 'password', ''));

    if (!csrf_valid()) {
        $errors['_'] = 'Session expirée. Recharge la page et réessaie.';
    } elseif ($email === '' || $password === '') {
        $errors['_'] = 'Saisis ton adresse e-mail et ton mot de passe.';
    } elseif (too_many_failures($email)) {
        $errors['_'] = 'Trop de tentatives. Réessaie dans ' . (int)cfg('security.lockout_minutes', 15) . ' minutes.';
    } else {
        $stmt = db()->prepare('SELECT id, password_hash, email_verified_at FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        $valid = password_verify($password, opt($user, 'password_hash', dummy_hash())) && $user;

        if (!$valid) {
            record_failure($email);
            $errors['_'] = 'Adresse e-mail ou mot de passe incorrect.';
        } elseif ($user['email_verified_at'] === null) {
            issue_verification_code((int)$user['id'], $email);
            $_SESSION['pending_email'] = $email;
            redirect('verification.php');
        } else {
            clear_failures($email);
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }
            login_user((int)$user['id']);
            redirect(after_login_target());
        }
    }
}

auth_open('Connexion');
if (!empty($errors['_'])) {
    error_list([$errors['_']]);
}
?>
<form method="post" novalidate>
  <?= csrf_field() ?>
  <?php input_field('email', 'Adresse e-mail', 'email', ['value' => $email, 'autocomplete' => 'email', 'required' => true]); ?>
  <?php input_field('password', 'Mot de passe', 'password', ['autocomplete' => 'current-password', 'required' => true]); ?>
  <button class="btn btn-primary btn-block" type="submit">Se connecter</button>
</form>
<p class="mt center"><a href="<?= e(url('mot-de-passe-oublie.php')) ?>">Mot de passe oublié ?</a></p>
<?php social_row(); ?>
<?php auth_close('Pas encore de compte ? <a href="' . e(url('inscription.php')) . '">Créer un compte</a>');
