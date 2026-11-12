<?php

// Outils d'argent : montants entiers en XOF, soldes calculés depuis les écritures, anti-doublon.

/** Convertit « 12 500 » ou « 12500 » en entier > 0. Retourne null si invalide. */
function parse_amount($s)
{
    $s = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', (string)$s);
    if (!preg_match('/^\d{1,12}$/', $s)) {
        return null;
    }
    $n = (int)$s;
    return $n > 0 ? $n : null;
}

/** Montant pouvant valoir zéro (solde initial). Retourne null si invalide. */
function parse_amount_zero($s)
{
    $s = trim((string)$s);
    if ($s === '' || $s === '0') {
        return 0;
    }
    return parse_amount($s);
}

/** Jeton à usage unique pour qu'un double clic ou un rechargement ne crée pas deux écritures. */
function once_field()
{
    $t = bin2hex(random_bytes(16));
    if (!isset($_SESSION['once']) || !is_array($_SESSION['once'])) {
        $_SESSION['once'] = [];
    }
    $_SESSION['once'][$t] = time();
    if (count($_SESSION['once']) > 50) {
        array_shift($_SESSION['once']);
    }
    return '<input type="hidden" name="once" value="' . $t . '">';
}

function once_valid()
{
    $t = (string)opt($_POST, 'once', '');
    if ($t !== '' && isset($_SESSION['once'][$t])) {
        unset($_SESSION['once'][$t]);
        return true;
    }
    return false;
}

/** Catégories par défaut, créées à la première visite du module Budget. */
function ensure_categories($userId)
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM categories WHERE user_id = ?');
    $stmt->execute([$userId]);
    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }
    $exp = ['Alimentation', 'Transport', 'Logement', 'Loisirs', 'Études', 'Santé', 'Téléphone et internet', 'Autres dépenses'];
    $inc = ['Salaire', 'Vente', 'Cadeau', 'Aide', 'Revenu imprévu', 'Autres revenus'];
    $ins = db()->prepare('INSERT INTO categories (user_id, name, kind) VALUES (?, ?, ?)');
    foreach ($exp as $n) {
        $ins->execute([$userId, $n, 'expense']);
    }
    foreach ($inc as $n) {
        $ins->execute([$userId, $n, 'income']);
    }
}

/** Solde d'un compte = solde initial + revenus + transferts entrants − dépenses − transferts sortants. */
function account_balances($userId)
{
    $stmt = db()->prepare(
        "SELECT a.id, a.name, a.type, a.initial_balance, a.archived,
                a.initial_balance + COALESCE(SUM(CASE WHEN t.kind IN ('income','transfer_in') THEN t.amount
                                                      WHEN t.kind IN ('expense','transfer_out') THEN -t.amount ELSE 0 END), 0) AS balance
           FROM accounts a LEFT JOIN transactions t ON t.account_id = a.id
          WHERE a.user_id = ?
          GROUP BY a.id, a.name, a.type, a.initial_balance, a.archived
          ORDER BY a.archived, a.id"
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as $k => $r) {
        $rows[$k]['balance'] = (int)$r['balance'];
    }
    return $rows;
}

function account_balance($userId, $accountId)
{
    foreach (account_balances($userId) as $a) {
        if ((int)$a['id'] === (int)$accountId) {
            return (int)$a['balance'];
        }
    }
    return null;
}

/** Montant épargné par objectif : dépôts (transfer_out) − retraits (transfer_in). */
function goal_saved($userId, $goalId)
{
    $stmt = db()->prepare(
        "SELECT COALESCE(SUM(CASE WHEN kind = 'transfer_out' THEN amount WHEN kind = 'transfer_in' THEN -amount ELSE 0 END), 0)
           FROM transactions WHERE user_id = ? AND goal_id = ?"
    );
    $stmt->execute([$userId, $goalId]);
    return (int)$stmt->fetchColumn();
}

function month_bounds($ymd)
{
    $first = substr($ymd, 0, 7) . '-01';
    $d = DateTime::createFromFormat('Y-m-d', $first);
    $d->modify('+1 month');
    return [$first, $d->format('Y-m-d')];
}

function owned_account($userId, $id)
{
    $stmt = db()->prepare('SELECT * FROM accounts WHERE id = ? AND user_id = ? AND archived = 0');
    $stmt->execute([(int)$id, $userId]);
    return $stmt->fetch();
}

function owned_category($userId, $id, $kind)
{
    $stmt = db()->prepare('SELECT * FROM categories WHERE id = ? AND user_id = ? AND kind = ?');
    $stmt->execute([(int)$id, $userId, $kind]);
    return $stmt->fetch();
}

/** Ligne d'écriture (utilisée par le budget et l'historique). */
function tx_row(array $t, $withDelete = false)
{
    $in = in_array($t['kind'], ['income', 'transfer_in'], true);
    $isTransfer = in_array($t['kind'], ['transfer_in', 'transfer_out'], true);
    $title = $t['label'] !== null && $t['label'] !== '' ? $t['label'] : ($t['cat_name'] ? $t['cat_name'] : ($isTransfer ? 'Transfert' : ($in ? 'Entrée' : 'Dépense')));
    $sub = fr_date($t['occurred_on']) . ' · ' . $t['account_name'] . ($t['cat_name'] && $t['label'] ? ' · ' . $t['cat_name'] : '') . ($isTransfer ? ' · transfert' : '');
    $h = '<div class="task"><div class="task-main"><strong>' . e($title) . '</strong><span class="sub capitalize">' . e($sub) . '</span></div>'
        . '<span class="amt ' . ($isTransfer ? 'amt-neutral' : ($in ? 'amt-in' : 'amt-out')) . '">' . ($in ? '+' : '−') . e(fcfa($t['amount'])) . '</span>';
    if ($withDelete) {
        $h .= '<form method="post" class="inline" data-confirm="Supprimer cette écriture ?">' . csrf_field()
            . '<input type="hidden" name="action" value="tx_delete"><input type="hidden" name="id" value="' . (int)$t['id'] . '">'
            . '<button class="icon-btn" type="submit" aria-label="Supprimer">🗑</button></form>';
    }
    return $h . '</div>';
}

