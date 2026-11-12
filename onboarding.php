<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$steps = [
    1 => ['Tout ce dont tu as besoin au même endroit', 'Agenda, budget, frigos, notes, partage, localisation… une seule appli pour ton quotidien.', 'agenda'],
    2 => ['Partage avec tes proches', 'Crée des espaces partagés (frigo, journée, localisation) et invite qui tu veux, par lien ou QR code.', 'journee'],
    3 => ['Tu gardes le contrôle', 'Tes finances, tes notes et ta position restent privées tant que tu ne décides pas de les partager.', 'lock'],
    4 => ['Prêt à commencer ?', 'Crée ton compte ou connecte-toi pour accéder à ton espace.', 'home'],
];
$n = (int)(opt($_GET, 'etape', 1));
if ($n < 1 || $n > 4) {
    $n = 1;
}
list($title, $text, $icon) = $steps[$n];

html_open($title);
?>
<main class="auth-wrap"><div class="auth-card card center">
  <div class="hero"><span class="tile t-frigo"><?= icon_svg($icon, 28) ?></span></div>
  <h1><?= e($title) ?></h1>
  <p class="muted"><?= e($text) ?></p>
  <div class="dots" aria-label="Étape <?= $n ?> sur 4">
    <?php for ($i = 1; $i <= 4; $i++): ?><i<?= $i === $n ? ' class="on"' : '' ?>></i><?php endfor; ?>
  </div>
  <?php if ($n < 4): ?>
    <a class="btn btn-primary btn-block" href="<?= e(url('onboarding.php?etape=' . ($n + 1))) ?>">Suivant</a>
    <p class="mt"><a href="<?= e(url('onboarding.php?etape=4')) ?>">Passer</a></p>
  <?php else: ?>
    <div class="stack">
      <a class="btn btn-primary btn-block" href="<?= e(url('inscription.php')) ?>">Créer un compte</a>
      <a class="btn btn-ghost btn-block" href="<?= e(url('connexion.php')) ?>">Se connecter</a>
    </div>
  <?php endif; ?>
</div></main>
<?php html_close();
