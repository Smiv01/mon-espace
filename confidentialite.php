<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

auth_open("Politique de confidentialité");
echo '<p class="muted">Texte provisoire : les politique de confidentialité définitive doivent être rédigées et validées avant le lancement public.</p>';
auth_close('<a href="' . e(url('index.php')) . '">Retour</a>');
