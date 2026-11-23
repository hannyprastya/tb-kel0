# 0004 · Pindah dari buku tamu PHP ke starter Laravel
Status: Diterima · Tanggal: 2026-11-10 · Penulis: Raka Pratama (99000001)

## Konteks
Sampai P11 aplikasi kami satu berkas `index.php` (±180 baris): buku tamu
P5–P8 + fitur kasus (katalog, pinjam, kembali, status JSON) yang ditempel
di atas cabang 404. Yang kami alami selama P9–P11:
- **Tidak ada login.** Siapa pun yang tahu alamatnya bisa mengirim
  `POST /buku/pinjam`; kebutuhan fungsional 2 meminta *petugas* yang
  mencatat. Menambah login, sesi, dan CSRF sendiri berarti menulis ulang
  hal yang rawan salah.
- **Validasi manual.** Bug F-04 (P10: `nama[]=x` → 500) lahir dari
  `trim()` tanpa cek tipe; setiap isian baru butuh pola `is_string()` yang
  sama.
- **Pemeriksaan P12 A pada image buku tamu** (2026-11-10, diulang
  2026-11-22): `/vendor/composer/installed.json`, `/composer.lock`,
  `/Dockerfile` bisa diunduh (4 × 200), versi Apache/PHP terlihat di header,
  Apache induk berjalan sebagai root (temuan T-01, T-02, T-05 di
  [keamanan.md](../keamanan.md)).
- **P14 butuh antrean, tugas terjadwal, dan unggah sampul/scan.** Di
  `index.php` itu berarti SDK AWS + fungsi Bref terpisah (`serverless/`),
  dua cara deploy untuk satu aplikasi kecil.

Mulai P12 mata kuliah menyediakan **starter Laravel 13** (PHP 8.4,
PostgreSQL 17) yang menjaga kontrak alat P10–P15: port `$PORT` 8080,
`/health` (DB, batas 3 s), `/healthz`, `DATABASE_URL`, log ke stderr,
halaman 503 saat DB mati. Isinya sudah termasuk login (Breeze), antrean +
worker, scheduler, disk `s3`, dan CI dengan Larastan.

## Keputusan
Di P12 repo `tb-kel0` pindah ke starter Laravel lewat PR
`pindah-laravel`, mengikuti langkah README starter:
1. Aplikasi PHP lama dihapus (`index.php`, `composer.*`, `Dockerfile*`,
   compose lapisan, `k8s/`); `docs/` P9–P11 tetap, path diperbarui.
2. Fitur kasus ditulis ulang sebagai **modul kasus** di `web/`
   (`web/KASUS-00.md`): tabel `petugas`, `buku`, `pinjam`; katalog publik;
   pinjam/kembali hanya untuk petugas yang login; sampul dan scan ke object
   storage; API JSON `/api/buku/...`.
3. Data lama tidak dihapus: migrasi starter mengganti `tamu` → `tamu_lama`,
   migrasi modul mengganti `buku`/`pinjam` buatan `index.php` →
   `buku_lama`/`pinjam_lama` (diuji pada salinan skema lama: 2 buku,
   1 pinjam, 2 tamu tetap ada). Isinya hanya data contoh, jadi tidak
   dipindah ke tabel baru.
4. Produksi tetap Render + Neon (ADR 0001, 0002) dalam **mode satu
   container** (`JALANKAN_WORKER=1`: Apache + worker + scheduler), ditambah
   **Supabase Storage** sebagai object storage S3-compatible untuk sampul
   dan scan.

## Alternatif yang ditolak
- **Tetap di `index.php`** dan menambah login, CSRF, validasi, antrean
  sendiri: paling sedikit berubah hari ini, tetapi semua mekanisme keamanan
  ditulis tangan oleh tim 4 orang yang baru belajar; tidak ada analisis
  statis yang berarti (PHPStan level 5 hanya melihat satu berkas).
- **Framework lain (Symfony, Slim) atau Node/Express:** setara secara
  teknis, tetapi tidak ada starter yang sudah menjaga kontrak P10–P15;
  pipeline, compose, k8s, dan alert harus disusun ulang sendiri.
- **`laravel new` dari nol tanpa starter:** login dan antrean ada, tetapi
  `/health` 3 s, halaman 503, grup `publik` untuk cache, mode satu
  container, Dockerfile non-root, dan CI harus dibuat ulang; risiko beda
  perilaku dengan modul P13–P15.

## Konsekuensi
- (+) Login + peran petugas, CSRF, validasi bawaan (F-04 menjadi Feature
  test, bukan baris `is_string`), job idempoten, scheduler, disk `s3`
  dengan tautan bertanda tangan 5 menit: semuanya kode framework yang diuji
  banyak orang.
- (+) Temuan P12 T-01, T-02, T-05 tertutup oleh rancangan starter (docroot
  hanya `web/public/`, `ServerTokens Prod`, `USER www-data`): dicek ulang di
  image baru, [keamanan.md](../keamanan.md) bagian 2.
- (+) Uji bertambah: PHPUnit + PostgreSQL (`docker compose run --rm uji`),
  Larastan level 5, uji fungsional F-01..F-19 + K-01..K-15.
- (−) **Lebih berat.** Image produksi 613 MB, 94 paket Composer produksi (dulu 30,
  kebanyakan OpenTelemetry); tiga proses (web, worker, scheduler) ±90 MiB
  RAM saat menganggur. CPU aplikasi per permintaan ±6,8 ms (dulu ±4 ms,
  P8): kebutuhan vCPU target dihitung ulang di P13
  ([kebutuhan.md](../kebutuhan.md) bagian 6).
- (−) Permukaan serangan baru dari dependensi (Composer + npm) dan CVE
  base image: dipantau `composer audit`, `npm audit`, Trivy, Dependabot
  ([keamanan.md](../keamanan.md) bagian 6).
- (−) Render Free tidur setelah ±15 menit sepi; dalam mode satu container
  worker dan scheduler ikut tidur (dicatat di evaluasi P15).
- (−) Satu pertemuan habis untuk pindah; fitur kasus ditulis ulang (±1.000
  baris PHP + Blade, termasuk view dan migrasi), dan langkah modul buku
  tamu P13–P15 harus diterjemahkan lewat tabel padanan README starter.
- Ditinjau ulang bila starter berganti versi mayor (Laravel 14) atau bila
  satu container Render tidak lagi cukup untuk web + worker.
