# Pengujian · Perpustakaan Kampus (P10 CI/CD dan pengujian, P11 SLO dan alert)

> **Contoh hasil.** Angka uji di dokumen ini diukur pada 2026-11-22 di
> laptop (macOS, Docker/OrbStack, 10 core) yang sedang dipakai banyak proses
> lain (load average 9–40), dengan image versi akhir (4 worker Apache).
> Baris yang bertanda *(ilustrasi)* tidak bisa diukur di repo contoh ini
> karena belum ada layanan Render/uptime monitor sungguhan; angkanya contoh
> yang masuk akal, bukan hasil ukur. Angka kelompokmu akan berbeda.

Pemilik dokumen: Dimas Ardiansyah (99000003). Semua load test hanya ke
compose sendiri (`http://app:8080`), tidak pernah ke URL Render.

## Bagian P10

### Tabel 1. Kebutuhan → kriteria lulus (ditulis sebelum menguji)
| Kebutuhan (P9) | Skenario uji | Kriteria lulus |
|----------------|--------------|----------------|
| Fungsional 1–3, 6, 7 (katalog, pinjam, kembali, buku tamu, status) | uji fungsional F-01..F-12 (`tests/fungsional.sh`), termasuk kasus negatif | semua test case lulus |
| Stok tidak boleh minus walau serentak (fungsional 2) | 30 permintaan pinjam serentak ke satu buku (F-12) | berhasil = eksemplar tersedia, sisanya 409, stok akhir 0 (tidak minus) |
| Katalog melayani 100 pengguna bersamaan (puncak 1.000 pengguna) | k6 `uji.js` ke `/buku`: 25 → 50 → 100 VU, masing-masing 1 menit | p95 < 1 detik |
| Jarang gagal | `http_req_failed` saat load test | error rate < 1% |
| Selalu bisa diakses (99,5%) | uptime monitor ke `/health` tiap 5 menit, ≥ 24 jam | uptime ≥ 99,5% |
| Pulih bila ada gangguan | hentikan db 20 detik, 3 kali (F.3) | pulih < 1 menit setelah db hidup; MTTR < 5 menit |

### Tabel 2. Hasil uji fungsional
Keluaran `bash tests/fungsional.sh` (juga dijalankan di langkah *Test* CI):

| ID | Langkah | Diharapkan | Aktual | Status |
|----|---------|------------|--------|--------|
| F-01 | Buka halaman utama `/` | 200, judul tampil | 200 | Lulus |
| F-02 | Simpan nama "Ani (uji)" | 302, nama muncul di daftar | 302 | Lulus |
| F-03 | Simpan nama kosong (curl) | 400 | 400 | Lulus |
| F-04 | Kirim `nama[]=x` (curl) | 400 | 500 sebelum perbaikan; 400 sesudah | Gagal; lulus setelah PR `fix/f04-tipe-nama` |
| F-05 | Buka `/tidak-ada` | 404 | 404 | Lulus |
| F-06 | `/health` saat DB normal | 200, `{"status":"ok","db":"ok"}` | 200 | Lulus |
| F-07 | `/health` saat DB mati (lokal) | 503, tanpa detail rahasia | 503 `{"status":"error","db":"gagal"}` | Lulus |
| F-08 | Pinjam buku 1, anggota A-0001 | 302 + no. peminjaman | 302 | Lulus |
| F-09 | Pinjam tanpa no. anggota | 400 | 400 | Lulus |
| F-10 | Pinjam buku 3 saat eksemplarnya habis | 409, stok tetap 0 | 409, stok 0 | Lulus |
| F-11 | Kembalikan pinjaman F-08, lalu kembalikan lagi | 302, lalu 404 | 302, 404 | Lulus |
| F-12 | 30 pinjam serentak ke buku 2 (2 eksemplar) | 2 × 302, 28 × 409, tersedia 0 | 2 / 28 / 0 | Lulus |

Uji tambahan di luar skrip: 100 permintaan pinjam serentak (50 paralel) ke
buku 1 (3 eksemplar) → **3 × 302, 97 × 409**, `min(tersedia) = 0`, tidak
ada baris minus. Yang menjamin: `UPDATE ... WHERE tersedia > 0` (baris
dikunci, dicek ulang setelah kunci) + `CHECK (tersedia BETWEEN 0 AND stok)`.

