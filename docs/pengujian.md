# Pengujian · Perpustakaan Kampus (P10 CI/CD dan pengujian)

> **Contoh hasil.** Angka uji di dokumen ini diukur pada 2026-11-22 di
> laptop (macOS, Docker/OrbStack, 10 core) yang sedang dipakai banyak proses
> lain (load average 9–40).
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
tanda awal antrean di 4 worker Apache.

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
