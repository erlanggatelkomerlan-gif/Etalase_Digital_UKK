<?php
/**
 * Form tambah / edit karya.
 *  - Tanpa ?id  -> mode TAMBAH karya baru
 *  - Dengan ?id -> mode EDIT (hanya pemilik karya atau admin)
 * Setiap karya WAJIB punya link GitHub atau URL website (kolom `link`).
 */
require __DIR__ . '/includes/config.php';
$me = require_login();   // wajib login

// Ambil id karya (dari URL saat membuka form, atau dari input hidden saat submit)
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$karya = null;
if ($id) {
    // Mode edit: ambil data karya lama dan cek hak akses
    $st = db()->prepare('SELECT * FROM karya WHERE id = ?');
    $st->execute([$id]);
    $karya = $st->fetch();
    if (!$karya) { http_response_code(404); exit('Karya tidak ditemukan.'); }
    if ((int) $karya['user_id'] !== (int) $me['id'] && !is_staff($me)) forbidden();
}

$errors = [];
// Nilai awal form (data lama saat edit, atau kosong saat tambah)
$v = [
    'judul' => $karya['judul'] ?? '',
    'deskripsi' => $karya['deskripsi'] ?? '',
    'kategori' => $karya['kategori'] ?? KATEGORI[0],
    'link' => $karya['link'] ?? '',
];

