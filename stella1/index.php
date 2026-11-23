<?php
/** Pintu masuk situs: sudah login -> beranda, belum -> halaman login. */
require __DIR__ . '/includes/config.php';
redirect(user() ? 'dashboard.php' : 'login.php');
