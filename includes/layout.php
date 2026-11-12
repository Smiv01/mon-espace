<?php

// Gabarits partagés : pages publiques (auth_*) et pages connectées (app_*).

function html_open($title)
{
    $brand = cfg('brand.name');
    echo '<!doctype html><html lang="fr"><head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="color-scheme" content="light dark">';
    echo '<meta name="theme-color" content="#6F8672">';
    echo '<script src="' . e(url('assets/theme.js')) . '"></script>';
    echo '<title>' . e($title) . ' · ' . e($brand) . '</title>';
    echo '<link rel="icon" href="' . e(url('assets/icon.svg')) . '" type="image/svg+xml">';
    echo '<link rel="stylesheet" href="' . e(url('assets/style.css')) . '">';
    echo '</head><body>';
}

function html_close()
{
    echo '<script src="' . e(url('assets/app.js')) . '" defer></script></body></html>';
}

function render_flashes()
{
    foreach (take_flashes() as $f) {
        $class = $f['type'] === 'error' ? 'form-error' : 'form-ok';
        $role = $f['type'] === 'error' ? 'alert' : 'status';
        echo '<div class="' . $class . '" role="' . $role . '">' . e($f['message']) . '</div>';
    }
}

function error_list(array $errors)
{
    foreach ($errors as $msg) {
        echo '<div class="form-error" role="alert">' . e($msg) . '</div>';
    }
}

function field_error(array $errors, $name)
{
    if (!empty($errors[$name])) {
        echo '<span class="field-error" id="' . e($name) . '-error" role="alert">' . e($errors[$name]) . '</span>';
    }
}

function input_field($name, $label, $type = 'text', array $o = [])
{
    $errors = opt($o, 'errors', []);
    $value = $type === 'password' ? '' : (opt($o, 'value', ''));
    $auto = opt($o, 'autocomplete', '');
    $extra = opt($o, 'attrs', '');
    $id = opt($o, 'id', $name);
    echo '<div class="field"><label for="' . e($id) . '">' . e($label) . '</label><div class="row">';
    echo '<input class="input" id="' . e($id) . '" name="' . e($name) . '" type="' . e($type) . '"'
        . ($auto !== '' ? ' autocomplete="' . e($auto) . '"' : '')
        . ' value="' . e($value) . '"'
        . (!empty($o['required']) ? ' required' : '')
        . (!empty($errors[$name]) ? ' aria-invalid="true" aria-describedby="' . e($name) . '-error"' : '')
        . ' ' . $extra . '>';
    if ($type === 'password') {
        echo '<button type="button" class="btn js-toggle-password" data-target="' . e($name) . '" aria-label="Afficher le mot de passe">Voir</button>';
    }
    echo '</div>';
    field_error($errors, $name);
    echo '</div>';
}

// --- Pages publiques ---------------------------------------------------------
function brand_mark($size = 56)
{
    $petal = function ($fill, $rot) {
        return '<ellipse rx="6" ry="13" cy="-11" fill="' . $fill . '" transform="rotate(' . $rot . ')"/>';
    };
    return '<svg class="brand-mark" width="' . (int)$size . '" height="' . (int)$size . '" viewBox="0 0 64 64" aria-hidden="true">'
        . '<g transform="translate(32 34)">' . $petal('#6F8672', 0) . $petal('#A8985F', 90) . $petal('#8FA7A0', 180) . $petal('#B08968', 270) . '</g></svg>';
}

// Avatar : photo de l'utilisateur si elle existe, sinon son initiale.
function avatar_html(array $user, $class)
{
    $path = (string)opt($user, 'avatar_path', '');
    if ($path !== '') {
        return '<img class="' . e($class) . ' avatar-img" src="' . e(url('avatar.php?v=' . substr(md5($path), 0, 8))) . '" alt="Photo de profil">';
    }
    $initial = mb_strtoupper(mb_substr((string)opt($user, 'first_name', '?'), 0, 1));
    return '<span class="' . e($class) . '" aria-hidden="true">' . e($initial) . '</span>';
}

function social_row()
{
    echo '<div class="divider">ou continuer avec</div><div class="social">'
        . '<span class="social-btn g" role="img" aria-label="Google, bientôt disponible" title="Bientôt disponible">G</span>'
        . '</div>';
}

function auth_open($title, $subtitle = null)
{
    html_open($title);
    echo '<main class="auth-wrap"><div class="auth-card card">';
    echo '<h1>' . e($title) . '</h1>';
    if ($subtitle) {
        echo '<p class="muted">' . e($subtitle) . '</p>';
    }
    render_flashes();
}

function auth_close($footerHtml = '')
{
    if ($footerHtml !== '') {
        echo '<p class="muted footer-note">' . $footerHtml . '</p>';
    }
    echo '</div></main>';
    html_close();
}