// ---- Proses form saat disubmit ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();   // tolak jika token CSRF salah
    $v['judul'] = trim($_POST['judul'] ?? '');
    $v['deskripsi'] = trim($_POST['deskripsi'] ?? '');
    $v['kategori'] = $_POST['kategori'] ?? '';
    $v['link'] = trim($_POST['link'] ?? '');

    // Validasi isian
    if (mb_strlen($v['judul']) < 3 || mb_strlen($v['judul']) > 80) $errors[] = 'Judul harus 3-80 karakter.';
    if (mb_strlen($v['deskripsi']) > 1500) $errors[] = 'Deskripsi maksimal 1500 karakter.';
    if (!in_array($v['kategori'], KATEGORI, true)) $errors[] = 'Kategori tidak valid.';

    // Link GitHub / website WAJIB diisi dan harus berupa URL http(s) yang valid
    if ($v['link'] === '') {
        $errors[] = 'Link GitHub atau URL website karya wajib diisi.';
    } else {
        $linkBersih = bersihkan_link($v['link']);
        if ($linkBersih === null) {
            $errors[] = 'Link tidak valid. Gunakan alamat lengkap, contoh: https://github.com/username/proyek';
        } else {
            $v['link'] = $linkBersih;   // simpan versi yang sudah dibersihkan
        }
    }

    // Gambar: wajib saat tambah baru, opsional saat edit
    $gambar = $karya['gambar'] ?? null;
    $hasFile = !empty($_FILES['gambar']) && ($_FILES['gambar']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE;
    $baru = null;
    if (!$errors && ($hasFile || !$karya)) {
        try {
            $baru = simpan_gambar($_FILES['gambar'] ?? [], 'karya');
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }

    // Semua valid -> simpan ke database
    if (!$errors) {
        if ($karya) {
            // EDIT: perbarui data; hapus file gambar lama jika diganti
            $up = db()->prepare('UPDATE karya SET judul=?, deskripsi=?, kategori=?, link=?, gambar=? WHERE id=?');
            $up->execute([$v['judul'], $v['deskripsi'], $v['kategori'], $v['link'], $baru ?? $gambar, $karya['id']]);
            if ($baru) hapus_gambar('karya', $gambar);
            flash('ok', 'Karya berhasil diperbarui.');
            redirect('karya_lihat.php?id=' . $karya['id']);
        }
        // TAMBAH: karya baru milik user yang sedang login
        $in = db()->prepare('INSERT INTO karya (user_id, judul, deskripsi, kategori, link, gambar) VALUES (?,?,?,?,?,?)');
        $in->execute([$me['id'], $v['judul'], $v['deskripsi'], $v['kategori'], $v['link'], $baru]);
        flash('ok', 'Karya berhasil ditambahkan ke etalase!');
        redirect('karya_lihat.php?id=' . db()->lastInsertId());
    }
}

// ---- Tampilan form ----
layout_start($karya ? 'Edit Karya' : 'Tambah Karya', $karya ? '' : 'tambah');
?>
<div class="page-head">
    <div>
        <h1><?= $karya ? 'Edit Karya' : 'Tambah Karya Baru' ?></h1>
        <p class="muted">Pamerkan hasil karyamu agar bisa dinikmati semua orang.</p>
    </div>
</div>

<!-- Daftar pesan kesalahan validasi -->
<?php foreach ($errors as $er): ?><div class="flash flash-err" role="alert"><?= e($er) ?></div><?php endforeach; ?>

<form method="post" enctype="multipart/form-data" class="form-split">
    <?= csrf_field() ?>
    <?php if ($karya): ?><input type="hidden" name="id" value="<?= (int) $karya['id'] ?>"><?php endif; ?>

    <!-- Kolom kiri: unggah gambar + pratinjau (dikompres otomatis oleh app.js) -->
    <div class="card pad">
        <label class="drop" for="gambar" data-drop>
            <img class="drop-preview" data-preview alt="Pratinjau" <?= $karya ? 'src="' . e(img_url('karya', $karya['gambar'])) . '"' : 'hidden' ?>>
            <span class="drop-hint" data-hint <?= $karya ? 'hidden' : '' ?>>
                <?= icon('upload', 36) ?>
                <b>Klik atau seret gambar ke sini</b>
                <small>JPG, PNG, WEBP, GIF &middot; ukuran otomatis dikecilkan</small>
            </span>
        </label>
        <input class="sr" type="file" id="gambar" name="gambar" accept="image/jpeg,image/png,image/webp,image/gif" data-resize <?= $karya ? '' : 'required' ?>>
        <?php if ($karya): ?><p class="muted small center">Biarkan kosong jika tidak ingin mengganti gambar.</p><?php endif; ?>
    </div>

    <!-- Kolom kanan: judul, kategori, link, deskripsi -->
    <div class="card pad">
        <div class="field">
            <label for="judul">Judul Karya</label>
            <input class="input" type="text" id="judul" name="judul" maxlength="80" value="<?= e($v['judul']) ?>" placeholder="Beri judul yang menarik" required>
        </div>
        <div class="field">
            <label for="kategori">Kategori</label>
            <select class="input" id="kategori" name="kategori">
                <?php foreach (KATEGORI as $c): ?>
                    <option value="<?= e($c) ?>" <?= $v['kategori'] === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <!-- Link GitHub / website karya (WAJIB) - tampil sebagai tombol di pop-up -->
        <div class="field">
            <label for="link">Link GitHub / URL Website <span class="req">*</span></label>
            <input class="input" type="url" id="link" name="link" maxlength="<?= MAX_LINK ?>" value="<?= e($v['link']) ?>" placeholder="https://github.com/username/nama-proyek" required>
            <p class="muted small hint">Tautan ke repositori GitHub atau website karyamu. Wajib diawali <b>https://</b></p>
        </div>
        <div class="field">
            <label for="deskripsi">Deskripsi</label>
            <textarea class="input" id="deskripsi" name="deskripsi" rows="6" maxlength="1500" placeholder="Ceritakan tentang karya ini: ide, teknik, atau proses pembuatannya."><?= e($v['deskripsi']) ?></textarea>
        </div>
        <!-- Tombol batal & simpan -->
        <div class="actions">
            <a href="<?= $karya ? 'karya_lihat.php?id=' . (int) $karya['id'] : 'dashboard.php' ?>" class="btn btn-line">Batal</a>
            <button type="submit" class="btn btn-red"><?= $karya ? 'Simpan Perubahan' : 'Terbitkan Karya' ?></button>
        </div>
    </div>
</form>
<?php layout_end();
