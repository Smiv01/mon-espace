<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/auth_flow.php';

if (current_user()) {
    redirect('accueil.php');
}

$errors = [];
$old = ['first_name' => '', 'last_name' => '', 'email' => ''];

if (is_post()) {
    if (!csrf_valid()) {
        $errors['_'] = 'Session expirée. Recharge la page et réessaie.';
    } else {
        $old['first_name'] = trim((string)(opt($_POST, 'first_name', '')));
        $old['last_name']  = trim((string)(opt($_POST, 'last_name', '')));
        $old['email']      = normalize_email((string)(opt($_POST, 'email', '')));
        $password = (string)(opt($_POST, 'password', ''));
        $confirm  = (string)(opt($_POST, 'confirm_password', ''));

        if ($old['first_name'] === '' || mb_strlen($old['first_name']) > 60) {
            $errors['first_name'] = 'Le prénom est obligatoire (60 caractères maximum).';
        }
        if (mb_strlen($old['last_name']) > 60) {
            $errors['last_name'] = 'Le nom est trop long (60 caractères maximum).';
        }
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($old['email']) > 190) {
            $errors['email'] = 'Adresse e-mail invalide.';
        }
        $pwErrors = password_errors($password);
        if ($pwErrors) {
            $errors['password'] = $pwErrors[0];
        } elseif ($password !== $confirm) {
            $errors['confirm_password'] = 'Les mots de passe ne correspondent pas.';
        }
        if (empty($_POST['accept_terms'])) {
            $errors['accept_terms'] = "Tu dois accepter les conditions d'utilisation.";
        }

        if (!$errors) {
            $pdo = db();
            $stmt = $pdo->prepare('SELECT id, email_verified_at FROM users WHERE email = ?');
            $stmt->execute([$old['email']]);
            $existing = $stmt->fetch();

            if ($existing) {
                // Même réponse qu'une nouvelle inscription : on ne révèle pas que l'adresse existe.
                if ($existing['email_verified_at'] === null) {
                    issue_verification_code((int)$existing['id'], $old['email']);
                } else {
                    send_mail(
                        $old['email'],
                        'Tentative d\'inscription',
                        "Bonjour,\n\nQuelqu'un a essayé de créer un compte avec cette adresse, qui est déjà inscrite. "
                        . "Si c'est toi, connecte-toi ou utilise « Mot de passe oublié ».\n"
                        . full_url('connexion.php')
                    );
                }
            } else {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')
                        ->execute([$old['email'], password_hash($password, PASSWORD_DEFAULT)]);
                    $userId = (int)$pdo->lastInsertId();
                    $pdo->prepare('INSERT INTO profiles (user_id, first_name, last_name) VALUES (?, ?, ?)')
                        ->execute([$userId, $old['first_name'], $old['last_name'] !== '' ? $old['last_name'] : null]);
                    $pdo->prepare('INSERT INTO user_preferences (user_id) VALUES (?)')->execute([$userId]);
                    $pdo->commit();
                } catch (Exception $ex) {
                    $pdo->rollBack();
                    throw $ex;
                }
                issue_verification_code($userId, $old['email']);
            }

            $_SESSION['pending_email'] = $old['email'];
            redirect('verification.php');
        }
    }
}

auth_open('Créer un compte', 'Quelques informations suffisent pour commencer.');
if (!empty($errors['_'])) {
    error_list([$errors['_']]);
}
?>
<form method="post" novalidate>
  <?= csrf_field() ?>
  <?php input_field('first_name', 'Prénom', 'text', ['value' => $old['first_name'], 'autocomplete' => 'given-name', 'required' => true, 'errors' => $errors]); ?>
  <?php input_field('last_name', 'Nom (facultatif)', 'text', ['value' => $old['last_name'], 'autocomplete' => 'family-name', 'errors' => $errors]); ?>
  <?php input_field('email', 'Adresse e-mail', 'email', ['value' => $old['email'], 'autocomplete' => 'email', 'required' => true, 'errors' => $errors]); ?>
  <?php input_field('password', 'Mot de passe (10 caractères minimum)', 'password', ['autocomplete' => 'new-password', 'required' => true, 'errors' => $errors]); ?>
  <?php input_field('confirm_password', 'Confirmer le mot de passe', 'password', ['autocomplete' => 'new-password', 'required' => true, 'errors' => $errors]); ?>
  <div class="checkbox">
    <input id="accept_terms" name="accept_terms" type="checkbox">
    <label for="accept_terms">J'accepte les <a href="<?= e(url('conditions.php')) ?>">conditions d'utilisation</a> et la <a href="<?= e(url('confidentialite.php')) ?>">politique de confidentialité</a>.</label>
  </div>
  <?php field_error($errors, 'accept_terms'); ?>
  <button class="btn btn-primary btn-block" type="submit">S'inscrire</button>
</form>
<?php social_row(); ?>
<?php auth_close('Déjà inscrit ? <a href="' . e(url('connexion.php')) . '">Se connecter</a>');
