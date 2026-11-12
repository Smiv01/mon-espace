<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/qr.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/money.php';
require __DIR__ . '/includes/stock.php';
require __DIR__ . '/includes/ocr.php';

$user = require_login();
$userId = (int)$user['id'];
$today = today_for($user);
$space = space_access($userId, opt($_GET, 'id', 0));
if (!$space) {
    flash('error', 'Cet espace est introuvable.');
    redirect('espaces.php');
}
$sid = (int)$space['id'];
$isOwner = $space['role'] === 'owner';
$tabs = ['stock', 'courses', 'partage', 'achat'];
$tab = in_array(opt($_GET, 'tab', ''), $tabs, true) ? (string)$_GET['tab'] : 'stock';
$self = 'espace.php?id=' . $sid;
$errors = [];

function owned_item($table, $spaceId, $id)
{
    $table = $table === 'shopping_items' ? 'shopping_items' : 'space_items';
    $stmt = db()->prepare('SELECT * FROM ' . $table . ' WHERE id = ? AND space_id = ?');
    $stmt->execute([(int)$id, (int)$spaceId]);
    return $stmt->fetch();
}

$stay = false;
if (is_post()) {
    if (!csrf_valid()) {
        flash('error', 'Session expirée. Recharge la page et réessaie.');
        redirect($self . ($tab !== 'stock' ? '&tab=' . $tab : ''));
    }
    $action = (string)opt($_POST, 'action', '');
    $back = $self;

    if ($action === 'stock_minus') {
        // −1 au stock : l'exemplaire utilisé s'ajoute à la liste de courses (à racheter).
        $it = owned_item('space_items', $sid, post_id());
        if ($it) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                if ((int)$it['quantity'] > 1) {
                    $pdo->prepare('UPDATE space_items SET quantity = quantity - 1 WHERE id = ? AND space_id = ?')->execute([$it['id'], $sid]);
                } else {
                    $pdo->prepare('DELETE FROM space_items WHERE id = ? AND space_id = ?')->execute([$it['id'], $sid]);
                }
                shopping_add($userId, $sid, $it['name'], 1);
                $pdo->commit();
            } catch (Exception $ex) {
                $pdo->rollBack();
                throw $ex;
            }
            flash('ok', (int)$it['quantity'] > 1
                ? $it['name'] . ' : 1 ajouté à ta liste de courses.'
                : $it['name'] . ' : il n\'y en a plus. Ajouté à ta liste de courses.');
        }
    } elseif ($action === 'stock_edit') {
        // Correction d'un produit : nom, date de péremption, et quantité en moins seulement (on ajoute via Courses).
        $it = owned_item('space_items', $sid, post_id());
        $name = post_str('name', 100);
        $qty = parse_qty(opt($_POST, 'quantity', ''));
        $date = post_str('expires_on', 10);
        if (!$it) {
            // produit introuvable : on ne fait rien
        } elseif ($name === '') {
            flash('error', 'Le produit a besoin d\'un nom.');
            $back = $self . '&edit=' . (int)$it['id'];
        } elseif ($qty === null || $qty > (int)$it['quantity']) {
            flash('error', 'Quantité invalide : tu peux la corriger vers le bas. Pour en ajouter, passe par Courses.');
            $back = $self . '&edit=' . (int)$it['id'];
        } elseif ($date !== '' && !valid_date($date)) {
            flash('error', 'Date de péremption invalide.');
            $back = $self . '&edit=' . (int)$it['id'];
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $exp = $date !== '' ? $date : null;
                // Même produit (nom et date identiques) déjà en stock : on fusionne les quantités.
                $stmt = $pdo->prepare('SELECT id, name, quantity, expires_on FROM space_items WHERE space_id = ? AND id <> ?');
                $stmt->execute([$sid, $it['id']]);
                $twin = null;
                foreach ($stmt->fetchAll() as $o) {
                    if (fold_text($o['name']) === fold_text($name) && (string)$o['expires_on'] === (string)$exp) {
                        $twin = $o;
                        break;
                    }
                }
                if ($twin) {
                    $pdo->prepare('UPDATE space_items SET quantity = quantity + ? WHERE id = ? AND space_id = ?')->execute([$qty, $twin['id'], $sid]);
                    $pdo->prepare('DELETE FROM space_items WHERE id = ? AND space_id = ?')->execute([$it['id'], $sid]);
                } else {
                    $pdo->prepare('UPDATE space_items SET name = ?, quantity = ?, expires_on = ? WHERE id = ? AND space_id = ?')->execute([$name, $qty, $exp, $it['id'], $sid]);
                }
                $pdo->commit();
            } catch (Exception $ex) {
                $pdo->rollBack();
                throw $ex;
            }
            flash('ok', 'Produit modifié.');
        }
    } elseif ($action === 'stock_delete') {
        db()->prepare('DELETE FROM space_items WHERE id = ? AND space_id = ?')->execute([post_id(), $sid]);
        flash('ok', 'Produit supprimé.');
    } elseif ($action === 'shop_add') {
        $back = $self . '&tab=courses';
        $name = post_str('name', 100);
        $qty = parse_qty(opt($_POST, 'quantity', ''));
        if ($name === '') {
            flash('error', 'Écris ce que tu veux acheter.');
        } elseif ($qty === null) {
            flash('error', 'Quantité invalide (un nombre entier, par exemple 2).');
        } elseif (once_valid()) {
            shopping_add($userId, $sid, $name, $qty);
            flash('ok', $name . ' ajouté aux courses.');
        }
    } elseif ($action === 'shop_delete') {
        $back = $self . '&tab=courses';
        db()->prepare('DELETE FROM shopping_items WHERE id = ? AND space_id = ?')->execute([post_id(), $sid]);
    } elseif ($action === 'shop_buy_one') {
        // « Acheté ✓ » sur un seul produit : on vérifie date de péremption et paiement avant de le ranger dans le stock.
        $it = owned_item('shopping_items', $sid, post_id());
        $back = $self . '&tab=courses';
        if ($it) {
            $_SESSION['purchase'] = ['space' => $sid, 'rows' => [['name' => $it['name'], 'qty' => (int)$it['quantity'], 'shop' => (int)$it['id']]], 'total' => null, 'source' => 'one'];
            $back = $self . '&tab=achat';
        }
    } elseif ($action === 'shop_finish') {
        // « J'ai fini mes courses » : tout ce qui est sur la liste est proposé ; ce que tu décoches reste sur la liste.
        $back = $self . '&tab=courses';
        $stmt = db()->prepare('SELECT id, name, quantity FROM shopping_items WHERE space_id = ? ORDER BY id');
        $stmt->execute([$sid]);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = ['name' => $r['name'], 'qty' => (int)$r['quantity'], 'shop' => (int)$r['id']];
        }
        if ($rows) {
            $_SESSION['purchase'] = ['space' => $sid, 'rows' => $rows, 'total' => null, 'source' => 'all'];
            $back = $self . '&tab=achat';
        } else {
            flash('error', 'Ta liste de courses est vide : rien à acheter.');
        }
    } elseif ($action === 'receipt_scan') {
        $back = $self . '&tab=courses';
        if (function_exists('set_time_limit')) {
            @set_time_limit(90);
        }
        list($text, $ocrErr) = ocr_read_upload(isset($_FILES['shot']) ? $_FILES['shot'] : null);
        if ($text === null) {
            flash('error', $ocrErr);
        } else {
            $found = parse_receipt($text);
            if (!$found) {
                flash('error', "Je n'ai reconnu aucun produit sur ce ticket. Essaie une photo bien à plat, nette et bien éclairée, ou ajoute à la main dans ta liste.");
            } else {
                $rows = [];
                foreach ($found as $f) {
                    $rows[] = ['name' => $f['name'], 'qty' => (int)$f['qty'], 'shop' => null];
                }
                $_SESSION['purchase'] = ['space' => $sid, 'rows' => $rows, 'total' => parse_receipt_total($text), 'source' => 'ticket'];
                flash('ok', count($rows) . ' produit(s) trouvé(s). Corrige si besoin, indique les dates puis valide.');
                $back = $self . '&tab=achat';
            }
        }
    } elseif ($action === 'purchase_cancel') {
        unset($_SESSION['purchase']);
        flash('ok', 'Achat abandonné. Rien n\'a été changé.');
        $back = $self . '&tab=courses';
    } elseif ($action === 'purchase_confirm') {
        $pur = isset($_SESSION['purchase']) && (int)$_SESSION['purchase']['space'] === $sid ? $_SESSION['purchase'] : null;
        $back = $self . '&tab=courses';
        if ($pur) {
            $in = isset($_POST['it']) && is_array($_POST['it']) ? $_POST['it'] : [];
            $todo = [];
            $err = '';
            foreach ($pur['rows'] as $i => $orig) {
                $r = isset($in[$i]) && is_array($in[$i]) ? $in[$i] : null;
                if (!$r || empty($r['keep'])) {
                    continue;
                }
                $nm = trim(mb_substr(trim((string)opt($r, 'name', '')), 0, 100));
                $qt = parse_qty(opt($r, 'qty', ''));
                $dt = trim((string)opt($r, 'date', ''));
                if ($nm === '') {
                    $err = 'Un produit coché n\'a pas de nom.';
                } elseif ($qt === null) {
                    $err = 'Quantité invalide pour « ' . $nm . ' » (un nombre entier).';
                } elseif ($dt !== '' && !valid_date($dt)) {
                    $err = 'Date de péremption invalide pour « ' . $nm . ' ».';
                }
                if ($err !== '') {
                    break;
                }
                $todo[] = ['name' => $nm, 'qty' => $qt, 'date' => $dt !== '' ? $dt : null, 'shop' => $orig['shop']];
            }
            if ($err === '' && !$todo) {
                $err = 'Coche au moins un produit acheté.';
            }
            $pay = (string)opt($_POST, 'pay', 'other') === 'me' ? 'me' : 'other';
            $amount = null;
            $account = null;
            if ($err === '' && $pay === 'me') {
                $amount = parse_amount(opt($_POST, 'amount', ''));
                $account = owned_account($userId, opt($_POST, 'account_id', 0));
                if ($amount === null) {
                    $err = 'Écris le montant payé (chiffres seulement), ou choisis « Quelqu\'un d\'autre a payé ».';
                } elseif (!$account) {
                    $err = 'Choisis le compte à débiter.';
                } elseif ($amount > account_balance($userId, $account['id'])) {
                    $err = 'Tu as seulement ' . fcfa(max(0, account_balance($userId, $account['id']))) . ' sur ' . $account['name'] . '. Choisis un montant plus petit ou un autre compte.';
                }
            }
            if ($err !== '') {
                flash('error', $err);
                $stay = true;
                $tab = 'achat';
            } elseif (once_valid()) {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    foreach ($todo as $t) {
                        stock_add($userId, $sid, $t['name'], $t['qty'], null, $t['date']);
                        $row = $t['shop'] ? owned_item('shopping_items', $sid, $t['shop']) : null;
                        if ($row) {
                            $left = (int)$row['quantity'] - $t['qty'];
                            if ($left > 0) {
                                $pdo->prepare('UPDATE shopping_items SET quantity = ? WHERE id = ? AND space_id = ?')->execute([$left, $row['id'], $sid]);
                            } else {
                                $pdo->prepare('DELETE FROM shopping_items WHERE id = ? AND space_id = ?')->execute([$row['id'], $sid]);
                            }
                        } elseif (!$t['shop']) {
                            shopping_resolve($sid, $t['name'], $t['qty']);
                        }
                    }
                    if ($pay === 'me') {
                        ensure_categories($userId);
                        $stmt = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND kind = 'expense' AND name = 'Alimentation' LIMIT 1");
                        $stmt->execute([$userId]);
                        $catId = $stmt->fetchColumn();
                        $pdo->prepare('INSERT INTO transactions (user_id, account_id, category_id, kind, amount, occurred_on, label) VALUES (?, ?, ?, ?, ?, ?, ?)')
                            ->execute([$userId, $account['id'], $catId ? $catId : null, 'expense', $amount, $today, mb_substr('Courses — ' . $space['name'], 0, 160)]);
                    }
                    $pdo->commit();
                } catch (Exception $ex) {
                    $pdo->rollBack();
                    throw $ex;
                }
                unset($_SESSION['purchase']);
                flash('ok', count($todo) . ' produit(s) rangé(s) dans le stock' . ($pay === 'me' ? ' · −' . fcfa($amount) . ' sur ' . $account['name'] : ' · ton budget n\'est pas touché') . '.');
                $back = $self;
            } else {
                unset($_SESSION['purchase']);
            }
        }
    } elseif ($action === 'space_delete') {
        if ($isOwner) {
            db()->prepare('DELETE FROM spaces WHERE id = ? AND user_id = ?')->execute([$sid, $userId]);
            flash('ok', 'Espace supprimé.');
        }
        redirect('espaces.php');
    } elseif ($action === 'space_leave') {
        if (!$isOwner) {
            db()->prepare('DELETE FROM space_members WHERE space_id = ? AND user_id = ?')->execute([$sid, $userId]);
            flash('ok', 'Tu as quitté « ' . $space['name'] . ' ».');
        }
        redirect('espaces.php');
    } elseif ($action === 'invite_create' && $isOwner) {
        $back = $self . '&tab=partage';
        $stmt = db()->prepare('SELECT COUNT(*) FROM space_invites WHERE space_id = ? AND used_at IS NULL AND expires_at > ?');
        $stmt->execute([$sid, gmdate('Y-m-d H:i:s')]);
        $stmt2 = db()->prepare('SELECT COUNT(*) FROM space_members WHERE space_id = ?');
        $stmt2->execute([$sid]);
        if ((int)$stmt->fetchColumn() >= 5) {
            flash('error', 'Tu as déjà 5 invitations en attente. Annule-en une ou attends qu\'elle soit utilisée.');
        } elseif ((int)$stmt2->fetchColumn() >= 10) {
            flash('error', 'Cet espace a déjà 10 membres.');
        } elseif (once_valid()) {
            $token = bin2hex(random_bytes(16));
            db()->prepare('INSERT INTO space_invites (space_id, created_by, token_hash, expires_at) VALUES (?, ?, ?, ?)')
                ->execute([$sid, $userId, hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + 7 * 86400)]);
            $_SESSION['new_invite'] = ['space' => $sid, 'token' => $token];
        }
    } elseif ($action === 'invite_cancel' && $isOwner) {
        $back = $self . '&tab=partage';
        db()->prepare('DELETE FROM space_invites WHERE id = ? AND space_id = ? AND used_at IS NULL')->execute([post_id(), $sid]);
        flash('ok', 'Invitation annulée.');
    } elseif ($action === 'member_remove' && $isOwner) {
        $back = $self . '&tab=partage';
        db()->prepare('DELETE FROM space_members WHERE space_id = ? AND user_id = ?')->execute([$sid, post_id('user_id')]);
        flash('ok', 'Membre retiré.');
    }
    if (!$stay) {
        redirect($back);
    }
}

