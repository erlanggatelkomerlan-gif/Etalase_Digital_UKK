-- ==========================================================
-- Etalase Stella — skema MySQL / MariaDB (XAMPP)
-- Cara impor: phpMyAdmin > tab "Impor" > pilih file ini > Kirim.
-- (Opsional: situs juga membuat database & tabel otomatis saat pertama dibuka.)
-- Akun awal dibuat otomatis oleh situs saat pertama dibuka:
--   superadmin / admin123, admin / admin123, guru / guru123
-- ==========================================================
SET NAMES utf8mb4;
CREATE DATABASE IF NOT EXISTS `stella1` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `stella1`;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `nama` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `role` VARCHAR(20) NOT NULL DEFAULT 'siswa',   -- super_admin | admin | guru | siswa
  `kelas` VARCHAR(50) NOT NULL DEFAULT '',
  `bio` TEXT NULL,
  `avatar` VARCHAR(100) NOT NULL DEFAULT '',
  `aktif` TINYINT(1) NOT NULL DEFAULT 1,
  `dibuat` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `karya` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `judul` VARCHAR(200) NOT NULL,
  `deskripsi` TEXT NULL,
  `kategori` VARCHAR(50) NOT NULL DEFAULT 'Lainnya',
  `gambar` VARCHAR(100) NOT NULL,
  `link` VARCHAR(500) NOT NULL DEFAULT '',        -- link GitHub / website karya
  `unggulan` TINYINT(1) NOT NULL DEFAULT 0,
  `dilihat` INT UNSIGNED NOT NULL DEFAULT 0,
  `dibuat` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_karya_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `suka` (
  `user_id` INT UNSIGNED NOT NULL,
  `karya_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`, `karya_id`),
  CONSTRAINT `fk_suka_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_suka_karya` FOREIGN KEY (`karya_id`) REFERENCES `karya`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `k` VARCHAR(50) NOT NULL PRIMARY KEY,
  `v` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`k`, `v`) VALUES
  ('nama_situs', 'Etalase Stella'),
  ('tagline', 'Ruang pamer karya siswa'),
  ('logo', '');
