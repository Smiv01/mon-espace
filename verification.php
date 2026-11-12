<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/auth_flow.php';

if (current_user()) {
    redirect('accueil.php');
}

$email = (string)(opt($_SESSION, 'pending_email', ''));
if ($email === '') {
    redirect('connexion.php');
}

$stmt = db()->prepare('SELECT id, email_verified_at FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (is_post()) {
    if (!csrf_valid()) {
        flash('error', 'Session expirée. Recharge la page et réessaie.');
    } elseif ((opt($_POST, 'action', '')) === 'resend') {
        if ($user && $user['email_verified_at'] === null) {
            $age = latest_code_age_seconds((int)$user['id']);
            if ($age !== null && $age < 60) {
                flash('error', 'Attends une minute avant de demander un nouveau code.');
            } else {
                issue_verification_code((int)$user['id'], $email);
                flash('ok', 'Si cette adresse peut être confirmée, un nouveau code vient d\'être envoyé.');
            }
        } else {
            flash('ok', 'Si cette adresse peut être confirmée, un nouveau code vient d\'être envoyé.');
        }
    } else {
        $code = preg_replace('/\D/', '', (string)(opt($_POST, 'code', '')));
        if ($user && $user['email_verified_at'] === null && strlen($code) === 6
            && check_verification_code((int)$user['id'], $code)) {
            db()->prepare('UPDATE users SET email_verified_at = ? WHERE id = ?')->execute([now(), $user['id']]);
            unset($_SESSION['pending_email']);
            login_user((int)$user['id']);
            redirect(after_login_target());
        }
        flash('error', 'Code invalide ou expiré.');
    }
    redirect('verification.php');
}

auth_open('Vérifie ton adresse e-mail', 'Si ' . $email . ' peut être confirmée, un code à 6 chiffres vient d\'être envoyé.');
if (cfg('env') === 'dev') {
    echo '<p class="muted small">Mode développement : aucun e-mail réel n\'est envoyé. Ouvre la <a href="' . e(url('dev-mails.php')) . '" target="_blank" rel="noopener">boîte de développement</a> pour lire le code.</p>';
}
?>
<form method="post" novalidate>
  <?= csrf_field() ?>
  <div class="field">
    <label for="code">Code de vérification</label>
    <input class="input code-input" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
  </div>
  <button class="btn btn-primary btn-block" type="submit">Valider</button>
</form>
<form method="post" class="mt">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="resend">
  <button class="btn btn-block" type="submit">Renvoyer un code</button>
</form>
<?php auth_close('<a href="' . e(url('connexion.php')) . '">Retour à la connexion</a>');