$stmt = db()->prepare('SELECT * FROM space_items WHERE space_id = ? ORDER BY expires_on IS NULL, expires_on, name');
$stmt->execute([$sid]);
$items = $stmt->fetchAll();
$editItem = null;
if ($tab === 'stock' && isset($_GET['edit'])) {
    $editItem = owned_item('space_items', $sid, $_GET['edit']);
}
$stmt = db()->prepare('SELECT * FROM shopping_items WHERE space_id = ? ORDER BY id');
$stmt->execute([$sid]);
$shop = $stmt->fetchAll();

$stmt = db()->prepare('SELECT u.id, p.first_name, p.last_name FROM space_members m JOIN users u ON u.id = m.user_id LEFT JOIN profiles p ON p.user_id = u.id WHERE m.space_id = ? ORDER BY m.id');
$stmt->execute([$sid]);
$members = $stmt->fetchAll();
$stmt = db()->prepare('SELECT p.first_name FROM profiles p WHERE p.user_id = ?');
$stmt->execute([(int)$space['user_id']]);
$ownerName = (string)$stmt->fetchColumn();
$invites = [];
$newLink = '';
if ($tab === 'partage' && $isOwner) {
    $stmt = db()->prepare('SELECT id, expires_at FROM space_invites WHERE space_id = ? AND used_at IS NULL AND expires_at > ? ORDER BY id DESC');
    $stmt->execute([$sid, gmdate('Y-m-d H:i:s')]);
    $invites = $stmt->fetchAll();
    if (!empty($_SESSION['new_invite']) && (int)$_SESSION['new_invite']['space'] === $sid) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $newLink = $scheme . '://' . (isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', $_SERVER['HTTP_HOST']) : 'localhost') . url('rejoindre.php?code=' . $_SESSION['new_invite']['token']);
        unset($_SESSION['new_invite']);
    }
}
$purchase = isset($_SESSION['purchase']) && (int)$_SESSION['purchase']['space'] === $sid ? $_SESSION['purchase'] : null;
if ($tab === 'achat' && !$purchase) {
    $tab = 'courses';
}
$myAccounts = $tab === 'achat' ? active_accounts($userId) : [];
$shared = count($members) > 0 || !$isOwner;

