<?php
// Frigos, congélateurs, placards : stock et liste de courses.

function space_kinds()
{
    return ['fridge' => 'Frigo', 'freezer' => 'Congélateur', 'pantry' => 'Placard', 'other' => 'Autre'];
}

/**
 * Un espace est accessible à son propriétaire et à ses membres (frigo partagé).
 * Retourne la ligne de l'espace + 'role' ('owner' ou 'member'), ou false.
 */
function space_access($userId, $id)
{
    $stmt = db()->prepare('SELECT * FROM spaces WHERE id = ?');
    $stmt->execute([(int)$id]);
    $sp = $stmt->fetch();
    if (!$sp) {
        return false;
    }
    if ((int)$sp['user_id'] === (int)$userId) {
        $sp['role'] = 'owner';
        return $sp;
    }
    $stmt = db()->prepare('SELECT 1 FROM space_members WHERE space_id = ? AND user_id = ?');
    $stmt->execute([(int)$id, (int)$userId]);
    if ($stmt->fetchColumn()) {
        $sp['role'] = 'member';
        return $sp;
    }
    return false;
}

/** Espaces visibles par l'utilisateur (les siens + ceux où il est membre). */
function accessible_space_ids($userId)
{
    $stmt = db()->prepare('SELECT id FROM spaces WHERE user_id = ? UNION SELECT space_id FROM space_members WHERE user_id = ?');
    $stmt->execute([(int)$userId, (int)$userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Quantité : entier de 1 à 9999 (vide = 1). Retourne null si invalide. */
function parse_qty($v)
{
    $v = trim((string)$v);
    if ($v === '') {
        return 1;
    }
    if (!preg_match('/^[0-9]{1,4}$/', $v) || (int)$v < 1) {
        return null;
    }
    return (int)$v;
}

/** État de péremption : [classe, texte] (ou ['', '']). */
function expiry_state($ymd, $today)
{
    if (!$ymd) {
        return ['', ''];
    }
    $days = (int)floor((strtotime($ymd . ' 00:00:00 UTC') - strtotime($today . ' 00:00:00 UTC')) / 86400);
    if ($days < 0) {
        return ['late', 'Périmé'];
    }
    if ($days === 0) {
        return ['late', "Expire aujourd'hui"];
    }
    if ($days <= 3) {
        return ['warn', 'Expire dans ' . $days . ($days > 1 ? ' jours' : ' jour')];
    }
    return ['muted', 'Jusqu\'au ' . fr_date($ymd, false)];
}

/** Ajoute au stock ; si le même produit (même date) existe déjà dans cet espace, on ajoute à la quantité. */
function stock_add($userId, $spaceId, $name, $qty, $unit, $expires)
{
    $stmt = db()->prepare('SELECT id FROM space_items WHERE space_id = ? AND LOWER(name) = LOWER(?) AND ((expires_on IS NULL AND ? IS NULL) OR expires_on = ?) LIMIT 1');
    $stmt->execute([$spaceId, $name, $expires, $expires]);
    $id = $stmt->fetchColumn();
    if ($id) {
        db()->prepare('UPDATE space_items SET quantity = quantity + ? WHERE id = ? AND space_id = ?')->execute([$qty, $id, $spaceId]);
        return;
    }
    db()->prepare('INSERT INTO space_items (user_id, space_id, name, quantity, unit, expires_on) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$userId, $spaceId, $name, $qty, $unit, $expires]);
}

function shopping_add($userId, $spaceId, $name, $qty)
{
    $stmt = db()->prepare('SELECT id FROM shopping_items WHERE space_id = ? AND LOWER(name) = LOWER(?) LIMIT 1');
    $stmt->execute([$spaceId, $name]);
    $id = $stmt->fetchColumn();
    if ($id) {
        db()->prepare('UPDATE shopping_items SET quantity = quantity + ? WHERE id = ? AND space_id = ?')->execute([$qty, $id, $spaceId]);
        return;
    }
    db()->prepare('INSERT INTO shopping_items (user_id, space_id, name, quantity) VALUES (?, ?, ?, ?)')->execute([$userId, $spaceId, $name, $qty]);
}

/**
 * Lit le texte d'un ticket de caisse (OCR) et en tire des produits probables : [['name' => , 'qty' => ], …].
 * Les tickets varient beaucoup : le résultat est toujours montré à l'utilisateur pour correction avant tout ajout.
 */
function parse_receipt($text)
{
    $skip = '/\b(total|sous[- ]?total|tva|ttc|ht|montant|esp[eè]ces?|carte|cb|rendu|monnaie|merci|ticket|caisse|caissier|facture|net\s*[aà]\s*payer|r[eè]glement|client|bienvenue|www|http|tel|t[eé]l|mobile|money|wave|orange|moov|mtn|remise|reduction|r[eé]duction|points?|fid[eé]lit[eé]|article[s]?|date|heure|n[°o]|siret|rccm|cc|ncc|adresse|abidjan|cocody|yopougon|marcory|treichville|koumassi|bingerville)\b/iu';
    $items = [];
    $lines = preg_split('/\R/u', (string)$text);
    foreach ($lines as $line) {
        $line = trim(preg_replace('/\s+/u', ' ', $line));
        if ($line === '' || mb_strlen($line) < 4 || preg_match($skip, $line)) {
            continue;
        }
        if (preg_match('#\d{1,2}[/.-]\d{1,2}[/.-]\d{2,4}#', $line) || preg_match('/\d{1,2}:\d{2}/', $line)) {
            continue;
        }
        // Quantité écrite « x3 » au milieu de la ligne.
        $qty = 1;
        if (preg_match('/\s[xX*]\s?(\d{1,2})(?=\s)/u', $line, $qx)) {
            $qty = (int)$qx[1];
            $line = trim(preg_replace('/\s[xX*]\s?\d{1,2}(?=\s)/u', '', $line, 1));
        }
        // Un produit : du texte puis un prix à la fin (au moins 2 chiffres).
        if (!preg_match('/^(.*?)[\s:=.]*([0-9][0-9 .,]*[0-9])\s*(?:f|fcfa|cfa)?\s*[A-Za-z]?$/iu', $line, $m)) {
            continue;
        }
        $label = trim($m[1]);
        if (preg_match('/^(\d{1,2})\s*[xX*]\s*(.+)$/u', $label, $q)) {
            $qty = (int)$q[1];
            $label = $q[2];
        } elseif (preg_match('/^(.*?)\s*[xX*]\s*(\d{1,2})$/u', $label, $q)) {
            $qty = (int)$q[2];
            $label = $q[1];
        } elseif (preg_match('/^(.*?)\s+(\d{1,2})\s*[xX*]\s*[0-9 .,]*$/u', $label, $q)) {
            $qty = (int)$q[2];
            $label = $q[1];
        }
        // Garde seulement les lettres, espaces, apostrophes et traits d'union.
        $name = trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\s\x27\-]/u', ' ', $label)));
        $name = trim(preg_replace('/\s+/u', ' ', preg_replace('/\b(kg|g|l|cl|ml|pc|pcs)\b/iu', ' ', $name)));
        if (mb_strlen($name) < 3 || !preg_match('/\p{L}{3}/u', $name)) {
            continue;
        }
        $name = mb_strtoupper(mb_substr($name, 0, 1)) . mb_strtolower(mb_substr($name, 1));
        $items[] = ['name' => mb_substr($name, 0, 100), 'qty' => max(1, min(99, $qty))];
        if (count($items) >= 40) {
            break;
        }
    }
    return $items;
}

/** Total du ticket (ligne « TOTAL » ou « NET À PAYER ») en francs entiers, ou null. */
function parse_receipt_total($text)
{
    $best = null;
    foreach (preg_split('/\R/u', (string)$text) as $line) {
        $line = trim($line);
        if (!preg_match('/(net\s*[aà]\s*payer|\btotal\b)/iu', $line) || preg_match('/(sous[- ]?total|\btva\b|\bht\b)/iu', $line)) {
            continue;
        }
        if (preg_match('/([0-9][0-9 .,]*[0-9])\s*(?:f|fcfa|cfa)?\s*$/iu', $line, $m)) {
            $n = preg_replace('/\D/', '', $m[1]);
            if ($n !== '' && strlen($n) <= 9 && (int)$n > 0 && ($best === null || (int)$n > $best)) {
                $best = (int)$n;
            }
        }
    }
    return $best;
}

/**
 * Retire de la liste de courses ce qui vient d'être acheté (même nom, ou nom qui contient l'autre).
 * Diminue la quantité demandée ; supprime la ligne quand elle est satisfaite. Retourne les noms touchés.
 */
function fold_text($s)
{
    $s = mb_strtolower(trim((string)$s), 'UTF-8');
    return strtr($s, [
        'à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i',
        'ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','œ'=>'oe','æ'=>'ae',
    ]);
}

function shopping_resolve($spaceId, $name, $qty)
{
    $name = fold_text($name);
    if (mb_strlen($name) < 3) {
        return [];
    }
    $stmt = db()->prepare('SELECT id, name, quantity FROM shopping_items WHERE space_id = ? ORDER BY id');
    $stmt->execute([$spaceId]);
    $touched = [];
    foreach ($stmt->fetchAll() as $row) {
        $n = fold_text($row['name']);
        if ($n === $name || (mb_strlen($n) >= 3 && (mb_strpos($name, $n) !== false || mb_strpos($n, $name) !== false))) {
            $left = (int)$row['quantity'] - (int)$qty;
            if ($left > 0) {
                db()->prepare('UPDATE shopping_items SET quantity = ? WHERE id = ? AND space_id = ?')->execute([$left, $row['id'], $spaceId]);
            } else {
                db()->prepare('DELETE FROM shopping_items WHERE id = ? AND space_id = ?')->execute([$row['id'], $spaceId]);
            }
            $touched[] = $row['name'];
            break;
        }
    }
    return $touched;
}
