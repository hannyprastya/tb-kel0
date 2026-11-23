# Pengujian · Perpustakaan Kampus (P10 CI/CD dan pengujian, P11 SLO dan alert)

> **Contoh hasil.** Sejak P12 aplikasi = starter Laravel + modul
> perpustakaan (ADR 0004); angka Bagian P10 di bawah **diukur ulang** pada
> aplikasi itu (2026-11-23, laptop macOS 10 core, Docker/OrbStack, dipakai
> banyak proses lain: load average dicatat per putaran). Angka buku tamu
> PHP lama tetap dicatat di subbagian *Riwayat* sebagai pembanding. Baris
> bertanda *(ilustrasi)* tidak bisa diukur di repo contoh ini (belum ada
> layanan Render/uptime monitor sungguhan). Angka kelompokmu akan berbeda.

Pemilik dokumen: Dimas Ardiansyah (99000003). Semua load test hanya ke
compose sendiri (`http://app:8080` atau `http://nginx`), tidak pernah ke
URL Render.

## Bagian P10

### Tabel 1. Kebutuhan → kriteria lulus (ditulis sebelum menguji)
| Kebutuhan (P9) | Skenario uji | Kriteria lulus |
|----------------|--------------|----------------|
| Kontrak aplikasi (`/health`, `/healthz`, 503 saat DB mati) dan fitur starter | uji fungsional F-01..F-19 (`tests/fungsional.sh`) | semua lulus |
| Fungsional 1–3, 5, 7 (katalog, pinjam, kembali, sampul/scan, status) | uji fungsional K-01..K-20 (`web/tests/kasus.sh`) + Feature test `tests/Feature/Kasus/` | semua lulus |
| Stok tidak boleh minus walau serentak (fungsional 2) | 30 permintaan pinjam serentak ke satu judul berisi 2 eksemplar (K-14) | 2 × 201, 28 × 409, tersedia akhir 0 |
| Isian salah tidak membuat error 500 (pelajaran F-04 P10) | Feature test: `anggota` berupa array, `hari` 30, buku tidak ada; `nama[]=x` di buku tamu | 422 + pesan, tidak ada baris tersimpan |
| Katalog melayani 100 pengguna bersamaan (puncak 1.000 pengguna) | k6 `uji.js` ke `/buku` (2.000 judul, 50 baris per halaman): 25 → 50 → 100 VU, masing-masing 1 menit | p95 < 1 detik |
| Jarang gagal | `http_req_failed` saat load test | error rate < 1% |
| Selalu bisa diakses (99,5%) | uptime monitor ke `/health` tiap 5 menit, ≥ 24 jam | uptime ≥ 99,5% |
| Pulih bila ada gangguan | hentikan db 20 detik, 3 kali (F.3) | pulih < 1 menit setelah db hidup; MTTR < 5 menit |

### Tabel 2. Hasil uji fungsional (Laravel)
Keluaran `bash tests/fungsional.sh` (juga langkah *Test* CI), dipendekkan:

