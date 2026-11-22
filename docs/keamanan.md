# Evaluasi keamanan · Kelompok 0 · 2026-11-10 (P12), diperbarui setelah P13–P14
Alat: Trivy 0.76.0, composer 2.10.3, PHPStan 2.3.1, Semgrep 1.182.1,
actionlint 1.7.12 (semua lewat Docker, versi dikunci).

> Angka scan diulang pada 2026-11-22 dengan image versi akhir (jumlah CVE
> bergeser setiap hari mengikuti database Trivy). Butir checklist yang
> butuh akun (GitHub settings, Render, Neon) ditandai *(ilustrasi)* di repo
> contoh ini.

## 1. Ringkasan
Pemeriksaan P12 menemukan tujuh temuan; tidak ada yang Tinggi. Yang paling
penting adalah berkas di luar aplikasi yang bisa diunduh lewat URL (T-01)
dan proses Apache yang berjalan sebagai root (T-05); keduanya sudah
diperbaiki di P13 dan dibuktikan ulang dengan alat yang sama. Skor
kepatuhan naik dari 15/20 (75%) menjadi 19/20 (95%). Risiko yang tidak
bisa atau tidak layak diperbaiki sekarang (password latihan, KMS bucket,
CVE tanpa perbaikan) diterima dengan sadar di ADR 0004. Pipeline sekarang
merah bila ada dependensi rentan, temuan kode, konfigurasi HIGH/CRITICAL,
secret baru, atau CVE CRITICAL yang sudah ada perbaikannya.

## 2. Temuan (Tabel 7, format slide 24)
| ID | Gejala | Bukti | Penyebab · dampak | Tingkat | Status |
|----|--------|-------|-------------------|---------|--------|
| T-01 | `/vendor/composer/installed.json`, `/composer.lock`, `/Dockerfile` bisa diunduh; PHP di `vendor/` bisa dipanggil | loop A.2: 4 × 200 | seluruh repo + `vendor/` disalin ke folder web, `.dockerignore` hanya daftar larangan · versi paket terbaca | Sedang (K3 × D2 = 6) | **diperbaiki P13** (`.htaccess`): 4 × 403, `.github/` 404 |
| T-02 | header `Server: Apache/2.4.x (Debian)`, `X-Powered-By: PHP/8.4.x` | A.3 `curl -sI` | bawaan Debian dan php.ini · versi server terbaca | Sedang (K3 × D1 = 3) | **diperbaiki P13**: `Server: Apache`, tanpa X-Powered-By |
| T-03 | image: 166 CVE (HIGH 165, CRITICAL 1: libxml2 CVE-2026-6653, status *affected*); 40 HIGH di `linux-libc-dev` | B.2 Trivy | paket Debian di base image; `linux-libc-dev` = header kernel untuk kompilasi, tidak dijalankan · celah dikenal ikut ke produksi | Sedang (K2 × D3 = 6) | dipantau; `--ignore-unfixed`: 0 → gerbang CI lulus (ADR 0004) |
| T-04 | password `latihan` di compose (2 berkas) dan `deploy/k8s/app.yaml` | D.3 aturan tambahan: 6 temuan + 1 alarm palsu (komentar `app/index.php` baris 7) | konfigurasi latihan P7–P8 · hanya berlaku di lab | Sedang (K3 × D1 = 3) | **diterima** (ADR 0004), allow-rule di `trivy-secret.yaml` |
| T-05 | Apache induk berjalan sebagai `root`; manifest tanpa `securityContext` | A.3 `ps -eo user,comm`; Semgrep 4 temuan `deploy/k8s/app.yaml` | bawaan image dan P8 · bila ada celah, penyerang langsung root | Rendah (K1 × D2 = 2) | **diperbaiki P13**: `USER www-data`, `runAsNonRoot`, `readOnlyRootFilesystem`, drop ALL |
| T-06 | bucket `storage-file`: AWS-0090, AWS-0132, AWS-0089 | D.1 / P14 E.7 trivy config | kode P6 tanpa versioning/KMS/logging · data scan bisa hilang tertimpa | Rendah (K1 × D2 = 2) | AWS-0090 **diperbaiki P14**; 0132 dan 0089 **diterima** (ADR 0004) |
| T-07 | satu akun anggota belum 2FA *(ilustrasi)* | butir 16, Settings › Password and authentication | belum diaktifkan sejak P7 · akun bisa diambil alih dengan password saja | Sedang (K2 × D3 = 6) | **diperbaiki** 2026-11-11 |

