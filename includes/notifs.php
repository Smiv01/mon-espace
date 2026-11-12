<?php
/**
 * Notifications calculées à la volée (aucune table) :
 * produits qui expirent, tâches et événements, enveloppes de budget, dettes à échéance.
 * Chaque élément : ['group', 'level' (late|warn|info), 'title', 'sub', 'link'].
 */
function notifications_for(array $user, $today)
{
    $userId = (int)$user['id'];
    $out = [];
    $d0 = strtotime($today . ' 00:00:00 UTC');
    $plus = function ($n) use ($today) {
        return date('Y-m-d', strtotime($today . ' 00:00:00 UTC') + $n * 86400);
    };

    // Frigos (espaces à moi ou partagés avec moi) : périmés ou qui expirent dans 3 jours
    require_once __DIR__ . '/stock.php';
    $ids = accessible_space_ids($userId);
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare(
            "SELECT i.name, i.quantity, i.expires_on, s.id AS sid, s.name AS sname FROM space_items i
               JOIN spaces s ON s.id = i.space_id
              WHERE i.space_id IN ($in) AND i.expires_on IS NOT NULL AND i.expires_on <= ?
              ORDER BY i.expires_on, i.name LIMIT 30"
        );
        $stmt->execute(array_merge($ids, [$plus(3)]));
        foreach ($stmt->fetchAll() as $r) {
            list($cls, $txt) = expiry_state($r['expires_on'], $today);
            $out[] = ['Frigos', $cls === 'late' ? 'late' : 'warn', $r['name'] . ' (' . (int)$r['quantity'] . ')',
                $txt . ' · ' . $r['sname'], 'espace.php?id=' . (int)$r['sid']];
        }
    }

    // Agenda : tâches en retard ou du jour
    $stmt = db()->prepare("SELECT title, due_date, due_time FROM tasks WHERE user_id = ? AND status = 'todo' AND due_date IS NOT NULL AND due_date <= ? ORDER BY due_date, due_time LIMIT 20");
    $stmt->execute([$userId, $today]);
    foreach ($stmt->fetchAll() as $t) {
        $late = $t['due_date'] < $today;
        $out[] = ['Agenda', $late ? 'late' : 'warn', $t['title'],
            $late ? 'En retard depuis le ' . fr_date($t['due_date']) : ($t['due_time'] ? "Aujourd'hui à " . substr($t['due_time'], 0, 5) : "Aujourd'hui"), 'agenda.php'];
    }
    // Agenda : événements d'aujourd'hui et de demain
    $stmt = db()->prepare('SELECT title, starts_at FROM events WHERE user_id = ? AND starts_at >= ? AND starts_at < ? ORDER BY starts_at LIMIT 20');
    $stmt->execute([$userId, $today . ' 00:00:00', $plus(2) . ' 00:00:00']);
    foreach ($stmt->fetchAll() as $ev) {
        $day = substr($ev['starts_at'], 0, 10);
        $out[] = ['Agenda', 'info', $ev['title'], ($day === $today ? "Aujourd'hui" : 'Demain') . ' à ' . substr($ev['starts_at'], 11, 5), 'agenda.php?vue=evenements'];
    }

    // Budget : enveloppes à 80 % ou dépassées ce mois-ci
    $m1 = substr($today, 0, 8) . '01';
    $m2 = date('Y-m-d', strtotime($m1 . ' +1 month'));
    $stmt = db()->prepare(
        "SELECT c.name, b.monthly_amount,
                (SELECT COALESCE(SUM(t.amount), 0) FROM transactions t
                  WHERE t.user_id = b.user_id AND t.category_id = b.category_id AND t.kind = 'expense'
                    AND t.occurred_on >= ? AND t.occurred_on < ?) AS spent
           FROM budgets b JOIN categories c ON c.id = b.category_id WHERE b.user_id = ?"
    );
    $stmt->execute([$m1, $m2, $userId]);
    foreach ($stmt->fetchAll() as $b) {
        $lim = (int)$b['monthly_amount'];
        $spent = (int)$b['spent'];
        if ($lim > 0 && $spent > $lim) {
            $out[] = ['Budget', 'late', 'Enveloppe « ' . $b['name'] . ' » dépassée', fcfa($spent) . ' dépensés sur ' . fcfa($lim), 'finances.php'];
        } elseif ($lim > 0 && $spent * 100 >= $lim * 80) {
            $out[] = ['Budget', 'warn', 'Enveloppe « ' . $b['name'] . ' » presque pleine', fcfa($spent) . ' dépensés sur ' . fcfa($lim), 'finances.php'];
        }
    }
    // Budget : dettes en retard ou à échéance dans 3 jours
    $stmt = db()->prepare("SELECT direction, person, amount, due_date FROM debts WHERE user_id = ? AND due_date IS NOT NULL AND due_date <= ? ORDER BY due_date LIMIT 20");
    $stmt->execute([$userId, $plus(3)]);
    foreach ($stmt->fetchAll() as $d) {
        $late = $d['due_date'] < $today;
        $who = $d['direction'] === 'i_owe' ? 'À rembourser à ' . $d['person'] : $d['person'] . ' te doit de l\'argent';
        $out[] = ['Budget', $late ? 'late' : 'warn', $who . ' · ' . fcfa($d['amount']),
            ($late ? 'Échéance dépassée : ' : 'Échéance : ') . fr_date($d['due_date']), 'dettes.php'];
    }
    return $out;
}
