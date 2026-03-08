<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$type = isset($_GET['type']) ? $_GET['type'] : '';
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$page = max(1, isset($_GET['page']) ? (int) $_GET['page'] : 1);
$per_page = 50;
$offset = ($page - 1) * $per_page;

$where = ['YEAR(date_don) = ?'];
$params = [$year];

if ($type && in_array($type, ['don', 'adhesion', 'combo'], true)) {
    $where[] = 'type = ?';
    $params[] = $type;
}

if ($search !== '') {
    $where[] = "(CONCAT(nom, ' ', prenom) LIKE ? COLLATE utf8mb4_unicode_ci OR email LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$where_sql = implode(' AND ', $where);

// Count total
$count_sql = "SELECT COUNT(*) FROM donations WHERE {$where_sql}";
$stmt = get_db()->prepare($count_sql);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();

// Fetch rows
$sql = "SELECT id, email, prenom, nom, amount, date_don, type, mode_paiement, source, receipt_number, annual_receipt_number
        FROM donations
        WHERE {$where_sql}
        ORDER BY date_don DESC, id DESC
        LIMIT ? OFFSET ?";
$stmt = get_db()->prepare($sql);
$all_params = array_merge($params, [$per_page, $offset]);
foreach ($all_params as $i => $v) {
    $stmt->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->execute();
$rows = $stmt->fetchAll();

// Stats for the year
$stats_sql = "SELECT COUNT(*) as nb, COALESCE(SUM(amount), 0) as total FROM donations WHERE YEAR(date_don) = ?";
$stmt = get_db()->prepare($stats_sql);
$stmt->execute([$year]);
$stats = $stmt->fetch();

echo json_encode([
    'donations' => $rows,
    'total' => $total,
    'page' => $page,
    'per_page' => $per_page,
    'pages' => max(1, (int) ceil($total / $per_page)),
    'stats' => [
        'nb' => (int) $stats['nb'],
        'total' => (float) $stats['total'],
    ],
]);
