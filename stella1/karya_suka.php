<?php
/**
 * Endpoint suka / batal suka sebuah karya (toggle).
 * Dipanggil lewat fetch() dari app.js (balas JSON) atau form biasa (redirect).
 */
require __DIR__ . '/includes/config.php';
$me = require_login();
csrf_verify();

// Pastikan karya yang dimaksud benar-benar ada
$id = (int) ($_POST['id'] ?? 0);
$ada = db()->prepare('SELECT 1 FROM karya WHERE id = ?');
$ada->execute([$id]);
if (!$ada->fetch()) { http_response_code(404); exit; }

// Sudah disukai -> batalkan; belum -> tambahkan suka
$cek = db()->prepare('SELECT 1 FROM suka WHERE user_id = ? AND karya_id = ?');
$cek->execute([$me['id'], $id]);
if ($cek->fetch()) {
    db()->prepare('DELETE FROM suka WHERE user_id = ? AND karya_id = ?')->execute([$me['id'], $id]);
    $on = false;
} else {
    db()->prepare('INSERT INTO suka (user_id, karya_id) VALUES (?, ?)')->execute([$me['id'], $id]);
    $on = true;
}

// Hitung ulang total suka karya ini
$n = db()->prepare('SELECT COUNT(*) FROM suka WHERE karya_id = ?');
$n->execute([$id]);

// Permintaan dari JavaScript: balas JSON {on, count}
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
    header('Content-Type: application/json');
    echo json_encode(['on' => $on, 'count' => (int) $n->fetchColumn()]);
    exit;
}
// Permintaan form biasa: kembali ke halaman asal
redirect($_SERVER['HTTP_REFERER'] ?? 'dashboard.php');