app_open($space['name'], 'espaces.php', $user);
?>
<a class="back-link" href="<?= e(url('espaces.php')) ?>">← Mes frigos</a>
<header class="page-head"><h1><?= e($space['name']) ?><?php if ($shared): ?> <span class="badge-shared">Partagé</span><?php endif; ?></h1>
  <?php if ($isOwner): ?>
  <form method="post" class="inline" data-confirm="Supprimer cet espace avec tout son contenu (et le retirer à tous les membres) ?">
    <?= csrf_field() ?><input type="hidden" name="action" value="space_delete">
    <button class="icon-btn" type="submit" aria-label="Supprimer l'espace">🗑</button>
  </form>
  <?php endif; ?>
</header>

<div class="tabs three">
  <a class="tab<?= $tab === 'stock' ? ' on' : '' ?>" href="<?= e(url($self)) ?>">Stock (<?= count($items) ?>)</a>
  <a class="tab<?= $tab === 'courses' || $tab === 'achat' ? ' on' : '' ?>" href="<?= e(url($self . '&tab=courses')) ?>">Courses (<?= count($shop) ?>)</a>
  <a class="tab<?= $tab === 'partage' ? ' on' : '' ?>" href="<?= e(url($self . '&tab=partage')) ?>">Partage</a>
</div>

