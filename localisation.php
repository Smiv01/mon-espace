<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/money.php';
require __DIR__ . '/includes/geo.php';

$user = require_login();
$userId = (int)$user['id'];
geo_purge();

if (is_post()) {
    if (!csrf_valid()) {
        flash('error', 'Session expirée. Recharge la page et réessaie.');
        redirect('localisation.php');
    }
    $action = (string)opt($_POST, 'action', '');
    if ($action === 'share_on') {
        $mine = geo_my_circles($userId);
        $picked = [];
        $raw = isset($_POST['circles']) && is_array($_POST['circles']) ? $_POST['circles'] : [];
        foreach ($raw as $cid) {
            if (isset($mine[(int)$cid])) {
                $picked[(int)$cid] = true;
            }
        }
        if (!$mine) {
            flash('error', 'Crée d\'abord un cercle de proches (ou rejoins-en un) pour choisir avec qui partager.');
        } elseif (!$picked) {
            flash('error', 'Choisis au moins un cercle avec qui partager ta position.');
        } else {
            $exp = geo_expiry_for((int)opt($_POST, 'hours', 1));
            // Les cercles décochés ne voient plus rien ; les autres reçoivent la nouvelle durée.
            foreach (array_keys($mine) as $cid) {
                if (!isset($picked[$cid])) {
                    db()->prepare('DELETE FROM location_shares WHERE user_id = ? AND circle_id = ?')->execute([$userId, $cid]);
                    continue;
                }
                $has = db()->prepare('SELECT id FROM location_shares WHERE user_id = ? AND circle_id = ?');
                $has->execute([$userId, $cid]);
                $sid = $has->fetchColumn();
                if ($sid) {
                    db()->prepare('UPDATE location_shares SET expires_at = ? WHERE id = ?')->execute([$exp, $sid]);
                } else {
                    db()->prepare('INSERT INTO location_shares (user_id, circle_id, expires_at) VALUES (?, ?, ?)')->execute([$userId, $cid, $exp]);
                }
            }
            flash('ok', 'Localisation activée. Tes proches te voient en direct. Garde l\'appli ouverte pour que ta position se mette à jour.');
        }
        redirect('localisation.php');
    }
    if ($action === 'share_off') {
        db()->prepare('DELETE FROM location_shares WHERE user_id = ?')->execute([$userId]);
        flash('ok', 'Localisation désactivée. Plus personne ne te voit, et ta position a été effacée.');
        redirect('localisation.php');
    }
    if ($action === 'circle_add' && once_valid()) {
        $name = post_str('name', 80);
        $n = db()->prepare('SELECT COUNT(*) FROM circles WHERE user_id = ?');
        $n->execute([$userId]);
        if ($name === '') {
            flash('error', 'Donne un nom à ton cercle (par exemple « Famille »).');
        } elseif ((int)$n->fetchColumn() >= 10) {
            flash('error', 'Tu as déjà 10 cercles. Supprime-en un pour en créer un autre.');
        } else {
            db()->prepare('INSERT INTO circles (user_id, name) VALUES (?, ?)')->execute([$userId, $name]);
            flash('ok', 'Cercle créé. Invite tes proches depuis l\'onglet Partage.');
            redirect('cercle.php?id=' . (int)db()->lastInsertId() . '&tab=partage');
        }
    }
    redirect('localisation.php');
}

$stmt = db()->prepare(
    'SELECT c.id, c.name, c.user_id,
            (SELECT COUNT(*) FROM circle_members m WHERE m.circle_id = c.id) AS n_members,
            (SELECT COUNT(*) FROM location_shares s WHERE s.circle_id = c.id AND s.expires_at > ?) AS n_live,
            (SELECT COUNT(*) FROM location_shares s WHERE s.circle_id = c.id AND s.user_id = ? AND s.expires_at > ?) AS i_share
       FROM circles c
      WHERE c.user_id = ? OR c.id IN (SELECT circle_id FROM circle_members WHERE user_id = ?)
      ORDER BY c.id'
);
$now = gmdate('Y-m-d H:i:s');
$stmt->execute([$now, $userId, $now, $userId, $userId]);
$circles = $stmt->fetchAll();

$tz = opt($user, 'timezone', 'Africa/Abidjan');
$my = geo_my_status($userId, $tz);
$points = geo_visible_points($userId, $tz);
$myCircles = geo_my_circles($userId);

allow_map_tiles();
app_open('Localisation', 'localisation.php', $user);
?>
<header class="page-hero">
  <img class="mod-ico" src="<?= e(url('assets/icons/loc.svg')) ?>" alt="" width="56" height="56">
  <div><h1>Localisation</h1><p class="muted">Ta position, seulement quand tu le décides</p></div>
</header>

<div class="card map-card">
  <div id="map" class="map" data-points="<?= e(json_encode($points)) ?>" data-url="<?= e(url('localisation-data.php?all=1')) ?>"
       data-update="<?= e(url('localisation-update.php')) ?>" data-csrf="<?= e(csrf_token()) ?>" data-sharing="<?= $my['on'] ? '1' : '0' ?>" data-follow="1"
       data-tiles="https://tile.openstreetmap.org" role="img" aria-label="Carte : ma position et celle de mes proches"></div>
  <p class="map-credit small">© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a></p>
  <noscript><p class="muted small">La carte demande JavaScript.</p></noscript>
