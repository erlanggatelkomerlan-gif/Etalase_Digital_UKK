# Etalase Stella (versi MySQL)

Website etalase karya siswa — PHP + MySQL/MariaDB. Tema merah · abu · putih.

## Cara pakai (XAMPP)
1. Salin folder ini ke `htdocs` dan beri nama `stella` (jadi `htdocs/stella`).
2. Di XAMPP Control Panel, **Start Apache** dan **Start MySQL**.
3. Siapkan database, pilih salah satu:
   - **Impor manual (disarankan):** buka `http://localhost/phpmyadmin` > tab **Impor** > pilih `database/stella.sql` > **Kirim**.
   - **Otomatis:** lewati langkah ini; database `stella` dan tabelnya dibuat sendiri saat situs pertama dibuka.
4. Buka `http://localhost/stella/`.
5. Folder `uploads/` harus bisa ditulis oleh web server. Jika MySQL Anda memakai password/port lain, ubah `DB_*` di `includes/config.php`.

## Akun awal (GANTI PASSWORD SEGERA di Profil > Edit Profil)
Akun dibuat otomatis saat situs pertama dibuka (file SQL hanya berisi skema).
| Peran       | Username    | Password  |
|-------------|-------------|-----------|
| Super Admin | superadmin  | admin123  |
| Admin       | admin       | admin123  |
| Guru        | guru        | guru123   |

Siswa mendaftar sendiri lewat halaman **Daftar**. Akun guru tambahan dibuat Super Admin di menu **Pengguna**.

## Fitur karya
- **Pop-up karya**: klik kartu karya di beranda/profil → pop-up muncul dengan **gambar di atas, deskripsi di bawah**, dan latar belakang **blur**. Tutup dengan tombol ✕, klik area blur, atau tombol `Esc`.
- **Link wajib**: setiap karya baru harus menyertakan **link GitHub atau URL website**. Hanya `http://` / `https://` yang diterima. Link tampil sebagai tombol "Lihat di GitHub" / "Kunjungi Website" di pop-up.
- Database lama otomatis ditambah kolom `link` (karya lama tanpa link tetap tampil; link wajib diisi saat diedit).

## Hak akses
- **Guru**: semua hak siswa + menandai/membatalkan karya unggulan (tombol di halaman detail karya).
- **Siswa**: tambah/edit/hapus karya sendiri, suka, ubah profil.
- **Admin**: semua hak siswa + moderasi karya (hapus, jadikan unggulan) + kelola akun siswa.
- **Super Admin**: semua hak Admin + kelola semua pengguna & peran + Pengaturan Situs (logo, nama, tagline).

## Logo website
Login sebagai Super Admin > **Pengaturan Situs** > unggah logo (PNG transparan/persegi, 512x512 disarankan).
Logo tampil di menu samping, halaman login/daftar, dan ikon tab browser.

## Struktur
- `includes/config.php` — database, autentikasi, fungsi bantu, layout
- `assets/` — CSS & JS (pop-up karya: `assets/js/app.js` bagian 7 dan `style.css` bagian *POP-UP KARYA*)
- `database/stella.sql` — skema MySQL untuk diimpor lewat phpMyAdmin
- `uploads/` — gambar karya, avatar, logo (eksekusi PHP diblokir)