<?php if ($tab === 'achat' && $purchase): ?>
  <?php $posted = $stay && isset($_POST['it']) && is_array($_POST['it']) ? $_POST['it'] : null; ?>
  <form method="post" novalidate class="card">
    <?= csrf_field() ?><?= once_field() ?><input type="hidden" name="action" value="purchase_confirm">
    <h2>Ce que tu as acheté</h2>
    <p class="muted small">Décoche ce que tu n'as pas pris : ça reste sur ta liste. Indique la date de péremption si elle est écrite sur le produit (facultatif).</p>
    <?php foreach ($purchase['rows'] as $i => $r):
        $pr = $posted && isset($posted[$i]) && is_array($posted[$i]) ? $posted[$i] : null;
        $vName = $pr ? (string)opt($pr, 'name', '') : $r['name'];
        $vQty = $pr ? (string)opt($pr, 'qty', '') : (string)$r['qty'];
        $vDate = $pr ? (string)opt($pr, 'date', '') : '';
        $vKeep = $posted ? !empty($pr['keep']) : true; ?>
      <div class="buy-row">
        <label class="keep"><input type="checkbox" name="it[<?= (int)$i ?>][keep]" value="1"<?= $vKeep ? ' checked' : '' ?> aria-label="Acheté"></label>
        <input class="input" type="text" name="it[<?= (int)$i ?>][name]" value="<?= e($vName) ?>" maxlength="100" aria-label="Produit">
        <input class="input qty-in" type="text" inputmode="numeric" name="it[<?= (int)$i ?>][qty]" value="<?= e($vQty) ?>" maxlength="4" aria-label="Quantité">
        <label class="buy-date"><span class="small muted">À consommer avant</span>
          <input class="input" type="date" name="it[<?= (int)$i ?>][date]" value="<?= e($vDate) ?>"></label>
      </div>
    <?php endforeach; ?>

    <h2 class="mt">Qui a payé ?</h2>
    <?php $vPay = $stay ? (string)opt($_POST, 'pay', 'other') : ($myAccounts ? 'me' : 'other'); ?>
    <div class="chips">
      <?php if ($myAccounts): ?><label class="chip"><input type="radio" name="pay" value="me"<?= $vPay === 'me' ? ' checked' : '' ?>><span>Moi — retirer de mon budget</span></label><?php endif; ?>
      <label class="chip"><input type="radio" name="pay" value="other"<?= $vPay !== 'me' ? ' checked' : '' ?>><span>Quelqu'un d'autre a payé</span></label>
    </div>
    <?php if ($myAccounts): ?>
      <p class="muted small">Si tu choisis « mon budget », le montant ci-dessous sera retiré du compte choisi.</p>
      <?= amount_input($stay ? (string)opt($_POST, 'amount', '') : ($purchase['total'] ? (string)$purchase['total'] : ''), '') ?>
      <?php if ($purchase['total'] && !$stay): ?><p class="muted small center">Total lu sur le ticket : <?= e(fcfa($purchase['total'])) ?>. Corrige si besoin.</p><?php endif; ?>
      <?= account_picker($myAccounts, 'account_id', $stay ? (int)opt($_POST, 'account_id', 0) : 0) ?>
    <?php else: ?>
      <p class="muted small">Tu n'as pas encore de compte dans ton budget. <a href="<?= e(url('finances.php')) ?>">Crées-en un</a> pour enregistrer tes dépenses.</p>
    <?php endif; ?>
    <button class="btn btn-primary btn-block btn-lg" type="submit">Valider et ranger dans le stock</button>
  </form>
  <form method="post" class="mt"><?= csrf_field() ?><input type="hidden" name="action" value="purchase_cancel"><button class="btn btn-block" type="submit">Annuler</button></form>