| ID | Langkah | Diharapkan | Aktual | Status |
|----|---------|------------|--------|--------|
| F-01, F-02 | `/health`, `/healthz` saat DB normal | 200, `{"status":"ok","db":"ok"}` | 200, 200 | Lulus |
| F-03, F-04 | `/`; header `Server` | 200; tanpa versi, tanpa `X-Powered-By` | 200; `Server: Apache` | Lulus |
| F-05..F-08 | buku tamu: simpan (CSRF), nama kosong, tanpa token, `/tamu` publik | 302, 422, 419, tanpa cookie | sama | Lulus |
| F-09..F-11b | daftar, dasbor, keluar, masuk lagi | 302 `/dashboard`, 200, 302, 302 | sama | Lulus |
| F-12..F-14 | unggah PNG ke object storage, unduh bertanda tangan, tanda tangan diubah, PHP berkedok `.png` | 302, 200 + isi sama, 403, ditolak | sama | Lulus |
| F-15 | `POST /api/tamu` → notifikasi oleh worker | `notifikasi_terkirim: true` | true | Lulus |
| F-16, F-17 | alamat tidak ada; API nama kosong | 404; 422 | 404; 422 | Lulus |
| F-18..F-18c | db `stop`: `/health`, `/`, `/healthz` | 503 JSON; 503 ramah + `Retry-After: 60`; 200 | sama | Lulus |
| F-19 | db `pause` (macet) | `/health` 503 dalam ≤ 3 s | 503, 3 s | Lulus |
| K-01, K-02 | katalog publik `/buku` | 200, tanpa cookie, `Cache-Control: max-age=60, public` | sama | Lulus |
| K-03 | `/buku/kelola` tanpa login | 302 ke `/login` | 302 | Lulus |
| K-04, K-05 | petugas menambah buku + sampul + scan; cari di katalog | 302; judul muncul | 302; 1 baris | Lulus |
| K-06 | sampul dari object storage | 200, isi sama, boleh di-cache | sama | Lulus |
| K-07, K-08 | scan lewat tautan bertanda tangan; tanda tangan diubah | 200 + isi sama; 403 | sama | Lulus |
| K-09 | PHP berkedok `.png` sebagai sampul | ditolak (jenis dari isi berkas) | ditolak | Lulus |
| K-10..K-12 | pinjam lewat formulir; tanpa no. anggota; pinjam sampai habis | 302; 422; 201 lalu 409 | sama | Lulus |
| K-13 | kembalikan, lalu kembalikan lagi | 200, lalu 404 | 200, 404 | Lulus |
| K-14 | 30 pinjam serentak, 2 eksemplar | 2 × 201, 28 × 409, tersedia 0 | `2x201 28x409/0` | Lulus |
| K-15 | `/api/buku/status` | 200 JSON | 200 | Lulus |
| K-16 | pinjam jatuh tempo besok → `kasus:pengingat-jatuh-tempo` → worker (P14) | `pengingat_terkirim: true` | true (±1–3 s) | Lulus |
| K-17 | perintah pengingat dijalankan lagi | tetap 1 catatan di `notifikasi` | 1, `dititipkan=0` | Lulus |
| K-18 | jadwal terdaftar | `kasus:pengingat-jatuh-tempo` di `schedule:list` | ada | Lulus |
| K-19 | db `stop` (P15): katalog, API | 503 + "Maaf", 503 JSON | sama | Lulus |
| K-20 | db `pause` (P15): katalog, API | 503 + "Maaf" dalam ≤ 3 s, 503 | 503 dalam 3,01 s (sebelum perbaikan `DbGagal`: **500**) | Lulus |

Hasil: **F-01..F-19 dan K-01..K-20 semua lulus** (K-16..K-18 sejak P14,
K-19..K-20 sejak P15). PHPUnit (`docker compose run --rm uji`,
PostgreSQL): 80 test, 315 assertion lulus; 24 milik modul
(`tests/Feature/Kasus/`) dan 2 milik kelompok di `tests/Unit/DbGagalMacetTest.php`.
K-16 juga lulus dengan `QUEUE_CONNECTION=sqs` (antrean SQS di Floci), dan
semua F/K selain uji db mati lulus dalam mode satu container (`JALANKAN_WORKER=1`, `TANPA_DB_MATI=1`).

Yang menjamin stok (K-14): satu `UPDATE buku SET tersedia = tersedia - 1
WHERE id = ? AND tersedia > 0` (baris dikunci PostgreSQL, syarat dicek
ulang setelah kunci) + `CHECK (tersedia BETWEEN 0 AND stok)`. Feature test
`test_database_menolak_stok_minus_atau_lebih_dari_stok` membuktikan CHECK
menolak `-1` dan nilai di atas stok walau kode aplikasi dilewati.

### Tabel 3. Hasil load test (k6 1.8.2, `uji.js`)
Data uji: 2.005 judul (2.000 dibuat `generate_series`), halaman `/buku`
50 baris = 25,9 KB HTML.

| Rute · VU | Rata-rata (ms) | p95 (ms) | max (ms) | Throughput (req/s) | Error (%) | THRESHOLDS | load average |
|-----------|----------------|----------|----------|--------------------|-----------|------------|--------------|
| `/buku` · 1 (smoke, 30 s) | 16,22 | 24,61 | 27 | 0,98 | 0 | ✓ | 28–29 |
| `/buku` · 25 | 21,24 | 32,75 | 98 | 24,42 | 0 | ✓ | 29–34 |
| `/buku` · 50 | 18,55 | 34,37 | 141 | 48,94 | 0 | ✓ | 34–26 |
| `/buku` · 100 | 22,73 | 49,16 | 299 | 97,09 | 0 | ✓ | 26–34 |
| `/api/buku/status` · 100 | 28,23 | 79,71 | 229 | 96,23 | 0 | ✓ | 34–38 |
| `/` (halaman bersesi) · 100 | 254,45 | **1.222,87** | 1.965 | 79,09 | 0 | ✗ (p95) | 38–63 |