Temuan lain yang dicatat tetapi bukan celah: PHPStan level 5 = 0 error;
level 8 = 14 error (kebanyakan nilai yang bisa `false`, mis.
`json_encode()` dan `fetch()`), dijadikan catatan perbaikan kode.

## 3. Temuan per tingkat (Tabel 8)
| Sumber | CRITICAL | HIGH | MEDIUM | LOW | Tingkat gabungan |
|--------|----------|------|--------|-----|------------------|
| Trivy image (B.2) | 1 (tanpa perbaikan) | 165 | – | – | Sedang (T-03) |
| trivy config (D.1, P14 E.7) | 0 | 1 (AWS-0132) | 1 (AWS-0090, sudah diperbaiki) | 1 (AWS-0089) | Rendah (T-06) |
| Secret (D.2–D.3) | 0 (aturan bawaan) | 6 + 1 alarm palsu (aturan tambahan) | – | – | Sedang (T-04) |
| composer audit, PHPStan 5, Semgrep (C) | 0 · 0 · 4 | – | – | – | Rendah (T-05) |
| Checklist (A, butir gagal) | – | – | – | – | 1, 4, 5, 16, 20 |

Gabungan Tabel 7: Sedang 5 (T-01, T-02, T-03, T-04, T-07), Rendah 2 (T-05, T-06).

## 4. Skor kepatuhan
| No | Butir | P12 | Setelah P13–P14 | Bukti |
|----|-------|-----|-----------------|-------|
| 1 | Berkas di luar aplikasi tidak tersaji | Gagal | Lulus | loop A.2: 403 |
| 2 | Berkas rahasia tidak tersaji | Lulus | Lulus | `/.env`, `/.git/config`, `/k8s/app.yaml` 404 |
| 3 | Pesan error tanpa detail | Lulus | Lulus | `/tidak-ada` teks singkat; `nama[]=x` 400 |
| 4 | Header tanpa nomor versi | Gagal | Lulus | `Server: Apache` |
| 5 | Proses aplikasi bukan root | Gagal | Lulus | `uid=33(www-data)` |
| 6 | Tidak ada token/kunci di repo | Lulus | Lulus | `trivy fs --scanners secret`: 0 |
| 7 | Password tertulis punya keputusan | Lulus | Lulus | ADR 0004 |
| 8 | Action dikunci SHA, permissions minimal | Lulus | Lulus | `ci.yml` |
| 9 | Base image dikunci digest | Lulus | Lulus | `FROM php:8.4-apache@sha256:3550aedd…` |
| 10 | `main` dilindungi | Lulus *(ilustrasi)* | Lulus *(ilustrasi)* | ruleset `lindungi-main` |
| 11 | Port database tidak terbuka | Lulus | Lulus | `5432/tcp` tanpa `0.0.0.0` |
| 12 | Koneksi DB produksi TLS | Lulus *(ilustrasi)* | Lulus *(ilustrasi)* | `sslmode=require` di Render *Environment* |
| 13 | HTTPS dipaksa | Lulus *(ilustrasi)* | Lulus *(ilustrasi)* | `http://` → 301 `https://` |
| 14 | Password produksi bukan "latihan" | Lulus *(ilustrasi)* | Lulus *(ilustrasi)* | password Neon acak |
| 15 | Bucket tidak publik | Lulus | Lulus | blok akses publik; tanpa AWS-0086/0087/0091/0093/0094 |
| 16 | Semua anggota 2FA GitHub | Gagal *(ilustrasi)* | Lulus *(ilustrasi)* | T-07 |
| 17 | Render dan Neon 1–2 admin + 2FA | Lulus *(ilustrasi)* | Lulus *(ilustrasi)* | Render: Raka; Neon: Salsa |
| 18 | Token GHCR P7 tidak aktif | Lulus *(ilustrasi)* | Lulus *(ilustrasi)* | *Expired* |
| 19 | trivy config `db-dev` | Lulus | Lulus | 0 temuan |
| 20 | trivy config bucket | Gagal | Gagal (2 temuan diterima) | AWS-0132, AWS-0089 |

```
P12    : lulus 15 dari 20 = 75%   gagal: 1, 4, 5, 16, 20
Target : 0,90 × 20 = 18 butir
Sekarang: lulus 19 dari 20 = 95%  gagal: 20 (risiko diterima, ADR 0004)
```

