<?php
/**
 * Etalase Stella - konfigurasi, database (MySQL), dan fungsi bantu.
 *
 * File ini di-require oleh SEMUA halaman. Isinya:
 *   1. Sesi & konstanta
 *   2. Database (koneksi + pembuatan tabel otomatis)
 *   3. Pengaturan situs
 *   4. Fungsi umum (escape, redirect, flash, CSRF, tanggal)
 *   5. Autentikasi & hak akses
 *   6. Upload gambar & validasi link karya
 *   7. Komponen tampilan (ikon, avatar, kartu karya, modal pop-up, layout)
 */

/* ---------- Sesi & konstanta ---------- */
// Mulai sesi PHP (dipakai untuk login, pesan flash, dan token CSRF).
// httponly = cookie tak bisa dibaca JavaScript, samesite = ikut mencegah CSRF.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
// Semua tanggal/jam memakai zona waktu WIB.
date_default_timezone_set('Asia/Jakarta');

// Lokasi folder penting. BASE_DIR = folder induk dari includes/.
define('BASE_DIR', dirname(__DIR__));
define('UPLOAD_DIR', BASE_DIR . '/uploads');          // tempat gambar karya, avatar, logo
// Pengaturan MySQL (bawaan XAMPP: user root tanpa password). Ubah jika berbeda.
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'stella');   // dibuat otomatis jika belum ada
define('DB_USER', 'root');
define('DB_PASS', '');
define('MAX_UPLOAD', 5 * 1024 * 1024);                // batas ukuran upload: 5 MB
define('MAX_LINK', 300);                              // batas panjang link karya (karakter)

// Daftar kategori karya yang boleh dipilih di form.
const KATEGORI = ['Seni Rupa', 'Fotografi', 'Desain Grafis', 'Tulisan', 'Teknologi', 'Kerajinan', 'Lainnya'];
// Nama tampil untuk tiap peran pengguna.
const ROLE_LABEL = ['super_admin' => 'Super Admin', 'admin' => 'Admin', 'guru' => 'Guru', 'siswa' => 'Siswa'];

/* ---------- Database ---------- */
/**
 * Mengembalikan koneksi PDO (MySQL). Dibuat sekali saja (singleton),
 * lalu tabel dipastikan ada lewat migrate().
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        // EMULATE_PREPARES aktif agar placeholder bernama boleh dipakai berulang (mis. :q pada pencarian)
        $opt = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
        ];
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn . ';dbname=' . DB_NAME, DB_USER, DB_PASS, $opt);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1049) {
                // Database belum ada: buat otomatis lalu sambungkan lagi
                try {
                    $tmp = new PDO($dsn, DB_USER, DB_PASS, $opt);
                    $tmp->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                    $pdo = new PDO($dsn . ';dbname=' . DB_NAME, DB_USER, DB_PASS, $opt);
                } catch (PDOException $e2) {
                    $e = $e2;
                }
            }
            if ($pdo === null) {
                http_response_code(500);
                exit('Tidak dapat terhubung ke MySQL. Pastikan MySQL di XAMPP sudah Start, lalu cek pengaturan DB_* di includes/config.php. (' . htmlspecialchars($e->getMessage()) . ')');
            }
        }
        $pdo->exec("SET time_zone = '+07:00'");  // WIB
        migrate($pdo);
    }
    return $pdo;
}

/**
 * Membuat tabel bila belum ada dan menambah kolom baru pada database lama.
 * Aman dijalankan berulang kali. Tidak perlu impor SQL manual.
 */
