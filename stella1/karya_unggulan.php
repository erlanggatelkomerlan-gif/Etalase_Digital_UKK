<?php
/** Menandai / membatalkan karya unggulan (toggle). Untuk guru, admin & super admin. */
require __DIR__ . '/includes/config.php';
$me = require_login();
if (!is_mod($me)) forbidden();
csrf_verify();

$id = (int) ($_POST['id'] ?? 0);
// 1 - nilai: 0 jadi 1, 1 jadi 0
db()->prepare('UPDATE karya SET unggulan = 1 - unggulan WHERE id = ?')->execute([$id]);
flash('ok', 'Status unggulan diperbarui.');
redirect($_SERVER['HTTP_REFERER'] ?? 'admin.php');
