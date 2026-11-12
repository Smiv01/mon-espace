<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

// Installation automatique de la base : disponible uniquement en mode dev.
if (cfg('env') !== 'dev') {
    http_response_code(404);
    exit('Page introuvable.');
}

$status = null;
$error = null;

if (is_post() && csrf_valid()) {
    try {
        $name = (string)cfg('db.name');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new RuntimeException('Nom de base invalide dans config/config.php.');
        }
        db(false)->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $sql = (string)file_get_contents(APP_ROOT . '/sql/schema.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $count = 0;
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            db()->exec($statement);
            $count++;
        }
        $status = "Base « $name » prête ($count instructions exécutées).";
    } catch (Exception $ex) {
        $error = 'Échec : ' . $ex->getMessage()
            . ' Vérifie que WAMP est démarré (icône verte) et que les réglages de config/config.php sont corrects.';
    }
}

auth_open('Installation', 'Crée la base de données et les tables nécessaires.');
if ($error) {
    error_list([$error]);
}
if ($status) {
    echo '<div class="form-ok" role="status">' . e($status) . '</div>';
    echo '<a class="btn btn-primary btn-block" href="' . e(url('index.php')) . '">Ouvrir l\'application</a>';
} else {
    ?>
    <form method="post">
      <?= csrf_field() ?>
      <button class="btn btn-primary btn-block" type="submit">Installer la base</button>
    </form>
    <?php
}
auth_close();