function account_types()
{
    return ['cash' => 'Espèces', 'wave' => 'Wave', 'mobile_money' => 'Mobile Money', 'bank' => 'Banque', 'other' => 'Autre'];
}

/** Comptes actifs d'un utilisateur avec leur solde. */
function active_accounts($userId)
{
    return array_values(array_filter(account_balances($userId), function ($a) { return !$a['archived']; }));
}

/** Choix d'un compte : masqué s'il n'y en a qu'un, sinon pastilles à cocher. */
function account_picker(array $accounts, $name = 'account_id', $selected = 0)
{
    if (count($accounts) === 1) {
        return '<input type="hidden" name="' . e($name) . '" value="' . (int)$accounts[0]['id'] . '">';
    }
    $h = '<div class="field"><span class="lbl">Sur quel compte ?</span><div class="chips">';
    foreach ($accounts as $i => $a) {
        $checked = ($selected ? (int)$selected === (int)$a['id'] : $i === 0) ? ' checked' : '';
        $h .= '<label class="chip"><input type="radio" name="' . e($name) . '" value="' . (int)$a['id'] . '"' . $checked . '><span>'
            . e($a['name']) . '<small>' . e(fcfa($a['balance'])) . '</small></span></label>';
    }
    return $h . '</div></div>';
}

/** Champ de montant géant (style appli de paiement). */
function amount_input($value = '', $error = '', $id = 'amount')
{
    return '<div class="amount-box"><input class="amount-input" id="' . e($id) . '" name="amount" type="text" inputmode="numeric" autocomplete="off" '
        . 'placeholder="0" value="' . e($value) . '" required aria-label="Montant en francs CFA"' . ($id === 'amount' ? ' autofocus' : '') . '><span class="amount-unit">F</span></div>'
        . ($error !== '' ? '<p class="field-error center" role="alert">' . e($error) . '</p>' : '');
}

/**
 * Lit un SMS de confirmation (Wave, Orange Money, MTN, Moov…) et en tire un montant, un sens et un nom.
 * Les formats varient : le résultat est toujours proposé à l'utilisateur pour validation, jamais enregistré seul.
 * Retourne ['amount' => int|null, 'kind' => 'income'|'expense'|null, 'label' => string, 'provider' => string].
 */
function parse_sms($text, $max = 600)
{
    $text = mb_substr((string)$text, 0, (int)$max);
    $plain = mb_strtolower($text);
    $plain = strtr($plain, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'ç' => 'c', 'î' => 'i', 'ô' => 'o', 'û' => 'u', 'ù' => 'u', "\xC2\xA0" => ' ', "\xE2\x80\xAF" => ' ']);
    $out = ['amount' => null, 'kind' => null, 'label' => '', 'provider' => ''];

    foreach (['wave' => 'wave', 'orange' => 'orange', 'mtn' => 'mtn', 'moov' => 'moov'] as $needle => $prov) {
        if (strpos($plain, $needle) !== false) {
            $out['provider'] = $prov;
            break;
        }
    }

    // Montant : premier nombre suivi de F / FCFA / CFA / XOF dans une phrase qui ne parle ni de solde ni de frais.
    $parts = preg_split('/(?<=[.!?\n])\s+/', $plain);
    foreach ($parts as $part) {
        if (preg_match('/solde|frais|commission|balance/', $part)) {
            continue;
        }
        if (preg_match('/(\d{1,3}(?:[ .,]\d{3})+|\d+)\s*(?:f\b|fcfa|cfa|xof|francs?)/', $part, $m)) {
            $n = (int)preg_replace('/\D/', '', $m[1]);
            if ($n > 0) {
                $out['amount'] = $n;
                break;
            }
        }
    }

    // Sens de l'opération.
    if (preg_match('/\b(recu|recus|depot|credit|credite|reception)\b/', $plain)) {
        $out['kind'] = 'income';
    } elseif (preg_match('/\b(envoye|envoi|paiement|paye|payes|retrait|achat|debit|debite|transfert de)\b/', $plain)) {
        $out['kind'] = 'expense';
    }

    // Nom ou numéro de l'autre personne (sur le texte d'origine pour garder les majuscules).
    if (preg_match('/\b(?:de|a|à|vers)\s+([A-ZÀ-Ý][A-Za-zÀ-ÿ\' -]{1,38})/u', $text, $m)) {
        $lab = trim(preg_replace('/\s+/', ' ', $m[1]));
        $lab = preg_replace('/\s+(reussi|reussie|réussi|réussie|effectue|effectué|avec|le|la|du)\b.*$/iu', '', $lab);
        $out['label'] = trim($lab);
    } elseif (preg_match('/(\+?\d[\d ]{7,14}\d)/', $text, $m)) {
        $out['label'] = trim($m[1]);
    }
    return $out;
}
