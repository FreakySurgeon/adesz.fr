<?php
require_once __DIR__ . '/config.php';

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        global $db_host, $db_name, $db_user, $db_pass;
        $pdo = new PDO(
            "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
            $db_user,
            $db_pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
    return $pdo;
}

function insert_donation(array $data): int {
    $columns = [
        'email', 'prenom', 'nom', 'adresse', 'cp', 'commune',
        'amount', 'date_don', 'type', 'mode_paiement', 'source',
        'stripe_payment_id', 'receipt_number',
    ];

    $fields = [];
    $placeholders = [];
    $values = [];

    foreach ($columns as $col) {
        if (array_key_exists($col, $data)) {
            $fields[] = $col;
            $placeholders[] = '?';
            $values[] = $data[$col];
        }
    }

    $sql = 'INSERT INTO donations (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = get_db()->prepare($sql);
    $stmt->execute($values);

    return (int) get_db()->lastInsertId();
}

function search_donors(string $query, int $limit = 10): array {
    $sql = "SELECT DISTINCT prenom, nom, email, adresse, cp, commune
            FROM donations
            WHERE CONCAT(nom, ' ', prenom) LIKE ? COLLATE utf8mb4_unicode_ci
            ORDER BY nom, prenom
            LIMIT ?";
    $stmt = get_db()->prepare($sql);
    $stmt->bindValue(1, '%' . $query . '%');
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function get_annual_donations(int $year): array {
    $sql = "SELECT id, email, prenom, nom, adresse, cp, commune, amount, date_don, type, mode_paiement, receipt_number, annual_receipt_number
            FROM donations
            WHERE YEAR(date_don) = ?
            ORDER BY nom, prenom, date_don";
    $stmt = get_db()->prepare($sql);
    $stmt->execute([$year]);
    $rows = $stmt->fetchAll();

    $groups = [];
    foreach ($rows as $row) {
        $key = $row['email'] ?: ($row['nom'] . '|' . $row['prenom'] . '|' . $row['cp']);

        if (!isset($groups[$key])) {
            $groups[$key] = [
                'email' => $row['email'],
                'prenom' => $row['prenom'],
                'nom' => $row['nom'],
                'adresse' => $row['adresse'],
                'cp' => $row['cp'],
                'commune' => $row['commune'],
                'total' => 0,
                'donations' => [],
            ];
        }

        $groups[$key]['total'] += (float) $row['amount'];
        $groups[$key]['donations'][] = [
            'id' => (int) $row['id'],
            'amount' => $row['amount'],
            'date_don' => $row['date_don'],
            'type' => $row['type'],
            'mode_paiement' => $row['mode_paiement'],
            'receipt_number' => $row['receipt_number'],
            'annual_receipt_number' => $row['annual_receipt_number'],
        ];
    }

    return array_values($groups);
}

function set_annual_receipt_number(array $donation_ids, string $receipt_number): void {
    if (empty($donation_ids)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($donation_ids), '?'));
    $sql = "UPDATE donations SET annual_receipt_number = ? WHERE id IN ({$placeholders})";
    $stmt = get_db()->prepare($sql);
    $stmt->execute(array_merge([$receipt_number], $donation_ids));
}