</div>
<p class="muted small" id="geo-status" aria-live="polite"></p>

<?php
ob_start();
if (!$myCircles): ?>
  <div class="muted small mt">Pour partager, crée un cercle (Famille, Amis…) ci-dessous et invite tes proches.</div>
<?php else: ?>
  <form method="post" class="mt">
    <?= csrf_field() ?><input type="hidden" name="action" value="share_on">
    <p class="muted small">Pendant combien de temps ?</p>
    <div class="chips">
      <label class="chip"><input type="radio" name="hours" value="1" checked><span>1 heure</span></label>
      <label class="chip"><input type="radio" name="hours" value="4"><span>4 heures</span></label>
      <label class="chip"><input type="radio" name="hours" value="8"><span>8 heures</span></label>
      <label class="chip"><input type="radio" name="hours" value="0"><span>Jusqu'à ce que j'arrête</span></label>
    </div>
    <p class="muted small mt">Avec qui ?</p>
    <div class="chips">
      <?php foreach ($myCircles as $cid => $cname): ?>
        <label class="chip"><input type="checkbox" name="circles[]" value="<?= (int)$cid ?>" <?= (!$my['on'] || isset($my['circles'][$cid])) ? 'checked' : '' ?>><span><?= e($cname) ?></span></label>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-primary btn-block mt" type="submit"><?= $my['on'] ? 'Enregistrer' : 'Activer ma localisation' ?></button>
  </form>
<?php endif;
$shareForm = ob_get_clean(); ?>
<div class="card mt">
  <?php if ($my['on']): ?>
    <p><strong>🟢 Ta localisation est activée</strong></p>
    <p class="muted small">Vue en direct par : <?= e(implode(', ', $my['circles'])) ?>. <?= $my['forever'] ? 'Jusqu\'à ce que tu la désactives.' : 'Elle s\'arrête toute seule à ' . e($my['until']) . '.' ?> Garde l'appli ouverte pour que ta position se mette à jour.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="share_off"><button class="btn btn-block" type="submit">Désactiver ma localisation</button></form>
    <details class="mt">
      <summary class="small"><strong>Changer la durée ou les cercles</strong></summary>
      <?= $shareForm ?>
    </details>
  <?php else: ?>
    <p><strong>⚪ Ta localisation est désactivée</strong></p>
    <p class="muted small">Personne ne voit où tu es. Ta position sur la carte ci-dessus reste sur ton téléphone. Active-la pour que tes proches te voient en direct.</p>
    <?= $shareForm ?>
  <?php endif; ?>
</div>

<h2 class="section-h">Mes proches en direct</h2>
<div class="task-list" id="people">
  <?php if (!$points): ?><div class="card muted">Personne ne partage sa position pour le moment.</div><?php endif; ?>
  <?php foreach ($points as $p): ?>
    <div class="task"><span class="mini-av"><?= e(mb_strtoupper(mb_substr($p['name'], 0, 1))) ?></span>
      <span class="task-main"><strong><?= e($p['name']) ?><?= $p['me'] ? ' (toi)' : '' ?></strong><span class="sub"><?= e(ago_text($p['ago'])) ?></span></span></div>
  <?php endforeach; ?>
</div>

<div class="card muted small mt">🔒 Rien n'est partagé par défaut. Tu actives et tu désactives quand tu veux, avec la durée de ton choix. Seule ta dernière position est gardée, puis effacée dès que tu désactives.</div>

<h2 class="section-h">Mes cercles</h2>
<div class="task-list">
  <?php if (!$circles): ?><div class="card muted">Aucun cercle pour le moment. Crée « Famille » ou « Amis » ci-dessous.</div><?php endif; ?>
  <?php foreach ($circles as $c): $mine = (int)$c['user_id'] === $userId; ?>
    <a class="task day-row" href="<?= e(url('cercle.php?id=' . (int)$c['id'])) ?>">
      <span class="task-main"><strong><?= e($c['name']) ?><?php if ((int)$c['i_share'] > 0): ?> <span class="badge-shared live">Je partage</span><?php endif; ?></strong>
        <span class="sub"><?= (int)$c['n_members'] + 1 ?> personne<?= (int)$c['n_members'] + 1 > 1 ? 's' : '' ?> · <?= (int)$c['n_live'] ?> en direct<?= $mine ? '' : ' · invité' ?></span></span>
    </a>
  <?php endforeach; ?>
</div>

<details class="card mt">
  <summary><strong>+ Nouveau cercle</strong></summary>
  <form method="post" class="mt">
    <?= csrf_field() ?><?= once_field() ?><input type="hidden" name="action" value="circle_add">
    <label class="field"><span>Nom du cercle</span><input class="input" type="text" name="name" maxlength="80" placeholder="Famille" required></label>
    <button class="btn btn-primary btn-block mt" type="submit">Créer le cercle</button>
  </form>
</details>
<script src="<?= e(url('assets/map.js')) ?>" defer></script>
<?php app_close('localisation.php');