<?php elseif ($tab === 'partage'): ?>
  <div class="card">
    <h2>Qui a accès ?</h2>
    <div class="member-row"><span class="mini-av"><?= e(mb_strtoupper(mb_substr($isOwner ? ($user['first_name'] ?: '?') : ($ownerName ?: '?'), 0, 1))) ?></span><span><strong><?= e($isOwner ? ($user['first_name'] ?: 'Toi') : ($ownerName ?: 'Propriétaire')) ?></strong><span class="sub muted"><?= $isOwner ? 'Toi · propriétaire' : 'Propriétaire' ?></span></span></div>
    <?php foreach ($members as $m): $isMe = (int)$m['id'] === $userId; ?>
      <div class="member-row"><span class="mini-av"><?= e(mb_strtoupper(mb_substr((string)$m['first_name'] ?: '?', 0, 1))) ?></span>
        <span class="grow"><strong><?= e($m['first_name'] ?: 'Membre') ?></strong><span class="sub muted"><?= $isMe ? 'Toi · membre' : 'Membre' ?></span></span>
        <?php if ($isOwner): ?>
        <form method="post" class="inline" data-confirm="Retirer cette personne de l'espace ?">
          <?= csrf_field() ?><input type="hidden" name="action" value="member_remove"><input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>">
          <button class="icon-btn" type="submit" aria-label="Retirer">✕</button>
        </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <p class="muted small mt">Tous les membres peuvent voir et modifier le stock et la liste de courses. Seul le propriétaire peut inviter, retirer quelqu'un ou supprimer l'espace.</p>
  </div>

  <?php if ($isOwner): ?>
  <div class="card mt">
    <h2>Inviter quelqu'un</h2>
    <p class="muted small">Crée un lien, envoie-le (WhatsApp par exemple). Il marche une seule fois et pendant 7 jours. La personne doit avoir un compte Mon Espace et accepter pour rejoindre.</p>
    <?php if ($newLink !== ''): ?>
      <div class="invite-box">
        <p class="small"><strong>Voilà ton lien</strong> (il ne sera plus affiché après cette page) :</p>
        <?php $qr = qr_svg($newLink, 220); if ($qr !== ''): ?>
        <div class="qr-box"><?= $qr ?><p class="muted small">Ou fais scanner ce code QR avec le téléphone de la personne.</p></div>
        <?php endif; ?>
        <input class="input" id="invite-link" type="text" readonly value="<?= e($newLink) ?>" aria-label="Lien d'invitation">
        <button class="btn btn-block mt" type="button" data-copy="invite-link">Copier le lien</button>
        <a class="btn btn-primary btn-block mt" target="_blank" rel="noopener" href="<?= e('https://wa.me/?text=' . rawurlencode('Rejoins « ' . $space['name'] . ' » sur Mon Espace : ' . $newLink)) ?>">Envoyer sur WhatsApp</a>
      </div>
    <?php endif; ?>
    <form method="post" class="mt">
      <?= csrf_field() ?><?= once_field() ?><input type="hidden" name="action" value="invite_create">
      <button class="btn btn-primary btn-block" type="submit">Créer un lien d'invitation</button>
    </form>
    <?php foreach ($invites as $inv): ?>
      <div class="member-row"><span class="grow small muted">Invitation en attente · expire le <?= e(fr_date(substr($inv['expires_at'], 0, 10))) ?></span>
        <form method="post" class="inline" data-confirm="Annuler cette invitation ?">
          <?= csrf_field() ?><input type="hidden" name="action" value="invite_cancel"><input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
          <button class="icon-btn" type="submit" aria-label="Annuler l'invitation">✕</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <form method="post" class="mt" data-confirm="Quitter cet espace partagé ?">
    <?= csrf_field() ?><input type="hidden" name="action" value="space_leave">
    <button class="btn btn-block" type="submit">Quitter cet espace</button>
  </form>
  <?php endif; ?>