function migrate(PDO $pdo): void
{
    // Buat tabel hanya jika belum ada. Skema yang sama tersedia di database/stella.sql
    // bila Anda lebih suka mengimpor manual lewat phpMyAdmin.
    if (!$pdo->query("SHOW TABLES LIKE 'users'")->fetch()) {
        $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            nama VARCHAR(100) NOT NULL,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'siswa',
            kelas VARCHAR(50) NOT NULL DEFAULT '',
            bio TEXT NULL,
            avatar VARCHAR(100) NOT NULL DEFAULT '',
            aktif TINYINT(1) NOT NULL DEFAULT 1,
            dibuat DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) $opts");
        $pdo->exec("CREATE TABLE IF NOT EXISTS karya (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            judul VARCHAR(200) NOT NULL,
            deskripsi TEXT NULL,
            kategori VARCHAR(50) NOT NULL DEFAULT 'Lainnya',
            gambar VARCHAR(100) NOT NULL,
            link VARCHAR(500) NOT NULL DEFAULT '',
            unggulan TINYINT(1) NOT NULL DEFAULT 0,
            dilihat INT UNSIGNED NOT NULL DEFAULT 0,
            dibuat DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_karya_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) $opts");
        $pdo->exec("CREATE TABLE IF NOT EXISTS suka (
            user_id INT UNSIGNED NOT NULL,
            karya_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, karya_id),
            CONSTRAINT fk_suka_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_suka_karya FOREIGN KEY (karya_id) REFERENCES karya(id) ON DELETE CASCADE
        ) $opts");
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            k VARCHAR(50) NOT NULL PRIMARY KEY,
            v TEXT NULL
        ) $opts");
    }

    // Akun awal (hanya saat tabel pengguna masih kosong)
    if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        $st = $pdo->prepare('INSERT INTO users (username, nama, password, role, kelas) VALUES (?,?,?,?,?)');
        $st->execute(['superadmin', 'Super Admin', password_hash('admin123', PASSWORD_DEFAULT), 'super_admin', '']);
        $st->execute(['admin', 'Admin Etalase', password_hash('admin123', PASSWORD_DEFAULT), 'admin', '']);
        $st->execute(['guru', 'Guru Stella', password_hash('guru123', PASSWORD_DEFAULT), 'guru', '']);
        $s = $pdo->prepare('INSERT IGNORE INTO settings (k, v) VALUES (?, ?)');
        $s->execute(['nama_situs', 'Etalase Stella']);
        $s->execute(['tagline', 'Ruang pamer karya siswa']);
        $s->execute(['logo', '']);
    }
}

/* ---------- Pengaturan situs ---------- */
/** Membaca satu pengaturan situs (di-cache per request). Memakai $default bila kosong. */
function setting(string $k, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT k, v FROM settings') as $r) {
            $cache[$r['k']] = (string) $r['v'];
        }
    }
    return ($cache[$k] ?? '') !== '' ? $cache[$k] : $default;
}

/** Menyimpan / memperbarui satu pengaturan situs. */
function set_setting(string $k, string $v): void
{
    $st = db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    $st->execute([$k, $v]);
}

/* ---------- Umum ---------- */
/** Escape HTML agar aman ditampilkan (mencegah XSS). Pakai untuk SEMUA data dari pengguna. */
function e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Pindah halaman lalu hentikan eksekusi skrip. */
function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

/** Menyimpan pesan sekali-tampil (ok / err) yang muncul di halaman berikutnya. */
function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['t' => $type, 'm' => $msg];
}

/** Token CSRF per sesi; dipakai agar form hanya sah jika dikirim dari situs ini. */
function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Input hidden berisi token CSRF untuk ditaruh di setiap form POST. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf()) . '">';
}

/** Memeriksa permintaan POST + token CSRF. Gagal = berhenti dengan kode 419. */
function csrf_verify(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(csrf(), (string) ($_POST['_csrf'] ?? ''))) {
        http_response_code(419);
        exit('Sesi kedaluwarsa atau permintaan tidak valid. Silakan muat ulang halaman.');
    }
}

/** Mengubah tanggal database menjadi format Indonesia, mis. "9 Nov 2026". */
function tanggal_id(?string $dt): string
{
    if (!$dt) return '';
    $bln = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $t = strtotime($dt);
    return date('j', $t) . ' ' . $bln[(int) date('n', $t)] . ' ' . date('Y', $t);
}

/** Mengambil inisial (maks. 2 huruf) dari nama, dipakai untuk avatar tanpa foto. */
function inisial(string $nama): string
{
    $parts = preg_split('/\s+/', trim($nama)) ?: [];
    $s = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $s .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $s ?: '?';
}

/* ---------- Autentikasi ---------- */
/** Data pengguna yang sedang login (null jika belum login / akun nonaktif). */
function user(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $st = db()->prepare('SELECT * FROM users WHERE id = ? AND aktif = 1');
            $st->execute([$_SESSION['uid']]);
            $u = $st->fetch() ?: null;
            if (!$u) unset($_SESSION['uid']);
        }
    }
    return $u;
}

/** Wajib login; jika belum, arahkan ke halaman login. */
function require_login(): array
{
    $u = user();
    if (!$u) redirect('login.php');
    return $u;
}

