<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

try {
    if (current_user()) {
        redirect('accueil.php');
    }
} catch (PDOException $ex) {
    redirect('install.php');
}

html_open('Bienvenue');
?>
<main class="auth-wrap"><div class="auth-card card splash">
  <div class="hero"><?= brand_mark(96) ?></div>
  <div class="logo-name"><?= e(cfg('brand.name')) ?></div>
  <p class="muted"><?= e(cfg('brand.tagline')) ?></p>
  <div class="stack mt">
    <a class="btn btn-primary btn-block" href="<?= e(url('onboarding.php?etape=1')) ?>">Commencer</a>
    <a class="btn btn-ghost btn-block" href="<?= e(url('connexion.php')) ?>">Se connecter</a>
  </div>
  <p class="muted small mt">En continuant, tu acceptes les <a href="<?= e(url('conditions.php')) ?>">conditions d'utilisation</a> et la <a href="<?= e(url('confidentialite.php')) ?>">politique de confidentialité</a>.</p>
</div></main>
<?php html_close();