**Temuan F-04** (P10 D.3): log aplikasi
`PHP Fatal error: Uncaught TypeError: trim(): Argument #1 ($string) must be
of type string, array given in /var/www/html/index.php:42`. Diperbaiki dengan
dua baris `is_string()` di rute `/tamu`; fitur kasus sejak awal memakai
fungsi `$isi()` yang sama polanya.

### Tabel 3. Hasil load test (k6 1.8.2, `uji.js` ke `/buku`)
| VU | Rata-rata (ms) | p95 (ms) | Throughput (req/s) | Error rate (%) | THRESHOLDS |
|----|----------------|----------|--------------------|----------------|------------|
| 1 (smoke, 30 s) | 9,47 | 16,14 | 0,99 | 0 | ✓ |
| 25 | 13,49 | 29,16 | 24,58 | 0 | ✓ |
| 50 | 9,21 | 13,37 | 49,38 | 0 | ✓ |
| 100 | 25,86 | 76,84 | 96,50 | 0 | ✓ |

Cek kewajaran 100 VU: 100 ÷ (1 + 0,026) ≈ 97,5 req/s, cocok dengan 96,5
terukur. **Titik lutut belum terlihat sampai 100 VU** (req/s masih naik
lurus, tanpa error), tetapi p95 naik ±6× dari 50 ke 100 VU dan `max` 397 ms:
tanda awal antrean di 4 worker Apache (dibuktikan di P11 Bagian B).
Kapasitas tanpa jeda (`uji-maks.js`, P13 C.2) ±430–490 req/s untuk satu
instance.

### Tabel 4. Availability dan reliability
| Pengukuran | Hasil | Bukti |
|------------|-------|-------|
| Lama monitor dan total cek | 36 jam, 432 cek *(ilustrasi)* | screenshot UptimeRobot |
| Cek berhasil / gagal → uptime | 431 / 1 → 99,77% *(ilustrasi; 1 gagal = Neon bangun dari tidur)* | hitungan F.2 |
| Waktu pulih uji 1 / 2 / 3 (db dihentikan 20 s) | 21 / 22 / 21 detik | `pantau.sh`: stop 05:51:32 → 200 pertama 05:51:53, dst. |
| MTTR | **21,3 detik** (20 detik di antaranya db sengaja mati) | rata-rata 3 uji |
| Deploy Render: kode selain 200 | 0 *(ilustrasi)*: Render baru mengalihkan trafik setelah `/health` versi baru lulus | screenshot `pantau.sh` |

### Tabel 5. Target vs hasil
| Kriteria | Target | Hasil | Status | Catatan |
|----------|--------|-------|--------|---------|
| Fungsional | 12 dari 12 | 11 dari 12 → 12 dari 12 | Lulus (setelah PR) | F-04 gagal dulu, diperbaiki |
| Stok serentak | tidak minus | 2/28/0 dan 3/97/0 | Lulus | dijamin database |
| p95 katalog @100 VU | < 1 s | 76,84 ms | Lulus | laptop, bukan Render |
| Error rate load test | < 1% | 0% | Lulus | |
| Uptime 24–48 jam | ≥ 99,5% | 99,77% *(ilustrasi)* | Lulus | jendela pendek |
| MTTR | < 5 menit | 21,3 s | Lulus | gangguan buatan, pulih otomatis saat db hidup |

### Tabel 6. Usulan SLO awal (bahan P11)
| Yang diukur (SLI) | Hasil P10 | Usulan SLO awal |
|-------------------|-----------|-----------------|
| Uptime `/health` per bulan | 99,77% *(ilustrasi)* | ≥ 99,5% per 30 hari |
| p95 katalog pada 100 VU | 76,84 ms | ≤ 300 ms (cadangan ±4× untuk vCPU Render yang jauh lebih kecil) |
| Error rate saat load test | 0% | < 0,5% respons 5xx |

## Bagian P11

### Tabel 1–2 (lab anggota Dimas, `app-99000003`)
k6 `uji-otel.js` (20 VU, 1 menit, 20% ke `/lambat`); waktu sisi server
dari kueri B.4 (`traces_spanmetrics_latency_sum/count`, jendela 1 menit).

| Konfigurasi | avg k6 (ms) | p95 k6 (ms) | req/s | server `GET /` (ms) | server `GET /lambat` (ms) |
|-------------|-------------|-------------|-------|---------------------|---------------------------|
| bawaan (150 worker) | 78 | 322 | 18,3 | 8,7 | 312 |
| 1 worker | 602 | 1.510 | 12,2 | 8,6 | 311 |
| 4 worker | 98 | 345 | 18,0 | 15,0 | 318 |