Cek kewajaran 100 VU `/buku`: 100 ÷ (1 + 0,023) ≈ 97,8 req/s, cocok
dengan 97,1 terukur. **Titik lutut katalog belum terlihat sampai 100 VU.**
Halaman bersesi `/` jauh lebih mahal: setiap permintaan membaca dan
menulis baris sesi di PostgreSQL, sehingga p95 melewati 1 s pada 100 VU;
karena itu cache P13 hanya untuk halaman `publik` dan pengunjung katalog
tidak diberi sesi. Kapasitas tanpa jeda (`uji-maks.js`, 50 VU 30 s, P13):
`/buku` ±216–364 req/s untuk satu instance, tergantung beban laptop.

### Tabel 4. Availability dan reliability
| Pengukuran | Hasil | Bukti |
|------------|-------|-------|
| Lama monitor dan total cek | 36 jam, 432 cek *(ilustrasi)* | screenshot UptimeRobot |
| Cek berhasil / gagal → uptime | 431 / 1 → 99,77% *(ilustrasi; 1 gagal = Neon bangun dari tidur)* | hitungan F.2 |
| Waktu pulih uji 1 / 2 / 3 (db dihentikan 20 s) | 21,2 / 21,2 / 21,1 detik | `tests/pantau.sh`: stop 21:54:14 → 200 pertama 21:54:35, dst. |
| MTTR | **21,2 detik** (20 detik di antaranya db sengaja mati) | rata-rata 3 uji |
| Deploy Render: kode selain 200 | 0 *(ilustrasi)*: Render baru mengalihkan trafik setelah `/health` versi baru lulus | screenshot `tests/pantau.sh` |

### Tabel 5. Target vs hasil
| Kriteria | Target | Hasil | Status | Catatan |
|----------|--------|-------|--------|---------|
| Fungsional | semua F dan K | F-01..F-19, K-01..K-20 lulus; PHPUnit 80/80 | Lulus | K-20 lulus setelah perbaikan P15 |
| Stok serentak | tidak minus | 2 / 28 / 0 (K-14) | Lulus | dijamin database |
| p95 katalog @100 VU | < 1 s | 49,16 ms | Lulus | laptop, bukan Render |
| Error rate load test | < 1% | 0% | Lulus | |
| Uptime 24–48 jam | ≥ 99,5% | 99,77% *(ilustrasi)* | Lulus | jendela pendek |
| MTTR | < 5 menit | 21,2 s | Lulus | gangguan buatan, pulih otomatis saat db hidup |

### Tabel 6. Usulan SLO awal (bahan P11)
| Yang diukur (SLI) | Hasil | Usulan SLO awal |
|-------------------|-------|-----------------|
| Uptime `/health` per bulan | 99,77% *(ilustrasi)* | ≥ 99,5% per 30 hari |
| p95 katalog pada 100 VU | 49,16 ms (Laravel), 76,84 ms (PHP) | ≤ 300 ms (cadangan ±6× untuk vCPU Render yang jauh lebih kecil) |
| Error rate saat load test | 0% | < 0,5% respons 5xx |

### Riwayat: buku tamu PHP (P10, sebelum pindah)
Diukur pada `index.php` P10 (2026-11-22). Dicatat karena keputusan dan SLO
P11 dibuat dari angka ini.
- Uji F-01..F-12 versi PHP: 11 dari 12 lulus; **F-04** `nama[]=x` → 500
  (`trim(): Argument #1 ($string) must be of type string, array given`,
  baris 42), lulus setelah PR `fix/f04-tipe-nama`. Di Laravel aturan
  `'string'` di validasi + Feature test `BukuTamuTest::test_nama_berupa_array_ditolak`
  dan `PerpustakaanTest::test_isian_pinjam_salah_ditolak` menggantikan
  baris itu.
- F-12 PHP (30 pinjam serentak, 2 eksemplar): 2 × 302, 28 × 409, tersedia 0.
- k6 `/buku` PHP (3 judul, halaman 693 B): 100 VU → p95 76,84 ms, 96,5
  req/s, 0 gagal.
