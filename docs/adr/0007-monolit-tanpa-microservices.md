# 0007 · Tetap satu aplikasi (monolit rapi), tidak dipecah menjadi microservices
Status: Diterima · Tanggal: 2026-11-17 · Penulis: Nadia Kusuma (99000004)

## Konteks
Aplikasi berisi buku tamu, katalog, pinjam-kembali, dan pemicu pengingat
dalam satu `index.php` (±220 baris). Beban per bagian tidak jauh berbeda:
semua ringan, CPU aplikasi ±4 ms per request (P8), dan yang paling dulu
habis adalah database bersama. Tim 4 orang (latihan) / 1–2 orang
(organisasi kasus). Slide P14: mulai dari monolit yang rapi, pecah hanya
dengan alasan terukur.

## Keputusan
Kami mempertahankan **satu aplikasi web** dengan satu database. Yang sudah
dipisah hanya pekerjaan yang memang berbeda sifat: pengiriman pengingat
(asinkron, ADR 0006). Pemisahan di dalam kode cukup per rute dan per tabel.

## Alternatif yang ditolak
- Microservices (katalog, sirkulasi, notifikasi masing-masing satu layanan
  + database sendiri): tiga pipeline, tiga deployment, panggilan antar
  layanan lewat jaringan, dan transaksi pinjam yang dulu satu `COMMIT`
  menjadi transaksi terdistribusi. Tidak ada beban per bagian yang
  berbeda jauh yang membenarkannya.
- Memecah katalog menjadi layanan baca terpisah supaya bisa di-scale
  sendiri: cache Nginx/CDN (P13 C.3) sudah menaikkan kapasitas baca katalog
  jauh lebih besar dengan biaya hampir nol.

## Konsekuensi
- (+) Satu image, satu pipeline, satu tempat melihat log dan trace.
- (+) Aturan stok tetap satu transaksi database (F-12 lulus).
- (-) Bila satu bagian rusak, image baru untuk semua bagian harus di-deploy.
- Ditinjau ulang bila tim > 8 orang atau satu bagian butuh skala yang
  berbeda jauh (mis. katalog publik untuk beberapa kampus).
