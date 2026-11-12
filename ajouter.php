<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/money.php';
require __DIR__ . '/includes/ocr.php';

// Écran unique pour une dépense, une entrée d'argent ou un transfert : le montant d'abord, le reste est déjà rempli.
$user = require_login();
$userId = (int)$user['id'];
$today = today_for($user);
ensure_categories($userId);

$type = (string)opt($_GET, 'type', 'depense');
if (!in_array($type, ['depense', 'entree', 'transfert'], true)) {
    $type = 'depense';
}
$accounts = active_accounts($userId);
if (!$accounts) {
    flash('error', "Choisis d'abord où se trouve ton argent.");
    redirect('finances.php');
}
if ($type === 'transfert' && count($accounts) < 2) {
    flash('error', 'Pour un transfert, il faut au moins deux comptes (par exemple Espèces et Wave).');
    redirect('comptes.php');
}
$kind = $type === 'entree' ? 'income' : 'expense';
$errors = [];
$pre = (int)opt($_GET, 'compte', 0);
$fromDefault = $pre ?: (int)$accounts[0]['id'];
$toDefault = 0;
foreach ($accounts as $a) {
    if ((int)$a['id'] !== $fromDefault) {
        $toDefault = (int)$a['id'];
        break;
    }
}

// Valeurs proposées après la lecture d'un SMS (à vérifier par l'utilisateur, jamais enregistrées seules).
$prefill = [];
if (isset($_SESSION['prefill']) && is_array($_SESSION['prefill'])) {
    $prefill = $_SESSION['prefill'];
    unset($_SESSION['prefill']);
}

