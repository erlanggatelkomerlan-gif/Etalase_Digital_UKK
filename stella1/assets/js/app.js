/**
 * Etalase Stella - JavaScript antarmuka.
 * Dimuat di akhir setiap halaman (lihat layout_end() / auth_end() di includes/config.php).
 *
 * Daftar fitur:
 *   1. Menu samping (mobile)
 *   2. Pesan flash (tutup manual / hilang otomatis)
 *   3. Tampilkan / sembunyikan password
 *   4. Konfirmasi sebelum submit (hapus, reset, dll.)
 *   5. Select yang langsung submit saat diganti
 *   6. Tombol suka tanpa reload (sinkron antara kartu & pop-up)
 *   7. Pop-up detail karya dengan latar blur
 *   8. Pratinjau + kompres gambar sebelum upload
 *   9. Seret & lepas file ke area upload
 */
(function () {
    'use strict';
    var body = document.body;

    /* ------------------------------------------------------------
     * 1. MENU SAMPING (mobile)
     * Tombol [data-open-nav] membuka sidebar, [data-close-nav] (area gelap) menutupnya.
     * CSS memakai class "nav-open" di <body> untuk menggeser sidebar.
     * ------------------------------------------------------------ */
    document.querySelectorAll('[data-open-nav]').forEach(function (b) {
        b.addEventListener('click', function () { body.classList.add('nav-open'); });
    });
    document.querySelectorAll('[data-close-nav]').forEach(function (b) {
        b.addEventListener('click', function () { body.classList.remove('nav-open'); });
    });

    /* ------------------------------------------------------------
     * 2. PESAN FLASH
     * Tombol X menutup pesan; pesan sukses (.flash-ok) hilang sendiri setelah 5 detik.
     * ------------------------------------------------------------ */
    document.querySelectorAll('.flash-x').forEach(function (b) {
        b.addEventListener('click', function () { b.parentNode.remove(); });
    });
    setTimeout(function () {
        document.querySelectorAll('.flash-ok').forEach(function (f) {
            f.style.transition = 'opacity .5s'; f.style.opacity = '0';
            setTimeout(function () { f.remove(); }, 500);
        });
    }, 5000);

    /* ------------------------------------------------------------
     * 3. TAMPILKAN / SEMBUNYIKAN PASSWORD
     * Tombol mata [data-toggle-pw] mengganti tipe input antara password <-> text.
     * ------------------------------------------------------------ */
    document.querySelectorAll('[data-toggle-pw]').forEach(function (b) {
        b.addEventListener('click', function () {
            var i = b.parentNode.querySelector('input');
            i.type = i.type === 'password' ? 'text' : 'password';
        });
    });

    /* ------------------------------------------------------------
     * 4. KONFIRMASI SEBELUM SUBMIT
     * Form dengan atribut data-confirm="..." menampilkan dialog OK/Batal dulu.
     * ------------------------------------------------------------ */
    document.querySelectorAll('form[data-confirm]').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            if (!window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
        });
    });

    /* ------------------------------------------------------------
     * 5. SELECT AUTO-SUBMIT
     * <select data-autosubmit> langsung mengirim form-nya ketika pilihan diganti
     * (urutkan karya, filter peran, ganti peran pengguna).
     * ------------------------------------------------------------ */
    document.querySelectorAll('[data-autosubmit]').forEach(function (s) {
        s.addEventListener('change', function () { s.form.submit(); });
    });

    /* ------------------------------------------------------------
     * 6. SUKA TANPA RELOAD
     * Mengirim form suka lewat fetch() ke karya_suka.php (balasan JSON {on, count}).
     * Setelah berhasil, SEMUA tombol suka untuk karya yang sama (di kartu maupun
     * di pop-up) diperbarui bersamaan supaya selalu sinkron.
     * Jika fetch gagal, form dikirim biasa (cadangan, halaman reload).
     * ------------------------------------------------------------ */

    /** Mengubah tampilan satu tombol suka (warna, aria, angka, animasi). */
    function setLikeState(btn, on, count) {
        btn.classList.toggle('on', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        btn.querySelector('.like-count').textContent = count;
        btn.classList.remove('pop'); void btn.offsetWidth; btn.classList.add('pop');
    }

    document.querySelectorAll('.like-form').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            e.preventDefault();
            var id = f.querySelector('input[name=id]').value;
            if (!id) return;
            fetch(f.action, {
                method: 'POST',
                body: new FormData(f),
                headers: { 'X-Requested-With': 'fetch' },
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); }).then(function (d) {
                // Perbarui setiap form suka yang id karyanya sama
                document.querySelectorAll('.like-form').forEach(function (other) {
                    var oid = other.querySelector('input[name=id]');
                    if (oid && oid.value === id) setLikeState(other.querySelector('.like-btn'), d.on, d.count);
                });
            }).catch(function () { f.submit(); });
        });
    });

    /* ------------------------------------------------------------
     * 7. POP-UP DETAIL KARYA
     * Klik gambar / judul kartu (class .js-karya-open) -> pop-up muncul di atas halaman
     * dengan latar blur. Susunan: gambar di atas, deskripsi di bawah.
     * Data diambil dari atribut data-* pada <article class="karya-card">
     * (dibuat oleh karya_card() di config.php).
     * Cara menutup: tombol X, klik area blur, atau tombol Esc.
     * Ctrl/Cmd/Shift + klik tetap membuka halaman penuh di tab baru (perilaku biasa).
     * ------------------------------------------------------------ */
    var modal = document.getElementById('karyaModal');
    if (modal) {
        var panel = modal.querySelector('.modal-panel');
        var lastFocus = null;   // elemen yang fokus sebelum pop-up dibuka (untuk dikembalikan)
        var $ = function (id) { return document.getElementById(id); };

        /** Mengisi pop-up dengan data karya dari kartu, lalu menampilkannya. */
        function openModal(card) {
            var d = card.dataset;
            lastFocus = document.activeElement;

            // Gambar + judul
            $('modalGambar').src = d.gambar;
            $('modalGambar').alt = d.judul;
            $('modalJudul').textContent = d.judul;
            $('modalKategori').textContent = d.kategori;
            $('modalUnggulan').hidden = d.unggulan !== '1';

            // Penulis: avatar (foto atau inisial), nama, kelas, tanggal
            var av = $('modalAvatar');
            av.textContent = '';
            if (d.avatar) {
                var im = document.createElement('img');
                im.src = d.avatar; im.alt = d.penulis;
                av.appendChild(im);
            } else {
                av.textContent = d.inisial;
            }
            $('modalPenulis').href = d.profil;
            $('modalNama').textContent = d.penulis;
            $('modalInfo').textContent = d.kelas + ' · ' + d.tanggal;

            // Deskripsi (textContent = aman dari XSS; baris baru dijaga lewat CSS white-space)
            $('modalDeskripsi').textContent = d.deskripsi && d.deskripsi.trim() !== ''
                ? d.deskripsi : 'Penulis belum menambahkan deskripsi.';
            $('modalDeskripsi').classList.toggle('is-empty', !(d.deskripsi && d.deskripsi.trim() !== ''));

            // Tombol link GitHub / website (disembunyikan bila karya lama belum punya link)
            var ln = $('modalLink');
            ln.hidden = !d.link;
            if (d.link) {
                ln.href = d.link;
                $('modalLinkLabel').textContent = d.linkLabel || 'Kunjungi';
            }

            // Tombol edit (hanya untuk pemilik karya / staf)
            var ed = $('modalEdit');
            ed.hidden = !d.edit;
            if (d.edit) ed.href = d.edit;

            // Tombol suka: salin status dari tombol suka di kartu
            var cardBtn = card.querySelector('.like-btn');
            $('modalSukaId').value = d.karyaId;
            setLikeState($('modalSuka'),
                cardBtn.classList.contains('on'),
                cardBtn.querySelector('.like-count').textContent);
            $('modalSuka').classList.remove('pop');

            // Jumlah dilihat (sementara dari kartu, lalu diperbarui dari server di bawah)
            $('modalDilihat').textContent = d.dilihat;

            // Tampilkan pop-up, kunci scroll halaman di belakangnya
            modal.hidden = false;
            body.classList.add('modal-open');
            panel.scrollTop = 0;
            requestAnimationFrame(function () { modal.classList.add('show'); });
            panel.focus();

            // Catat tayangan di server (sekali per sesi) dan tampilkan angka terbaru
            fetch('karya_lihat.php?id=' + encodeURIComponent(d.karyaId) + '&json=1', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) { if (j && j.ok) { $('modalDilihat').textContent = j.dilihat; d.dilihat = j.dilihat; } })
                .catch(function () { /* diabaikan: angka dari kartu tetap tampil */ });
        }

        /** Menutup pop-up dan mengembalikan fokus ke elemen sebelumnya. */
        function closeModal() {
            if (modal.hidden) return;
            modal.classList.remove('show');
            body.classList.remove('modal-open');
            // Tunggu animasi fade-out selesai sebelum benar-benar disembunyikan
            setTimeout(function () { modal.hidden = true; $('modalGambar').src = ''; }, 220);
            if (lastFocus && lastFocus.focus) lastFocus.focus();
        }

        // Buka: klik pada elemen .js-karya-open di dalam kartu mana pun (event delegation,
        // jadi tetap bekerja untuk kartu yang dimuat belakangan)
        document.addEventListener('click', function (e) {
            var trigger = e.target.closest('.js-karya-open');
            if (!trigger) return;
            if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return; // biarkan buka tab baru
            var card = trigger.closest('.karya-card');
            if (!card) return;
            e.preventDefault();
            openModal(card);
        });

        // Tutup: tombol X dan area blur (keduanya punya atribut data-modal-close)
        modal.querySelectorAll('[data-modal-close]').forEach(function (el) {
            el.addEventListener('click', closeModal);
        });

        // Keyboard: Esc menutup; Tab dijaga agar fokus tidak keluar dari pop-up
        document.addEventListener('keydown', function (e) {
            if (modal.hidden) return;
            if (e.key === 'Escape') { closeModal(); return; }
            if (e.key !== 'Tab') return;
            var f = Array.prototype.filter.call(
                panel.querySelectorAll('a[href], button, input:not([type=hidden])'),
                function (el) { return !el.hidden && el.offsetParent !== null; }
            );
            if (!f.length) return;
            var first = f[0], last = f[f.length - 1];
            if (e.shiftKey && (document.activeElement === first || document.activeElement === panel)) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        });
    }

    /* ------------------------------------------------------------
     * 8. PRATINJAU + KOMPRES GAMBAR SEBELUM UPLOAD
     * Gambar besar diperkecil (sisi terpanjang max 1800px, atau data-max) di browser
     * sebelum dikirim, supaya upload cepat dan hemat ruang server.
     * ------------------------------------------------------------ */

    /** Memperkecil gambar via <canvas>; memanggil cb(fileBaru). GIF/tipe lain dilewati. */
    function resizeFile(file, maxSide, cb) {
        if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return cb(file);
        var img = new Image();
        var url = URL.createObjectURL(file);
        img.onload = function () {
            URL.revokeObjectURL(url);
            var w = img.naturalWidth, h = img.naturalHeight;
            var scale = Math.min(1, maxSide / Math.max(w, h));
            // Sudah kecil -> tidak perlu diproses
            if (scale === 1 && file.size < 1.5 * 1024 * 1024) return cb(file);
            var c = document.createElement('canvas');
            c.width = Math.round(w * scale); c.height = Math.round(h * scale);
            c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
            // PNG kecil tetap PNG (menjaga transparansi); selain itu jadi JPEG
            var keepPng = file.type === 'image/png' && file.size < 3 * 1024 * 1024;
            var type = keepPng ? 'image/png' : 'image/jpeg';
            c.toBlob(function (blob) {
                if (!blob) return cb(file);
                var ext = keepPng ? '.png' : '.jpg';
                cb(new File([blob], file.name.replace(/\.[^.]+$/, '') + ext, { type: type }));
            }, type, 0.86);
        };
        img.onerror = function () { cb(file); };
        img.src = url;
    }

    /** Menampilkan pratinjau gambar yang dipilih (di area drop atau avatar). */
    function showPreview(input, file) {
        var zone = input.id ? document.querySelector('label[for="' + input.id + '"][data-drop]') : null;
        var reader = new FileReader();
        reader.onload = function (e) {
            if (zone) {
                var p = zone.querySelector('[data-preview]'), h = zone.querySelector('[data-hint]');
                p.src = e.target.result; p.hidden = false; if (h) h.hidden = true;
            }
            if (input.hasAttribute('data-avatar-input')) {
                var box = document.querySelector('[data-avatar-preview] .avatar');
                if (box) box.innerHTML = '<img src="' + e.target.result + '" alt="Pratinjau">';
            }
        };
        reader.readAsDataURL(file);
    }

    // Pasang ke setiap <input type=file data-resize>
    document.querySelectorAll('input[type=file][data-resize]').forEach(function (input) {
        var max = parseInt(input.getAttribute('data-max') || '1800', 10);
        input.addEventListener('change', function () {
            var f = input.files && input.files[0];
            if (!f) return;
            resizeFile(f, max, function (out) {
                // Ganti file di input dengan versi yang sudah diperkecil
                if (out !== f && window.DataTransfer) {
                    var dt = new DataTransfer(); dt.items.add(out); input.files = dt.files;
                }
                showPreview(input, out);
            });
        });
    });

    /* ------------------------------------------------------------
     * 9. SERET & LEPAS (drag & drop)
     * Area [data-drop] menyorot saat file diseret di atasnya, dan memasukkan
     * file yang dilepas ke input file terkait.
     * ------------------------------------------------------------ */
    document.querySelectorAll('[data-drop]').forEach(function (zone) {
        ['dragenter', 'dragover'].forEach(function (ev) {
            zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('over'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.remove('over'); });
        });
        zone.addEventListener('drop', function (e) {
            var input = document.getElementById(zone.getAttribute('for'));
            if (input && e.dataTransfer.files.length) {
                input.files = e.dataTransfer.files;
                input.dispatchEvent(new Event('change'));
            }
        });
    });
})();
