<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/auth_flow.php';

if (current_user()) {
    redirect('accueil.php');
}

$error = null;
$sent = false;

if (is_post()) {
    $email = normalize_email((string)(opt($_POST, 'email', '')));
    if (!csrf_valid()) {
        $error = 'Session expirée. Recharge la page et réessaie.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Adresse e-mail invalide.';
    } else {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ? AND email_verified_at IS NOT NULL');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user) {
            create_reset_link((int)$user['id'], $email);
        }
        // Même message que le compte existe ou non.
        $sent = true;
    }
}

auth_open('Mot de passe oublié', 'Saisis l\'adresse e-mail de ton compte : si elle existe, tu recevras un lien sécurisé.');
if ($error) {
    error_list([$error]);
}
if ($sent) {
    echo '<div class="form-ok" role="status">Si un compte existe avec cette adresse, un lien de récupération vient d\'être envoyé.</div>';
    if (cfg('env') === 'dev') {
        echo '<p class="muted small">Mode développement : ouvre la <a href="' . e(url('dev-mails.php')) . '" target="_blank" rel="noopener">boîte de développement</a> pour lire le lien.</p>';
    }
}
?>
<form method="post" novalidate>
  <?= csrf_field() ?>
  <?php input_field('email', 'Adresse e-mail du compte', 'email', ['autocomplete' => 'email', 'required' => true]); ?>
  <button class="btn btn-primary btn-block" type="submit">Envoyer le lien</button>
</form>
<?php auth_close('<a href="' . e(url('connexion.php')) . '">Retour à la connexion</a>');