- MTTR PHP: 21 / 22 / 21 s.

## Bagian P11

> Tabel 1–2 dan trace di bawah diukur di **buku tamu PHP** (P11, sebelum
> pindah). Pengukuran ulang di Laravel (rute `/lambat` tidak ada lagi) ada
> di subbagian *P11 diukur ulang di Laravel* (P15, 2026-11-23).

### Tabel 1–2 (lab anggota Dimas, `app-99000003`, buku tamu PHP)
k6 `uji-otel.js` (20 VU, 1 menit, 20% ke `/lambat`); waktu sisi server
dari kueri B.4 (`traces_spanmetrics_latency_sum/count`, jendela 1 menit).

| Konfigurasi | avg k6 (ms) | p95 k6 (ms) | req/s | server `GET /` (ms) | server `GET /lambat` (ms) |
|-------------|-------------|-------------|-------|---------------------|---------------------------|
| bawaan (150 worker) | 78 | 322 | 18,3 | 8,7 | 312 |
| 1 worker | 602 | 1.510 | 12,2 | 8,6 | 311 |
| 4 worker | 98 | 345 | 18,0 | 15,0 | 318 |

Satu trace `GET /lambat`: span induk 308,20 ms, `PDO::query`
`pg_sleep(0.3)` 301,37 ms → **99,5%** di database. Hitungan B.5 (1 worker):
waktu layanan 0,8 × 8,6 + 0,2 × 311 = 69 ms → kapasitas 14,5 req/s < beban
18,7 req/s → antrean tumbuh; selisih p95 k6 dan span server = **waktu antre
menunggu worker**.

### P11 diukur ulang di Laravel (P15, 2026-11-23)
Stack `deploy/compose/otel.yml` (image `Dockerfile.otel`, ekstensi
`opentelemetry` 1.5.0 + `opentelemetry-auto-laravel`), `NIM=99000003`.
k6 `uji-otel.js` 20 VU 1 menit, campuran `/`, `/buku`, `/api/buku/status`;
waktu sisi server = `traces_spanmetrics_latency_sum/count` per `span_name`
(jendela 1 menit).

| Konfigurasi | avg k6 (ms) | p95 k6 (ms) | req/s | server `GET /` | server `GET /buku` | server `GET /api/buku/status` |
|-------------|-------------|-------------|-------|----------------|--------------------|-------------------------------|
| 4 worker (bawaan) | 30,8 | 50,1 | 19,4 | 16,4 ms | 10,8 ms | 8,3 ms |
| 1 worker | 36,5 | 64,2 | 19,2 | 13,6 ms | 9,6 ms | 7,3 ms |

Hitungan B.5 (1 worker): waktu layanan rata-rata (13,6 + 9,6 + 7,3) ÷ 3 =
10,2 ms → kapasitas 1000 ÷ 10,2 = 98 req/s, jauh di atas beban 20 ÷ (1 +
0,036) = 19,3 req/s → **tidak ada antrean**; p95 1 worker hanya naik 14 ms.
Tanpa `/lambat` (query 0,3 s) bottleneck worker baru terlihat pada uji
tanpa jeda (optimasi.md C.1: 1 worker ±101–117 req/s).

Satu trace `GET /buku` (median dari 20 terakhir) di Tempo: span induk
9,23 ms; `App\Models\Kasus\Buku::get` 6,05 ms, di dalamnya `sql SELECT`
(`select "id", "kode", "judul" … from "buku" order by "judul" limit 50`)
5,60 ms → **61% di database**, sisanya ±3,2 ms membuat model dan merender
Blade. Halaman bersesi `GET /` lebih mahal (16,4 ms) karena membaca dan
menulis baris `sessions`. Log Loki `DB gagal: SQLSTATE[08006] …` membawa
`trace_id` yang membuka trace ERROR yang sama.

### Tabel 3. SLO kelompok
| SLI | SLO (target + kondisi) | Alasan dari kebutuhan |
|-----|------------------------|-----------------------|
| p95 katalog `/buku` | ≤ 300 ms @100 VU, k6 1 menit | target P9 < 1 s; cadangan untuk instance kecil dan minggu ujian |
| Throughput | ≥ 90 req/s @100 VU | 100 VU dengan jeda 1 s ≈ 97 req/s bila tidak antre |
| Error rate 5xx | < 0,5% | 99,5% permintaan berhasil |
| Availability | ≥ 99,5% per 30 hari | kebutuhan.md bagian 4 (1.000 pengguna) |
| Gangguan → alert | ≤ 2 menit | petugas sirkulasi menyadari sebelum antrean di meja memanjang |