// --- Pages connectées ------------------------------------------------------------
function icon_svg($name, $size = 22)
{
    $paths = [
        'home'     => 'M3 11l9-8 9 8v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z',
        'explore'  => 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zm9 16l-4-4',
        'plus'     => 'M12 5v14M5 12h14',
        'bell'     => 'M6 16V11a6 6 0 0 1 12 0v5l2 2H4zM10 21h4',
        'user'     => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm-8 9a8 8 0 0 1 16 0',
        'agenda'   => 'M5 4h14a1 1 0 0 1 1 1v15a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1zm0 5h15M8 2v4m8-4v4',
        'budget'   => 'M3 7h18v12H3zM3 11h18M16 15h2',
        'frigo'    => 'M7 3h10a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zm-1 7h12M9 6v2m0 5v3',
        'journee'  => 'M4 5h16v14H4zM4 15l5-5 4 4 3-3 4 4',
        'loc'      => 'M12 21s7-6.2 7-11a7 7 0 0 0-14 0c0 4.8 7 11 7 11zm0-8a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
        'notes'    => 'M6 3h9l4 4v14H6zM9 12h7M9 16h7M9 8h3',
        'lock'     => 'M6 11h12v9H6zM8 11V8a4 4 0 0 1 8 0v3',
        'gear'     => 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19 12l2-1-1-3-2 .5-1.5-1.5.5-2-3-1-1 2h-2l-1-2-3 1 .5 2L6 8.5 4 8l-1 3 2 1v2l-2 1 1 3 2-.5L7.5 19l-.5 2 3 1 1-2h2l1 2 3-1-.5-2 1.5-1.5 2 .5 1-3-2-1z',
        'chevron'  => 'M9 6l6 6-6 6',
        'logout'   => 'M10 4H5v16h5M15 8l4 4-4 4M9 12h10',
        'down'     => 'M12 5v14M6 13l6 6 6-6',
        'up'       => 'M12 19V5M6 11l6-6 6 6',
        'swap'     => 'M7 7h13M16 3l4 4-4 4M17 17H4M8 13l-4 4 4 4',
        'coin'     => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 7v10M9.5 9.5h3.5a1.8 1.8 0 0 1 0 3.5h-2.5a1.8 1.8 0 0 0 0 3.5H15',
        'help'     => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zm0 13v.5M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .9-1 1.7',
    ];
    $d = opt($paths, $name, '');
    return '<svg width="' . (int)$size . '" height="' . (int)$size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $d . '"/></svg>';
}

// Les six modules de la planche (nom, fichier, icône, classe de couleur, texte secondaire honnête).
function module_list()
{
    return [
        ['Agenda',       'agenda.php',       'agenda',  't-agenda',  'Tâches et événements'],
        ['Budget',       'finances.php',     'budget',  't-budget',  'Argent, épargne, dettes'],
        ['Frigos',       'espaces.php',      'frigo',   't-frigo',   'Stocks et courses'],
        ['Ma Journée',   'journees.php',     'journee', 't-journee', 'Souvenirs partagés'],
        ['Localisation', 'localisation.php', 'loc',     't-loc',     'Partage volontaire'],
        ['Notes',        'notes.php',        'notes',   't-notes',   'Idées et mémos'],
    ];
}

function nav_links($current, $extra)
{
    if ($extra) {
        // Barre latérale (ordinateur) : accueil + tous les modules + profil.
        $items = [['accueil.php', 'Accueil', 'home']];
        foreach (module_list() as $m) {
            $items[] = [$m[1], $m[0], $m[2]];
        }
        $items[] = ['notifications.php', 'Notifications', 'bell'];
        $items[] = ['profil.php', 'Profil et réglages', 'user'];
        foreach ($items as $it) {
            $active = $current === $it[0] ? ' aria-current="page"' : '';
            echo '<a class="nav-item" href="' . e(url($it[0])) . '"' . $active . '>' . icon_svg($it[2]) . '<span>' . e($it[1]) . '</span></a>';
        }
        return;
    }
    // Barre du bas (mobile), comme sur la planche : Accueil, Explorer, +, Notifications, Profil.
    $link = function ($file, $label, $icon) use ($current) {
        $active = $current === $file ? ' aria-current="page"' : '';
        echo '<a class="nav-item" href="' . e(url($file)) . '"' . $active . '>' . icon_svg($icon) . '<span>' . e($label) . '</span></a>';
    };
    $link('accueil.php', 'Accueil', 'home');
    $link('explorer.php', 'Explorer', 'explore');
    echo '<a class="fab" href="' . e(url('explorer.php')) . '" aria-label="Tous les modules">' . icon_svg('plus', 26) . '</a>';
    $link('notifications.php', 'Notifications', 'bell');
    $link('profil.php', 'Profil', 'user');
}

function app_open($title, $currentFile, array $user, $bare = false)
{
    html_open($title);
    echo '<div class="shell"><aside class="sidebar" aria-label="Navigation principale">';
    echo '<div class="brand-row">' . brand_mark(32) . '<strong>' . e(cfg('brand.name')) . '</strong></div>';
    nav_links($currentFile, true);
    echo '</aside><main class="content">';
    echo '<div class="topbar"><div class="theme-switch" role="group" aria-label="Thème d\'affichage">'
        . '<button type="button" data-theme-set="light" aria-pressed="false" title="Clair">☀ Clair</button>'
        . '<button type="button" data-theme-set="auto" aria-pressed="false" title="Comme le téléphone">Auto</button>'
        . '<button type="button" data-theme-set="dark" aria-pressed="false" title="Sombre">☾ Sombre</button>'
        . '</div></div>';
    if (!$bare) {
        echo '<div class="page">';
    }
    render_flashes();
}

function app_close($currentFile, $bare = false)
{
    if (!$bare) {
        echo '</div>';
    }
    echo '</main><nav class="bottom-nav" aria-label="Navigation principale">';
    nav_links($currentFile, false);
    echo '</nav></div>';
    html_close();
}

function coming_soon($title, $phase, $description, array $user, $file)
{
    app_open($title, $file, $user);
    echo '<header class="page-head"><h1>' . e($title) . '</h1></header>';
    echo '<div class="card stack"><p>' . e($description) . '</p>';
    echo '<span class="badge">Prévu en ' . e($phase) . ' — pas encore développé</span></div>';
    app_close($file);
}