if (is_post()) {
    if (!csrf_valid()) {
        flash('error', 'Session expirée. Recharge la page et réessaie.');
        redirect('ajouter.php?type=' . $type);
    }
    $act = (string)opt($_POST, 'action', '');
    if ($act === 'sms_parse' || $act === 'shot_parse') {
        $text = '';
        if ($act === 'shot_parse') {
            list($text, $ocrErr) = ocr_read_upload(isset($_FILES['shot']) ? $_FILES['shot'] : null);
            if ($text === null) {
                flash('error', $ocrErr);
                redirect('ajouter.php?type=' . $type);
            }
            $r = parse_sms($text, 3000);
        } else {
            $r = parse_sms(opt($_POST, 'sms', ''));
        }
        if ($r['amount'] === null) {
            flash('error', $act === 'shot_parse'
                ? "Je n'ai pas trouvé de montant sur cette capture. Écris-le toi-même ci-dessous."
                : "Je n'ai pas trouvé de montant dans ce SMS. Écris-le toi-même ci-dessous.");
            redirect('ajouter.php?type=' . $type);
        }
        $acc = 0;
        $wantType = $r['provider'] === 'wave' ? 'wave' : ($r['provider'] !== '' ? 'mobile_money' : '');
        foreach ($accounts as $a) {
            if (($wantType !== '' && $a['type'] === $wantType) || ($r['provider'] !== '' && stripos($a['name'], $r['provider']) !== false)) {
                $acc = (int)$a['id'];
                break;
            }
        }
        $_SESSION['prefill'] = ['amount' => (string)$r['amount'], 'label' => $r['label'], 'account_id' => $acc];
        $target = $r['kind'] === 'income' ? 'entree' : ($r['kind'] === 'expense' ? 'depense' : $type);
        flash('ok', ($act === 'shot_parse' ? 'Capture lue' : 'SMS lu') . ' : vérifie le montant puis valide.');
        redirect('ajouter.php?type=' . ($type === 'transfert' ? 'depense' : $target));
    }
    $amount = parse_amount(opt($_POST, 'amount', ''));
    $date = post_str('date', 10);
    if ($date === '') {
        $date = $today;
    }
    if ($amount === null) {
        $errors['amount'] = 'Écris un montant (chiffres seulement).';
    }
    if (!valid_date($date)) {
        $errors['date'] = 'Date invalide.';
    }

    if ($type === 'transfert') {
        $from = owned_account($userId, opt($_POST, 'from_id', 0));
        $to = owned_account($userId, opt($_POST, 'to_id', 0));
        if (!$from || !$to) {
            $errors['from_id'] = 'Choisis les deux comptes.';
        } elseif ((int)$from['id'] === (int)$to['id']) {
            $errors['to_id'] = 'Choisis deux comptes différents.';
        } elseif ($amount !== null && account_balance($userId, $from['id']) < $amount) {
            $errors['amount'] = 'Il n\'y a pas assez d\'argent sur ' . $from['name'] . '.';
        }
        if (!$errors) {
            if (once_valid()) {
                $group = bin2hex(random_bytes(8));
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $ins = $pdo->prepare('INSERT INTO transactions (user_id, account_id, kind, amount, occurred_on, label, transfer_group) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $ins->execute([$userId, $from['id'], 'transfer_out', $amount, $date, 'Transfert vers ' . $to['name'], $group]);
                    $ins->execute([$userId, $to['id'], 'transfer_in', $amount, $date, 'Transfert depuis ' . $from['name'], $group]);
                    $pdo->commit();
                } catch (Exception $ex) {
                    $pdo->rollBack();
                    throw $ex;
                }
                flash('ok', 'Transfert fait : ' . fcfa($amount) . ' de ' . $from['name'] . ' vers ' . $to['name'] . '.');
            }
            redirect('finances.php');
        }
    } else {
        $label = post_str('label', 160);
        $account = owned_account($userId, opt($_POST, 'account_id', 0));
        $cat = owned_category($userId, opt($_POST, 'category_id', 0), $kind);
        if (!$account) {
            $errors['account_id'] = 'Choisis un compte.';
        } elseif ($kind === 'expense' && $amount !== null) {
            // Impossible de dépenser plus que ce que contient le compte.
            $have = account_balance($userId, $account['id']);
            if ($amount > $have) {
                $errors['amount'] = 'Tu as seulement ' . fcfa(max(0, $have)) . ' sur ' . $account['name'] . '. Choisis un montant plus petit ou un autre compte.';
            }
        }
        if (!$errors) {
            if (once_valid()) {
                db()->prepare('INSERT INTO transactions (user_id, account_id, category_id, kind, amount, occurred_on, label) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$userId, $account['id'], $cat ? $cat['id'] : null, $kind, $amount, $date, $label !== '' ? $label : null]);
                flash('ok', ($kind === 'income' ? 'Entrée ajoutée : +' : 'Dépense ajoutée : −') . fcfa($amount));
            }
            redirect('finances.php');
        }
    }
}

$stmt = db()->prepare('SELECT * FROM categories WHERE user_id = ? AND kind = ? ORDER BY id');
$stmt->execute([$userId, $kind]);
$cats = $stmt->fetchAll();
$titles = ['depense' => 'Nouvelle dépense', 'entree' => "Argent reçu", 'transfert' => 'Transfert'];
$cta = ['depense' => 'Valider la dépense', 'entree' => "Valider l'entrée", 'transfert' => 'Transférer'];

app_open($titles[$type], 'finances.php', $user);
?>
<a class="back-link" href="<?= e(url('finances.php')) ?>">← Retour</a>
<h1 class="center"><?= e($titles[$type]) ?></h1>
<?php if ($type !== 'transfert'): ?>
<details class="card add-card send-form sms-box">
  <summary>✉ Coller un SMS de Wave, Orange, MTN ou Moov</summary>
  <form method="post" novalidate class="mt">
    <?= csrf_field() ?><input type="hidden" name="action" value="sms_parse">
    <textarea class="input area" name="sms" maxlength="600" placeholder="Colle ici le SMS de confirmation" aria-label="SMS de confirmation"></textarea>
    <p class="muted small">Le SMS est lu pour remplir le montant. Il n'est pas gardé, et rien n'est enregistré avant ta validation.</p>
    <button class="btn btn-block" type="submit">Lire le SMS</button>
  </form>
</details>
<details class="card add-card send-form sms-box">
  <summary>📷 Envoyer une capture d'écran du message</summary>
  <form method="post" enctype="multipart/form-data" novalidate class="mt">
    <?= csrf_field() ?><input type="hidden" name="action" value="shot_parse">
    <input class="input" type="file" name="shot" accept="image/png,image/jpeg" aria-label="Capture d'écran">
    <p class="muted small">La capture est lue pour trouver le montant, puis supprimée aussitôt. Rien n'est enregistré avant ta validation.</p>
    <button class="btn btn-block" type="submit">Lire la capture</button>
  </form>
</details>
<?php endif; ?>
<form method="post" novalidate class="send-form">
  <?= csrf_field() ?><?= once_field() ?>
  <?= amount_input((string)opt($_POST, 'amount', opt($prefill, 'amount', '')), (string)opt($errors, 'amount', '')) ?>

  <?php if ($type === 'transfert'): ?>
    <div class="field"><span class="lbl">De</span><div class="chips">
      <?php foreach ($accounts as $i => $a): ?><label class="chip"><input type="radio" name="from_id" value="<?= (int)$a['id'] ?>"<?= (int)$a['id'] === $fromDefault ? ' checked' : '' ?>><span><?= e($a['name']) ?><small><?= e(fcfa($a['balance'])) ?></small></span></label><?php endforeach; ?>
    </div></div>
    <div class="field"><span class="lbl">Vers</span><div class="chips">
      <?php foreach ($accounts as $i => $a): ?><label class="chip"><input type="radio" name="to_id" value="<?= (int)$a['id'] ?>"<?= (int)$a['id'] === $toDefault ? ' checked' : '' ?>><span><?= e($a['name']) ?></span></label><?php endforeach; ?>
    </div></div>
    <?php field_error($errors, 'from_id'); field_error($errors, 'to_id'); ?>
    <p class="muted small center">Un transfert déplace ton argent : il n'est compté ni comme revenu ni comme dépense.</p>
  <?php else: ?>
    <?= account_picker($accounts, 'account_id', (int)opt($_POST, 'account_id', opt($prefill, 'account_id', $pre))) ?>
    <div class="field"><span class="lbl"><?= $kind === 'income' ? "D'où vient l'argent ?" : 'Pour quoi ?' ?></span><div class="chips">
      <label class="chip"><input type="radio" name="category_id" value="0" checked><span>Autre</span></label>
      <?php foreach ($cats as $c): ?><label class="chip"><input type="radio" name="category_id" value="<?= (int)$c['id'] ?>"><span><?= e($c['name']) ?></span></label><?php endforeach; ?>
    </div></div>
    <?php field_error($errors, 'account_id'); ?>
    <?php input_field('label', 'Note (facultative)', 'text', ['value' => (string)opt($_POST, 'label', opt($prefill, 'label', '')), 'attrs' => 'maxlength="160"']); ?>
  <?php endif; ?>

  <details class="more"><summary class="link">Ce n'était pas aujourd'hui ?</summary>
    <?php input_field('date', 'Date', 'date', ['value' => (string)opt($_POST, 'date', $today), 'errors' => $errors]); ?>
  </details>
  <button class="btn btn-primary btn-block btn-lg" type="submit"><?= e($cta[$type]) ?></button>
</form>
<?php app_close('finances.php');
