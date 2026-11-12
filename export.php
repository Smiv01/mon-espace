<?php
// Export de mes données personnelles (sauvegarde) : un fichier JSON lisible, réservé à son propriétaire.
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$userId = (int)$user['id'];

if (!is_post() || !csrf_valid()) {
    redirect('profil.php');
}

// Uniquement ce qui appartient à l'utilisateur (jamais les photos ni les positions des autres).
$tables = array(
    'comptes' => 'accounts',
    'categories' => 'categories',
    'transactions' => 'transactions',
    'budgets' => 'budgets',
    'objectifs_epargne' => 'savings_goals',
    'dettes' => 'debts',
    'remboursements' => 'debt_payments',
    'taches' => 'tasks',
    'evenements' => 'events',
    'notes' => 'notes',
    'frigos' => 'spaces',
);

$data = array(
    'application' => 'Mon Espace',
    'exporte_le' => gmdate('Y-m-d H:i:s') . ' UTC',
);
foreach ($tables as $label => $table) {
    $stmt = db()->prepare('SELECT * FROM ' . $table . ' WHERE user_id = ? ORDER BY id');
    $stmt->execute(array($userId));
    $data[$label] = $stmt->fetchAll();
}

// Produits des frigos dont on est propriétaire.
$stmt = db()->prepare('SELECT i.* FROM space_items i JOIN spaces s ON s.id = i.space_id WHERE s.user_id = ? ORDER BY i.id');
$stmt->execute(array($userId));
$data['produits_en_stock'] = $stmt->fetchAll();

$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="mon-espace-' . gmdate('Y-m-d') . '.json"');
header('Cache-Control: no-store');
echo $json;