<?php elseif ($tab === 'stock' && $editItem): ?>
  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="action" value="stock_edit"><input type="hidden" name="id" value="<?= (int)$editItem['id'] ?>">
    <h2>Modifier le produit</h2>
    <label class="field"><span>Nom</span><input class="input" type="text" name="name" maxlength="100" value="<?= e($editItem['name']) ?>" required></label>
    <label class="field"><span>Quantité (au maximum <?= (int)$editItem['quantity'] ?>)</span><input class="input" type="text" inputmode="numeric" name="quantity" maxlength="4" value="<?= (int)$editItem['quantity'] ?>"></label>
    <label class="field"><span>À consommer avant (facultatif)</span><input class="input" type="date" name="expires_on" value="<?= e((string)$editItem['expires_on']) ?>"></label>
    <p class="muted small">Pour en ajouter, passe par l'onglet Courses.</p>
    <button class="btn btn-primary btn-block mt" type="submit">Enregistrer</button>
    <a class="btn btn-block mt" href="<?= e(url($self)) ?>">Annuler</a>
  </form>
<?php elseif ($tab === 'stock'): ?>
  <p class="muted small">Le stock se remplit quand tu achètes depuis l'onglet <a href="<?= e(url($self . '&tab=courses')) ?>">Courses</a>. Appuie sur <strong>−</strong> quand tu en utilises un : il s'ajoute à ta liste de courses.</p>
  <div class="task-list mt">
    <?php if (!$items): ?><div class="card muted">Le stock est vide. <a href="<?= e(url($self . '&tab=courses')) ?>">Fais ta liste de courses</a> puis appuie sur « Acheté ✓ ».</div><?php endif; ?>
    <?php foreach ($items as $it): list($cls, $txt) = expiry_state($it['expires_on'], $today); ?>
      <div class="task stock-row">
        <span class="task-main"><strong><?= e($it['name']) ?></strong>
          <?php if ($txt !== ''): ?><span class="sub <?= e($cls) ?>"><?= e($txt) ?></span><?php endif; ?></span>
        <form method="post" class="inline qty">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
          <button class="qty-btn" type="submit" name="action" value="stock_minus" aria-label="En utiliser un">−</button>
          <span class="qty-val"><?= (int)$it['quantity'] ?><?= $it['unit'] ? ' <small>' . e($it['unit']) . '</small>' : '' ?></span>
        </form>
        <a class="icon-btn" href="<?= e(url($self . '&edit=' . (int)$it['id'])) ?>" aria-label="Modifier">✎</a>
        <form method="post" class="inline" data-confirm="Supprimer ce produit ?">
          <?= csrf_field() ?><input type="hidden" name="action" value="stock_delete"><input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
          <button class="icon-btn" type="submit" aria-label="Supprimer">🗑</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <form method="post" novalidate class="card shop-add">
    <?= csrf_field() ?><?= once_field() ?><input type="hidden" name="action" value="shop_add">
    <h2>Ma liste de courses</h2>
    <div class="shop-fields">
      <?php input_field('name', 'À acheter', 'text', ['value' => '', 'required' => true, 'attrs' => 'maxlength="100" placeholder="Ex. Riz, huile…"']); ?>
      <?php input_field('quantity', 'Qté', 'text', ['value' => '1', 'attrs' => 'inputmode="numeric" maxlength="4"']); ?>
    </div>
    <button class="btn btn-primary btn-block" type="submit">Ajouter à la liste</button>
  </form>

  <div class="task-list mt">
    <?php if (!$shop): ?><div class="card muted">La liste est vide. Ajoute ce que tu veux acheter, ou scanne un ticket. Quand un produit est fini dans le stock, il arrive ici tout seul.</div><?php endif; ?>
    <?php foreach ($shop as $it): ?>
      <div class="task">
        <span class="task-main"><strong><?= e($it['name']) ?></strong><span class="sub">Quantité : <?= (int)$it['quantity'] ?></span></span>
        <form method="post" class="inline">
          <?= csrf_field() ?><input type="hidden" name="action" value="shop_buy_one"><input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
          <button class="btn btn-small" type="submit">Acheté ✓</button>
        </form>
        <form method="post" class="inline" data-confirm="Retirer de la liste ?">
          <?= csrf_field() ?><input type="hidden" name="action" value="shop_delete"><input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
          <button class="icon-btn" type="submit" aria-label="Retirer">🗑</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($shop): ?>
  <form method="post" class="mt">
    <?= csrf_field() ?><input type="hidden" name="action" value="shop_finish">
    <button class="btn btn-primary btn-block btn-lg" type="submit">J'ai fini mes courses</button>
    <p class="muted small center mt">Tu vérifies ce que tu as pris, puis ça passe dans le stock et quitte la liste.</p>
  </form>
  <?php endif; ?>

  <details class="card add-card mt">
    <summary>📷 Scanner un ticket de caisse</summary>
    <form method="post" enctype="multipart/form-data" novalidate class="mt">
      <?= csrf_field() ?><input type="hidden" name="action" value="receipt_scan">
      <input class="input" type="file" name="shot" accept="image/jpeg,image/png" capture="environment" aria-label="Photo du ticket">
      <p class="muted small">Prends en photo ton ticket (à plat, bien éclairé) ou choisis une image. Les produits lus te sont proposés : tu corriges, tu indiques les dates, puis tu valides. La photo est supprimée aussitôt après la lecture.</p>
      <button class="btn btn-block" type="submit">Lire le ticket</button>
    </form>
  </details>
<?php endif; ?>
<?php app_close('espaces.php');