/** True jika pengguna adalah admin atau super admin. */
function is_staff(?array $u): bool
{
    return $u && in_array($u['role'], ['admin', 'super_admin'], true);
}

/** True jika pengguna adalah super admin. */
function is_super(?array $u): bool
{
    return $u && $u['role'] === 'super_admin';
}

/** True jika pengguna adalah guru. */
function is_guru(?array $u): bool
{
    return $u && $u['role'] === 'guru';
}

/** True jika boleh menandai karya unggulan: guru, admin, atau super admin. */
function is_mod(?array $u): bool
{
    return is_staff($u) || is_guru($u);
}

/** Halaman khusus admin / super admin. */
function require_staff(): array
{
    $u = require_login();
    if (!is_staff($u)) forbidden();
    return $u;
}

/** Halaman khusus super admin. */
function require_super(): array
{
    $u = require_login();
    if (!is_super($u)) forbidden();
    return $u;
}

/** Menampilkan halaman 403 "Akses ditolak". */
function forbidden(): void
{
    http_response_code(403);
    layout_start('Akses Ditolak');
    echo '<div class="empty card"><h2>Akses ditolak</h2><p>Anda tidak memiliki izin untuk membuka halaman ini.</p><a class="btn btn-red" href="dashboard.php">Kembali ke Beranda</a></div>';
    layout_end();
    exit;
}

/* ---------- Upload gambar ---------- */
/**
 * Memvalidasi & menyimpan gambar upload ke uploads/<$sub>/.
 * Mengecek error upload, ukuran, dan tipe asli file (bukan sekadar ekstensi).
 * Mengembalikan nama file acak; melempar RuntimeException bila gagal.
 */
function simpan_gambar(array $f, string $sub): string
{
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException(match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Ukuran file melebihi batas server. Gunakan gambar yang lebih kecil.',
            UPLOAD_ERR_NO_FILE => 'Silakan pilih file gambar.',
            default => 'Upload gagal, coba lagi.',
        });
    }
    if ($f['size'] > MAX_UPLOAD) {
        throw new RuntimeException('Ukuran gambar maksimal 5 MB.');
    }
    $info = @getimagesize($f['tmp_name']);
    $map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($map[$info[2]])) {
        throw new RuntimeException('File harus berupa gambar JPG, PNG, WEBP, atau GIF.');
    }
    $dir = UPLOAD_DIR . '/' . $sub;
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = bin2hex(random_bytes(12)) . '.' . $map[$info[2]];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Gagal menyimpan file. Pastikan folder uploads dapat ditulis.');
    }
    return $name;
}

/** Menghapus file gambar lama. Nama file dicek polanya agar tidak bisa menghapus file lain. */
function hapus_gambar(string $sub, ?string $name): void
{
    if ($name && preg_match('/^[a-f0-9]+\.(jpg|png|webp|gif)$/', $name)) {
        @unlink(UPLOAD_DIR . '/' . $sub . '/' . $name);
    }
}

/** URL publik sebuah gambar di folder uploads. */
function img_url(string $sub, string $name): string
{
    return 'uploads/' . $sub . '/' . rawurlencode($name);
}

/* ---------- Link karya (GitHub / website) ---------- */
/**
 * Membersihkan & memvalidasi link karya.
 *  - Boleh tanpa "https://" (otomatis ditambahkan).
 *  - Hanya skema http/https yang diterima (menolak javascript:, data:, dll. demi keamanan).
 *  - Harus punya nama host yang valid dan panjang maksimal MAX_LINK.
 * Mengembalikan URL siap simpan, atau null bila tidak valid.
 */
function bersihkan_link(string $raw): ?string
{
    $url = trim($raw);
    if ($url === '' || mb_strlen($url) > MAX_LINK) return null;
    // Jika pengguna menulis "github.com/user/repo" tanpa skema, tambahkan https://
    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
        // Tolak skema aneh seperti "javascript:alert(1)" yang tidak memakai "//"
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) && !preg_match('#^[^/]+\.[^/]+#', $url)) return null;
        $url = 'https://' . $url;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) return null;
    $parts = parse_url($url);
    $skema = strtolower($parts['scheme'] ?? '');
    $host = $parts['host'] ?? '';
    if (!in_array($skema, ['http', 'https'], true) || !str_contains($host, '.')) return null;
    return $url;
}