## 5. Target vs hasil (Tabel 9)
| Metrik | Target | Hasil | Selisih | Status | Sumber |
|--------|--------|-------|---------|--------|--------|
| CVE CRITICAL di image | 0 | 1 (tanpa perbaikan) | +1 | Belum, dipantau | Trivy 0.76.0 |
| CVE HIGH di image | ada rencana | 165, 0 yang sudah ada perbaikan | – | Tercapai (rencana: Dependabot digest mingguan) | Trivy 0.76.0 |
| Kepatuhan konfigurasi | ≥ 90% | 95% | +5 poin | Tercapai (setelah P13) | Tabel 4 |
| Port DB terbuka ke internet | 0 | 0 | 0 | Tercapai | butir 11 |
| Akun dengan MFA | 100% | 100% *(ilustrasi)* | 0 | Tercapai (setelah T-07) | butir 16–17 |
| Secret di repo | 0 | 0 (aturan bawaan); password latihan diterima | 0 | Tercapai dengan catatan | trivy fs |
| Dependensi rentan | 0 | 0 (35 paket di lock) | 0 | Tercapai | composer audit |
| Waktu perbaikan | CRITICAL ≤ 3 hari, HIGH ≤ 14 hari | T-01, T-02, T-05 diperbaiki 7 hari setelah ditemukan (P13) | – | Tercapai | tanggal temuan → PR |

## 6. Risiko yang diterima
[adr/0004-risiko-diterima.md](adr/0004-risiko-diterima.md): password
latihan (T-04), AWS-0132 dan AWS-0089 (T-06), CVE tanpa perbaikan (T-03).
Dipasang sebagai kode: `trivy-secret.yaml` (allow-rules) dan
`.trivyignore.yaml` (dengan alasan per temuan).

## 7. Prioritas untuk P13 (Tabel 11, satu baris per anggota)
| No | ID temuan | Kuadran | Jenis optimasi P13 | Rencana perbaikan | Ukuran sebelum (alat · angka) | Target | NIM |
|----|-----------|---------|--------------------|-------------------|-------------------------------|--------|-----|
| 1 | T-01, T-02, T-05 | Kerjakan dulu | hardening | `.htaccess`, sembunyikan versi, `USER www-data`, `securityContext` | loop A.2 · 4 × 200; `curl -sI` versi tampil; `id` root | 4 × 403; tanpa versi; uid 33 | 99000001 |
| 2 | P11 p95 `/lambat` 1 worker | Kerjakan dulu | worker/scale | jumlah worker dari hitungan B.5 | k6 `uji-otel.js` 1 worker · p95 1,51 s | p95 ≤ 0,5 s | 99000003 |
| 3 | kapasitas katalog minggu ujian | Rencanakan | cache | cache Nginx untuk `/buku` dan `/` | k6 `uji.js` 100 VU · p95 72,7 ms, `uji-maks` ±430 req/s | p95 < 10 ms | 99000004 |
| 4 | biaya + `requests` lab berlebih | Kalau sempat | rightsizing | ukuran Pod/task dari pemakaian nyata, jadwal skala | `docker stats` · 17 MiB vs requests 128Mi | requests 64Mi, biaya terjadwal | 99000002 |

Matriks prioritas (Tabel 10): **kerjakan dulu** T-01 (terpapar di Render),
T-02, T-05, worker; **rencanakan** LB + replika, cache, HPA; **kalau sempat**
rightsizing, AWS-0090; **tunda** AWS-0132 (KMS berbayar), CVE tanpa
perbaikan (menunggu Debian).

## 8. Pembaruan setelah P13–P14 (dibuktikan ulang dengan alat yang sama)
| Pemeriksaan | Sebelum | Sesudah |
|-------------|---------|---------|
| loop A.2 (`installed.json`, `autoload.php`, `composer.lock`, `Dockerfile`) | 200 × 4 | 403 × 4; `/.github/workflows/ci.yml` 404 |
| `curl -sI localhost:8080/` | versi Apache + PHP | `Server: Apache` |
| `docker compose exec app id` | `uid=0(root)` | `uid=33(www-data)` |
| Semgrep `p/default` | 4 temuan (`deploy/k8s/app.yaml`); P14: 2 temuan kunci statis di provider Terraform | 0 temuan (243 aturan, 64 berkas) |
| trivy config HIGH/CRITICAL (Dockerfile, k8s, infra) | DS-0002, KSV-0014 ×2, KSV-0118, AWS-0132 | 0 selain AWS-0132 yang diterima |
| k6 `uji.js` 100 VU setelah hardening | – | p95 76,84 ms, 0 gagal (kinerja tidak turun) |