### Tabel 4. Error budget dan burn rate
| Perhitungan | Hasil |
|-------------|-------|
| Error budget 99,5% per 30 hari | (1 − 0,995) × 43.200 = **216 menit** |
| Downtime dari uptime P10 | 1 cek gagal × 5 menit ≤ 5 menit → sisa ≥ 97,7% *(ilustrasi)* |
| PHP (P11): 1 jam berisi lab B + D.2, 2.093 gagal dari 9.969 permintaan | 0,21 → burn rate **42** → budget habis dalam 17,1 jam |
| Laravel (P15): 1 jam berisi 2 uji OTel + 2 × G1, 3.004 gagal dari 13.980 permintaan k6 | 0,215 → burn rate 0,215 ÷ 0,005 = **43** |
| Dengan burn rate itu budget 30 hari habis dalam | 30 × 24 ÷ 43 = **16,7 jam** |
| Ambang page | 14,4 × 0,005 = **7,2%** error rate selama 1 jam |

### Alert dan dasbor sebagai kode
Aturan alert dibuat dari nol di Grafana (P11 D.1: interval 10 s, pending
30 s, no data = Normal, Runbook URL), diekspor ke
`observability/alert-rules.yaml`; dasbor SLO di
`observability/slo-dashboard.json`. Keduanya dimuat otomatis oleh
`deploy/compose/otel.yml`. Kueri memakai `service=~"app-.+"` supaya berlaku
untuk NIM siapa pun. Saat pindah ke Laravel (P12) berkas starter dipakai
dan nama folder, uid, dan `runbook_url` kelompok dipasang lagi: span
Laravel bernama `GET /buku`, `POST /buku/pinjam`, dst. (rute, bukan URL).

### Tabel 5. Bukti alert (jam WIB; PHP dari tab History, Laravel dari API Grafana tiap 2 s)
| Latihan | Aplikasi | db dihentikan | Pending | Alerting (Firing) | db dinyalakan | Normal | Gangguan → Firing |
|---------|----------|---------------|---------|-------------------|---------------|--------|-------------------|
| 1 (D.2, k6 5 menit, 2026-11-22) | buku tamu PHP | 05:37:48 | 05:38:10 (A=0,296) | 05:38:40 (A=0,918) | 05:39:11 | 05:40:10 | 52 s |
| 2 (G1 ke-1, P15, 2026-11-23) | Laravel | 22:33:49 | 22:34:11 (A=1,0) | 22:34:41 (A=1,0) | 22:35:12 | 22:36:11 | 52 s |
| 3 (G1 ke-2, P15, 2026-11-23) | Laravel | 22:39:08 | 22:39:21 (A=1,0) | 22:39:51 (A=1,0) | 22:40:22 | 22:41:21 | 43 s |

Di Laravel A = 1,0 (semua span server error) karena ketiga rute `uji-otel.js`
membutuhkan database; di PHP sebagian rute tidak.

### Tabel 7. Target vs hasil kinerja
| SLI | Target (SLO) | Hasil | Selisih | Status | Sumber |
|-----|--------------|-------|---------|--------|--------|
| p95 @100 VU | ≤ 300 ms | 49,16 ms (Laravel, P12) | −251 ms | Tercapai | k6 Tabel 3 |
| Throughput @100 VU | ≥ 90 req/s | 97,09 req/s | +7,1 | Tercapai | k6 Tabel 3 |
| Error rate 5xx (tanpa gangguan) | < 0,5% | 0% | – | Tercapai | k6 |
| Availability 30 hari | ≥ 99,5% | 99,77% *(ilustrasi, jendela 36 jam)* | +0,27 | Tercapai (sementara) | uptime P10 |
| p95 @20 VU campuran (OTel) | (informasi) | 50,1 ms (4 worker) | – | – | k6 + Grafana |
| Gangguan → alert | ≤ 2 menit | 43–52 s | −68 s | Tercapai | alert D.2, G1 × 2 |