/** Teks tombol link: "Lihat di GitHub" untuk github.com, selain itu "Kunjungi Website". */
function link_label(string $url): string
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', $host);
    return ($host === 'github.com' || str_ends_with($host, '.github.com')) ? 'Lihat di GitHub' : 'Kunjungi Website';
}

/* ---------- Komponen tampilan ---------- */
/** Mengeluarkan ikon SVG inline berdasarkan nama (ikuti warna teks lewat currentColor). */
function icon(string $name, int $size = 20): string
{
    static $p = [
        'home'     => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h5v-6h4v6h5V10"/>',
        'image'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21c.8-4 4-6 8-6s7.2 2 8 6"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 010 6.8M18.5 14.8c1.7.7 2.8 2.3 3 5.2"/>',
        'shield'   => '<path d="M12 3l8 3v6c0 4.5-3.3 8-8 9-4.7-1-8-4.5-8-9V6z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
        'heart'    => '<path d="M20.8 4.6a5.5 5.5 0 00-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 00-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 000-7.8z"/>',
        'eye'      => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>',
        'edit'     => '<path d="M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z"/>',
        'trash'    => '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"/>',
        'star'     => '<path d="M12 3l2.6 5.6 6.1.7-4.5 4.2 1.2 6L12 16.5 6.6 19.5l1.2-6L3.3 9.3l6.1-.7z"/>',
        'menu'     => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'upload'   => '<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
        'grid'     => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'key'      => '<circle cx="8" cy="15" r="4"/><path d="M10.8 12.2L20 3M16 7l3 3"/>',
        'check'    => '<path d="M5 12l5 5L20 7"/>',
        'x'        => '<path d="M6 6l12 12M18 6L6 18"/>',
        'link'     => '<path d="M10 13a5 5 0 007.5.5l3-3a5 5 0 00-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 00-7.5-.5l-3 3a5 5 0 007 7l1.7-1.7"/>',
    ];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

/** Logo situs (gambar bila sudah diunggah, ikon bintang bila belum). */
function logo_html(int $size = 42): string
{
    $logo = setting('logo');
    if ($logo !== '') {
        return '<span class="logo logo-img" style="width:' . $size . 'px;height:' . $size . 'px"><img src="' . e(img_url('logo', $logo)) . '" alt="Logo ' . e(setting('nama_situs', 'Etalase Stella')) . '"></span>';
    }
    return '<span class="logo" style="width:' . $size . 'px;height:' . $size . 'px">' . icon('star', (int) ($size * 0.52)) . '</span>';
}

/** Avatar bulat: foto profil bila ada, jika tidak tampil inisial nama. */
function avatar_html(array $u, int $size = 40, string $extra = ''): string
{
    $st = 'width:' . $size . 'px;height:' . $size . 'px;font-size:' . max(11, (int) ($size * 0.36)) . 'px';
    if (!empty($u['avatar'])) {
        return '<span class="avatar ' . $extra . '" style="' . $st . '"><img src="' . e(img_url('avatar', $u['avatar'])) . '" alt="' . e($u['nama']) . '"></span>';
    }
    return '<span class="avatar ' . $extra . '" style="' . $st . '">' . e(inisial($u['nama'])) . '</span>';
}

/** Lencana warna sesuai peran pengguna. */
function role_badge(string $role): string
{
    $cls = ['super_admin' => 'b-red', 'admin' => 'b-dark', 'guru' => 'b-dark', 'siswa' => 'b-gray'][$role] ?? 'b-gray';
    return '<span class="badge ' . $cls . '">' . e(ROLE_LABEL[$role] ?? $role) . '</span>';
}

/**
 * Query dasar untuk mengambil karya + data penulis + jumlah suka + status suka user saat ini.
 * Tambahkan WHERE / ORDER BY setelahnya dan isi parameter :me.
 */
function karya_select(): string
{
    return 'SELECT k.*, u.nama, u.avatar, u.kelas,
        (SELECT COUNT(*) FROM suka s WHERE s.karya_id = k.id) AS jml_suka,
        EXISTS(SELECT 1 FROM suka s WHERE s.karya_id = k.id AND s.user_id = :me) AS saya_suka
        FROM karya k JOIN users u ON u.id = k.user_id';
}

/**
 * Kartu karya di grid.
 * Seluruh data karya ditaruh di atribut data-* agar JavaScript (assets/js/app.js)
 * bisa menampilkannya di pop-up TANPA memuat halaman baru.
 * Tautan judul/gambar tetap mengarah ke karya_lihat.php sebagai cadangan
 * (misal JavaScript mati atau klik-tengah / Ctrl+klik untuk buka tab baru).
 */
function karya_card(array $k): string
{
    $me = user();
    $liked = !empty($k['saya_suka']);
    // Pemilik karya & staf boleh mengedit -> tombol Edit muncul di pop-up
    $bisaEdit = $me && ((int) $k['user_id'] === (int) $me['id'] || is_staff($me));
    $link = (string) ($k['link'] ?? '');
    $href = 'karya_lihat.php?id=' . (int) $k['id'];
    ob_start(); ?>
    <article class="karya-card"
        data-karya-id="<?= (int) $k['id'] ?>"
        data-judul="<?= e($k['judul']) ?>"
        data-deskripsi="<?= e($k['deskripsi']) ?>"
        data-kategori="<?= e($k['kategori']) ?>"
        data-gambar="<?= e(img_url('karya', $k['gambar'])) ?>"
        data-unggulan="<?= !empty($k['unggulan']) ? '1' : '0' ?>"
        data-dilihat="<?= (int) $k['dilihat'] ?>"
        data-tanggal="<?= e(tanggal_id($k['dibuat'])) ?>"
        data-penulis="<?= e($k['nama']) ?>"
        data-kelas="<?= e($k['kelas'] ?: 'Siswa') ?>"
        data-avatar="<?= !empty($k['avatar']) ? e(img_url('avatar', $k['avatar'])) : '' ?>"
        data-inisial="<?= e(inisial($k['nama'])) ?>"
        data-profil="profil.php?id=<?= (int) $k['user_id'] ?>"
        data-link="<?= e($link) ?>"
        data-link-label="<?= $link !== '' ? e(link_label($link)) : '' ?>"
        data-edit="<?= $bisaEdit ? 'karya_form.php?id=' . (int) $k['id'] : '' ?>"
        data-halaman="<?= e($href) ?>">
        <!-- Klik gambar -> buka pop-up (class js-karya-open ditangkap oleh app.js) -->
        <a class="karya-thumb js-karya-open" href="<?= e($href) ?>">
            <img src="<?= e(img_url('karya', $k['gambar'])) ?>" alt="<?= e($k['judul']) ?>" loading="lazy">
            <span class="badge b-glass"><?= e($k['kategori']) ?></span>
            <?php if (!empty($k['unggulan'])): ?><span class="badge b-red star-tag"><?= icon('star', 12) ?> Unggulan</span><?php endif; ?>
        </a>
        <div class="karya-body">
            <h3><a class="js-karya-open" href="<?= e($href) ?>"><?= e($k['judul']) ?></a></h3>
            <div class="karya-meta">
                <a class="karya-author" href="profil.php?id=<?= (int) $k['user_id'] ?>">
                    <?= avatar_html($k, 28) ?><span><?= e($k['nama']) ?></span>
                </a>
                <!-- Tombol suka (dikirim lewat fetch tanpa reload, lihat app.js) -->
                <form method="post" action="karya_suka.php" class="like-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                    <button type="submit" class="like-btn <?= $liked ? 'on' : '' ?>" aria-label="Suka" aria-pressed="<?= $liked ? 'true' : 'false' ?>">
                        <?= icon('heart', 16) ?><span class="like-count"><?= (int) $k['jml_suka'] ?></span>
                    </button>
                </form>
            </div>
        </div>
    </article>
    <?php
    return ob_get_clean();
}

/**
 * Kerangka pop-up karya (satu saja per halaman, diisi ulang oleh JavaScript saat kartu diklik).
 * Susunan: gambar di atas, lalu deskripsi + tombol di bawahnya.
 * Latar belakang di-blur lewat CSS (.modal-backdrop).
 */
function modal_karya(): string
{
    ob_start(); ?>
<div class="modal" id="karyaModal" hidden>
    <!-- Latar blur; klik area ini menutup pop-up -->
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="modalJudul" tabindex="-1">
        <button type="button" class="modal-close" data-modal-close aria-label="Tutup"><?= icon('x', 20) ?></button>

        <!-- BAGIAN ATAS: gambar karya -->
        <div class="modal-media"><img id="modalGambar" src="" alt=""></div>

        <!-- BAGIAN BAWAH: judul, penulis, deskripsi, tombol -->
        <div class="modal-body">
            <div class="tags">
                <span class="badge b-soft" id="modalKategori"></span>
                <span class="badge b-red" id="modalUnggulan" hidden><?= icon('star', 12) ?> Unggulan</span>
            </div>
            <h2 class="modal-title" id="modalJudul"></h2>

            <a class="author-box" id="modalPenulis" href="#">
                <span class="avatar" id="modalAvatar" style="width:44px;height:44px;font-size:15px"></span>
                <span><b id="modalNama"></b><small id="modalInfo"></small></span>
            </a>

            <h3 class="modal-label">Deskripsi</h3>
            <p class="modal-desc" id="modalDeskripsi"></p>

            <div class="modal-actions">
                <!-- Link GitHub / website karya (disembunyikan jika kosong) -->
                <a class="btn btn-red" id="modalLink" href="#" target="_blank" rel="noopener noreferrer" hidden>
                    <?= icon('link', 18) ?><span id="modalLinkLabel">Kunjungi</span>
                </a>
                <!-- Suka: id karya diisi JS saat pop-up dibuka -->
                <form method="post" action="karya_suka.php" class="like-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" id="modalSukaId" value="">
                    <button type="submit" class="like-btn like-lg" id="modalSuka" aria-pressed="false">
                        <?= icon('heart', 18) ?><span class="like-count">0</span> suka
                    </button>
                </form>
                <a class="btn btn-line" id="modalEdit" href="#" hidden><?= icon('edit', 16) ?> Edit</a>
                <span class="modal-views"><?= icon('eye', 18) ?> <span id="modalDilihat">0</span> dilihat</span>
            </div>
        </div>
    </div>
</div>
    <?php
    return ob_get_clean();
}

/** Tombol nomor halaman. $baseQuery = parameter filter yang dipertahankan. */
function pagination(int $total, int $per, int $page, string $baseQuery = ''): string
{
    $pages = (int) ceil($total / $per);
    if ($pages <= 1) return '';
    $h = '<nav class="pagination" aria-label="Halaman">';
    for ($i = 1; $i <= $pages; $i++) {
        $h .= '<a class="' . ($i === $page ? 'on' : '') . '" href="?' . e($baseQuery) . ($baseQuery ? '&' : '') . 'hal=' . $i . '">' . $i . '</a>';
    }
    return $h . '</nav>';
}

/* ---------- Layout ---------- */
/**
 * Membuka halaman utama (setelah login): <head>, sidebar menu, topbar pencarian, lalu <main>.
 * $title = judul tab, $active = kunci menu yang disorot di sidebar.
 * Wajib dipasangkan dengan layout_end().
 */
function layout_start(string $title, string $active = ''): void
{
    $u = user();
    $site = setting('nama_situs', 'Etalase Stella');
    // Menu untuk semua pengguna: [kunci, label, url, ikon]
    $nav = [
        ['beranda', 'Beranda', 'dashboard.php', 'home', null],
        ['karyasaya', 'Karya Saya', 'dashboard.php?saya=1', 'image', null],
        ['tambah', 'Tambah Karya', 'karya_form.php', 'plus', null],
        ['profil', 'Profil Saya', 'profil.php', 'user', null],
    ];
    // Menu tambahan khusus admin
    $staffNav = [
        ['admin', 'Panel Admin', 'admin.php', 'grid'],
        ['pengguna', 'Pengguna', 'pengguna.php', 'users'],
    ];
    // Menu tambahan khusus super admin
    $superNav = [['pengaturan', 'Pengaturan Situs', 'pengaturan.php', 'settings']];
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - <?= e($site) ?></title>
    <?php if (setting('logo') !== ''): ?><link rel="icon" href="<?= e(img_url('logo', setting('logo'))) ?>"><?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app">
    <div class="scrim" data-close-nav></div>
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="dashboard.php">
            <?= logo_html(44) ?>
            <span class="brand-text"><?= e($site) ?><small><?= e(setting('tagline', 'Ruang pamer karya siswa')) ?></small></span>
        </a>
        <nav class="side-nav" aria-label="Menu utama">
            <div class="side-label">Menu</div>
            <?php foreach ($nav as $n): ?>
                <a href="<?= e($n[2]) ?>" class="<?= $active === $n[0] ? 'on' : '' ?>"><?= icon($n[3]) ?><?= e($n[1]) ?></a>
            <?php endforeach; ?>
            <?php if (is_staff($u)): ?>
                <div class="side-label">Administrasi</div>
                <?php foreach ($staffNav as $n): ?>
                    <a href="<?= e($n[2]) ?>" class="<?= $active === $n[0] ? 'on' : '' ?>"><?= icon($n[3]) ?><?= e($n[1]) ?></a>
                <?php endforeach; ?>
                <?php if (is_super($u)): foreach ($superNav as $n): ?>
                    <a href="<?= e($n[2]) ?>" class="<?= $active === $n[0] ? 'on' : '' ?>"><?= icon($n[3]) ?><?= e($n[1]) ?></a>
                <?php endforeach; endif; ?>
            <?php endif; ?>
        </nav>
        <?php if ($u): ?>
        <div class="side-user">
            <a href="profil.php" class="side-user-info">
                <?= avatar_html($u, 42) ?>
                <span><b><?= e($u['nama']) ?></b><small><?= e(ROLE_LABEL[$u['role']] ?? '') ?></small></span>
            </a>
            <a href="logout.php" class="icon-btn" aria-label="Keluar" title="Keluar"><?= icon('logout', 18) ?></a>
        </div>
        <?php endif; ?>
    </aside>

    <div class="main">
        <header class="topbar">
            <button class="icon-btn menu-btn" type="button" data-open-nav aria-label="Buka menu"><?= icon('menu') ?></button>
            <form class="search" action="dashboard.php" method="get" role="search">
                <?= icon('search', 18) ?>
                <label class="sr" for="cari">Cari karya</label>
                <input id="cari" type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Cari karya, judul, atau nama siswa">
            </form>
            <a href="karya_form.php" class="btn btn-red btn-sm top-add"><?= icon('plus', 16) ?><span>Tambah Karya</span></a>
        </header>
        <main class="content">
            <?php foreach ($_SESSION['flash'] ?? [] as $f): ?>
                <div class="flash flash-<?= e($f['t']) ?>" role="status"><?= e($f['m']) ?><button type="button" class="flash-x" aria-label="Tutup"><?= icon('x', 16) ?></button></div>
            <?php endforeach; unset($_SESSION['flash']); ?>
<?php
}

/**
 * Menutup halaman: footer, pop-up karya (hanya bila sudah login), dan memuat app.js.
 */
function layout_end(): void
{
    ?>
        </main>
        <footer class="foot">&copy; <?= date('Y') ?> <?= e(setting('nama_situs', 'Etalase Stella')) ?> &middot; Karya siswa, kebanggaan bersama</footer>
    </div>
</div>
<?php if (user()) echo modal_karya(); ?>
<script src="assets/js/app.js"></script>
</body>
</html>
<?php
}

/** Membuka halaman login/daftar (layout dua kolom tanpa sidebar). Pasangkan dengan auth_end(). */
function auth_start(string $title): void
{
    $site = setting('nama_situs', 'Etalase Stella');
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - <?= e($site) ?></title>
    <?php if (setting('logo') !== ''): ?><link rel="icon" href="<?= e(img_url('logo', setting('logo'))) ?>"><?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">
<main class="auth">
    <section class="auth-visual">
        <div class="brand brand-light">
            <?= logo_html(48) ?>
            <span class="brand-text"><?= e($site) ?><small><?= e(setting('tagline', 'Ruang pamer karya siswa')) ?></small></span>
        </div>
        <div class="auth-hero">
            <h1>Tempat terbaik memamerkan karyamu.</h1>
            <p>Unggah karya, dapatkan apresiasi dari teman dan guru, dan jadilah bagian dari etalase kebanggaan sekolah.</p>
            <ul class="auth-points">
                <li><?= icon('check', 18) ?> Unggah karya kapan saja</li>
                <li><?= icon('check', 18) ?> Dapatkan suka dan apresiasi</li>
                <li><?= icon('check', 18) ?> Profil pribadi yang menarik</li>
            </ul>
        </div>
        <div class="auth-foot">&copy; <?= date('Y') ?> <?= e($site) ?></div>
    </section>
    <section class="auth-panel">
        <div class="auth-card">
            <?php foreach ($_SESSION['flash'] ?? [] as $f): ?>
                <div class="flash flash-<?= e($f['t']) ?>" role="status"><?= e($f['m']) ?></div>
            <?php endforeach; unset($_SESSION['flash']); ?>
<?php
}

/** Menutup halaman login/daftar dan memuat app.js. */
function auth_end(): void
{
    ?>
        </div>
    </section>
</main>
<script src="assets/js/app.js"></script>
</body>
</html>
<?php
}