Satu trace `GET /lambat` di Tempo: span induk 308,20 ms; `PDO::__construct`
4,48 ms, `PDO::exec` (CREATE TABLE) 0,17 ms, `PDO::query` `SELECT
pg_sleep(0.3)` 301,37 ms, `PDO::query` count 0,78 ms → di database
(4,48 + 0,17 + 301,37 + 0,78) ÷ 308,20 = **99,5%**. Log Loki
`laporan lambat dibuat, 3 tamu` membawa `trace_id` dan membuka trace yang sama.

Hitungan B.5 (1 worker): waktu layanan = 0,8 × 8,6 + 0,2 × 311 = 69 ms →
kapasitas 1000 ÷ 69 = 14,5 req/s. Beban datang 20 ÷ (1 + 0,069) = 18,7
req/s > 14,5 → antrean tumbuh. Cek: 20 ÷ (1 + 0,602) = 12,5 req/s, cocok
dengan 12,2 terukur. Span server hampir sama di ketiga konfigurasi, tetapi
p95 k6 1 worker 1,51 s: selisihnya adalah **waktu antre menunggu worker**,
yang tidak terlihat di trace karena span baru mulai saat worker mengambil
permintaan. Perbaikannya di P13 C.1 (4 worker).

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
| Error rate 1 jam berisi 3 latihan (lab B + D.2 + 2 × G1): 2.093 gagal dari 9.969 permintaan | 0,21 → burn rate 0,21 ÷ 0,005 = **42** |
| Dengan burn rate itu budget 30 hari habis dalam | 30 × 24 ÷ 42 = **17,1 jam** |
| Ambang page | 14,4 × 0,005 = **7,2%** error rate selama 1 jam |

### Alert dan dasbor sebagai kode
Aturan alert dibuat dari nol di Grafana (P11 D.1: interval 10 s, pending
30 s, no data = Normal, Runbook URL), lalu diekspor dan disimpan di
`observability/alert-rules.yaml`; dasbor SLO di
`observability/slo-dashboard.json`. Keduanya dimuat otomatis oleh
`docker-compose.otel.yml`, jadi setiap anggota mendapat alert yang sama.
Kueri memakai `service=~"app-.+"` supaya berlaku untuk NIM siapa pun.
Ganti `PEMILIK` di `runbook_url` dengan username GitHub pemilik repo.

### Tabel 5. Bukti alert (jam WIB, dari tab History Grafana)
| Latihan | db dihentikan | Pending | Alerting (Firing) | db dinyalakan | Normal | Gangguan → Firing |
|---------|---------------|---------|-------------------|---------------|--------|-------------------|
| 1 (D.2, k6 5 menit) | 05:37:48 | 05:38:10 (A=0,296) | 05:38:40 (A=0,918) | 05:39:11 | 05:40:10 | 52 s |
| 2 (G1 ke-1, P15) | 05:43:02 | 05:43:20 (A=0,244) | 05:43:50 (A=0,791) | 05:44:16 | 05:45:20 | 48 s |
| 3 (G1 ke-2, P15) | 05:47:21 | 05:47:40 (A=0,162) | 05:48:10 (A=0,716) | 05:48:50 | 05:49:50 | 49 s |

### Tabel 7. Target vs hasil kinerja
| SLI | Target (SLO) | Hasil | Selisih | Status | Sumber |
|-----|--------------|-------|---------|--------|--------|
| p95 @100 VU | ≤ 300 ms | 76,84 ms | −223 ms | Tercapai | k6 P10 |
| p95 @20 VU, 20% `/lambat` | (informasi) | 345 ms (4 worker) | – | – | k6 + Grafana |
| Throughput @100 VU | ≥ 90 req/s | 96,5 req/s | +6,5 | Tercapai | k6 |
| Error rate 5xx (tanpa gangguan) | < 0,5% | 0% | – | Tercapai | Grafana kueri 3 |
| Availability 30 hari | ≥ 99,5% | 99,77% *(ilustrasi, jendela 36 jam)* | +0,27 | Tercapai (sementara) | uptime P10 |
| Gangguan → alert | ≤ 2 menit | 48–52 s | −68 s | Tercapai | alert D.2, G1 |
