# Evaluasi keamanan · Kelompok 0 · 2026-11-10 (P12), diperbarui setelah P13–P14
Alat (semua lewat Docker, versi dikunci): Trivy 0.76.0, composer 2.11.2,
npm 11 (node 24), Larastan 3.15.0 (PHPStan 2.3.7), Semgrep 1.182.1,
actionlint 1.7.12.

> Pemeriksaan A (checklist + loop URL) dijalankan dua kali: pada image
> buku tamu PHP sebelum pindah (angka modul P12, diulang 2026-11-22) dan
> pada starter Laravel + modul perpustakaan sesudah pindah (ADR 0004,
> diukur 2026-11-23). Scan B–D hanya pada Laravel. Jumlah CVE bergeser
> setiap hari mengikuti database Trivy. Butir yang butuh akun (GitHub
> settings, Render, Neon, Supabase) ditandai *(ilustrasi)*.

## 1. Ringkasan
Tiga temuan terpenting di buku tamu PHP (berkas di luar aplikasi bisa
diunduh, versi server terlihat, Apache sebagai root) **tertutup oleh
perpindahan ke starter Laravel**: docroot hanya `web/public/`, header tanpa
versi, proses `www-data`. Pada aplikasi Laravel tidak ada temuan Tinggi.
Yang tersisa: CVE base image Debian (1 CRITICAL tanpa perbaikan, 75 HIGH
yang perbaikannya belum masuk image resmi), password latihan, bucket lab
tanpa KMS, header HSTS/CSP belum ada, dan API tanpa pembatas laju. Skor
kepatuhan: buku tamu 15/20 (75%) → Laravel 18/20 (90%). Risiko yang tidak
diperbaiki diterima dengan sadar (bagian 6). Pipeline merah bila ada
dependensi rentan, temuan Semgrep, konfigurasi HIGH/CRITICAL, secret baru,
error Larastan level 5, atau CVE CRITICAL yang sudah ada perbaikannya.

## 2. Temuan (Tabel 7, format slide 24)
| ID | Gejala | Bukti | Penyebab · dampak | Tingkat | Status |
|----|--------|-------|-------------------|---------|--------|
| T-01 | buku tamu: `/vendor/composer/installed.json`, `/composer.lock`, `/Dockerfile` bisa diunduh | loop A.2 image PHP: 4 × 200 | seluruh repo + `vendor/` di folder web · versi paket terbaca | Sedang (K3 × D2 = 6) | **tertutup oleh pindah** (ADR 0004): di Laravel 404 untuk `/vendor/…`, `/composer.lock`, `/composer.json`, `/Dockerfile`, `/artisan`, `/storage/logs/laravel.log`; 403 untuk `/.env`, `/.git/config`, `/.htaccess` |
| T-02 | buku tamu: `Server: Apache/2.4.x (Debian)`, `X-Powered-By: PHP/8.4.x` | A.3 `curl -sI` | bawaan Debian/php.ini | Sedang (K3 × D1 = 3) | **tertutup oleh pindah**: `Server: Apache`, tanpa `X-Powered-By` (F-04) |
| T-03 | image Laravel: CRITICAL 1 (libxml2 CVE-2026-6653, *affected*), HIGH 187, MEDIUM 711, LOW 931; 75 HIGH sudah ada perbaikannya (`linux-libc-dev` 19, `libkrb5-3`/`libk5crypto3`/`libgssapi-krb5-2`/`libkrb5support0` 14 per paket) | B.2 Trivy (Debian 13.7, 105 paket rentan) | paket Debian di base image `php:8.4-apache`; digest resmi terbaru = digest kami (dicek 2026-11-23) · celah dikenal ikut ke produksi | Sedang (K2 × D3 = 6) | dipantau; gerbang CI (CRITICAL + `ignore-unfixed`) = 0 → lulus; HIGH menunggu digest baru (bagian 6) |
| T-04 | password `latihan` di `docker-compose.yml` (3), `deploy/k8s/00-rahasia.yaml` (1), `ci.yml` (2), `web/phpunit.xml` (1) | D.3 aturan tambahan tanpa allow-rule: 7 temuan | konfigurasi lab, CI, dan uji · hanya berlaku di lab | Sedang (K3 × D1 = 3) | **diterima** (bagian 6), allow-rule `latihan` di `trivy-secret.yaml` |
| T-05 | buku tamu: Apache induk sebagai `root` | A.3 `ps -eo user,comm` | bawaan image | Rendah (K1 × D2 = 2) | **tertutup oleh pindah**: semua proses `www-data` (uid 33), kode milik root dan hanya-baca |
| T-05b | manifest: KSV-0020/0021 (LOW) × 5 workload | D.1 `trivy config` | uid/gid 33 < 10000 | Rendah (K1 × D1 = 1) | **diterima** (bagian 6): Pod sudah `runAsNonRoot`, `readOnlyRootFilesystem`, drop ALL |
| T-06 | bucket `infra/storage-file`: AWS-0132 (HIGH), AWS-0089 (LOW) | D.1 `trivy config` | bucket lab tanpa KMS dan log akses · AWS-0090 (versioning) sudah diperbaiki di starter | Rendah (K1 × D2 = 2) | **diterima** (bagian 6), `.trivyignore.yaml` |
| T-07 | satu akun anggota belum 2FA *(ilustrasi)* | butir 16 | belum diaktifkan sejak P7 · akun bisa diambil alih dengan password saja | Sedang (K2 × D3 = 6) | **diperbaiki** 2026-11-11 |
| T-08 | tanpa `Strict-Transport-Security` dan `Content-Security-Policy` | `curl -sI /`: hanya `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` | `web/docker/apache.conf` starter · browser bisa diturunkan ke http pada kunjungan pertama, tidak ada batas `base-uri`/`object-src` | Rendah (K1 × D2 = 2) | **diperbaiki P13 C.4** |
| T-09 | `/api/*` tanpa pembatas laju | 150 permintaan beruntun (10 paralel) ke `/api/buku/status`: 150 × 200 | grup `api` Laravel tanpa `throttle` · API baca bisa dibanjiri dari satu alamat | Rendah (K2 × D1 = 2) | **diterima** (bagian 6), WAF rate-based di target |
| T-10 | DS-0026 (LOW) `web/Dockerfile*` tanpa `HEALTHCHECK` | D.1 | health check ada di compose/k8s/Render, bukan di image | Rendah (K1 × D1 = 1) | **diterima** (bagian 6) |

Temuan lain yang dicatat tetapi bukan celah: Larastan level 5 = 0 error;
level 8 = 14 error, **semuanya di kode starter/Breeze** (nilai yang bisa
`null`, mis. `$request->user()`), 0 di modul perpustakaan; dicatat sebagai
catatan perbaikan untuk pemilik starter.

**Aturan secret diuji balik** (D.3): berkas sementara berisi baris YAML
`X_PASSWORD` dengan nilai acak → 1 HIGH `password-di-berkas`; berkas berisi
URL PostgreSQL dengan password acak → 1 HIGH `password-di-url-db`; kedua
berkas lalu dihapus. Allow-rule hanya meloloskan `latihan`. (Contoh nilainya
sengaja tidak ditulis di sini: dokumen ini juga di-scan CI.)

## 3. Temuan per tingkat (Tabel 8, aplikasi Laravel)
| Sumber | CRITICAL | HIGH | MEDIUM | LOW | Tingkat gabungan |
|--------|----------|------|--------|-----|------------------|
| Trivy image (B.2) | 1 (tanpa perbaikan) | 187 (75 ada perbaikan) | 711 | 931 | Sedang (T-03) |
| trivy config (D.1) | 0 | 1 (AWS-0132, diterima) | 0 | 13 (KSV-0020/0021 × 10, DS-0026 × 2, AWS-0089) | Rendah (T-05b, T-06, T-10) |
| Secret (D.2–D.3) | 0 (aturan bawaan) | 9 (aturan tambahan, allow-rule `latihan`) | – | – | Sedang (T-04) |
| composer audit (94 paket) · npm audit · Larastan 5 · Semgrep (397 aturan, 194 berkas) | 0 · 0 · 0 · 0 | – | – | – | – |
| Checklist (A, butir gagal) | – | – | – | – | 16, 20 |

Gabungan Tabel 7 (yang masih terbuka di Laravel): Sedang 3 (T-03, T-04,
T-07), Rendah 5 (T-05b, T-06, T-08, T-09, T-10).

## 4. Skor kepatuhan
| No | Butir | Buku tamu PHP | Laravel (P12) | Bukti (Laravel) |
|----|-------|---------------|---------------|-----------------|
| 1 | Berkas di luar aplikasi tidak tersaji | Gagal | Lulus | loop A.2: 404/403 |
| 2 | Berkas rahasia tidak tersaji | Lulus | Lulus | `/.env`, `/.git/config` 403; `/storage/logs/laravel.log` 404 |
| 3 | Pesan error tanpa detail | Lulus | Lulus | `/tidak-ada` 404 halaman Laravel; `nama[]=x` → 422 (Feature test) |
| 4 | Header tanpa nomor versi | Gagal | Lulus | `Server: Apache` (F-04) |
| 5 | Proses aplikasi bukan root | Gagal | Lulus | `uid=33(www-data)` |
| 6 | Tidak ada token/kunci di repo | Lulus | Lulus | `trivy fs --scanners secret`: 0; `APP_KEY` dari env |
| 7 | Password tertulis punya keputusan | Lulus | Lulus | bagian 6 |
| 8 | Action dikunci SHA, permissions minimal | Lulus | Lulus | `ci.yml` |
| 9 | Base image dikunci digest | Lulus | Lulus | `php:8.4-apache@sha256:3550aedd…`, `composer`, `node` |
| 10 | `main` dilindungi | Lulus *(ilustrasi)* | Lulus *(ilustrasi)* | ruleset `lindungi-main` |
| 11 | Port database tidak terbuka | Lulus | Lulus | `5432/tcp` tanpa `0.0.0.0` |
| 12 | Koneksi DB produksi TLS | Lulus *(ilustrasi)* | Lulus *(ilustrasi)* | `sslmode=require` di Render *Environment* |
| 13 | HTTPS dipaksa, cookie `Secure` | Lulus *(ilustrasi)* | Lulus | cookie `tb_sesi` dan `XSRF-TOKEN` diberi `secure` bila proxy meneruskan `X-Forwarded-Proto: https` (dicoba dengan curl); `http://` → `https://` oleh Render *(ilustrasi)* |
| 14 | Password produksi bukan "latihan" | Lulus *(ilustrasi)* | Lulus *(ilustrasi)* | password Neon acak, `APP_KEY` dari `key:generate --show` |
| 15 | Bucket tidak publik | Lulus | Lulus | blok akses publik (Floci); bucket Supabase *private*, unduh lewat tautan bertanda tangan |
| 16 | Semua anggota 2FA GitHub | Gagal *(ilustrasi)* | Gagal *(ilustrasi)* | T-07 |
| 17 | Render, Neon, Supabase 1–2 admin + 2FA | Lulus *(ilustrasi)* | Lulus *(ilustrasi)* | Render: Raka; Neon: Salsa; Supabase: Nadia |
| 18 | Token GHCR P7 tidak aktif | Lulus *(ilustrasi)* | Lulus *(ilustrasi)* | *Expired* |
| 19 | trivy config Dockerfile + manifest HIGH/CRITICAL = 0 | Lulus | Lulus | hanya LOW (T-05b, T-10) |
| 20 | trivy config bucket | Gagal | Gagal | AWS-0132, AWS-0089 (diterima) |

```
Buku tamu PHP : lulus 15 dari 20 = 75%   gagal: 1, 4, 5, 16, 20
Target        : 0,90 × 20 = 18 butir
Laravel (P12) : lulus 18 dari 20 = 90%   gagal: 16, 20
Setelah P13   : lulus 19 dari 20 = 95%   gagal: 20 (risiko diterima, bagian 6)
```

## 5. Target vs hasil (Tabel 9)
| Metrik | Target | Hasil | Selisih | Status | Sumber |
|--------|--------|-------|---------|--------|--------|
| CVE CRITICAL di image | 0 | 1 (tanpa perbaikan) | +1 | Belum, dipantau | Trivy 0.76.0 |
| CVE HIGH di image | ada rencana | 187, 75 di antaranya sudah ada perbaikan Debian | – | Rencana: digest baru ≤ 14 hari setelah terbit | Trivy 0.76.0 |
| Kepatuhan konfigurasi | ≥ 90% | 90% | 0 | Tercapai | Tabel 4 |
| Port DB terbuka ke internet | 0 | 0 | 0 | Tercapai | butir 11 |
| Akun dengan MFA | 100% | 75% *(ilustrasi)* | −25 poin | Belum (T-07) | butir 16–17 |
| Secret di repo | 0 | 0 (aturan bawaan); password latihan diterima | 0 | Tercapai dengan catatan | trivy fs |
| Dependensi rentan | 0 | 0 (94 paket Composer, npm 0) | 0 | Tercapai | composer/npm audit |
| Analisis statis | 0 error | Larastan 5: 0; Semgrep: 0 | 0 | Tercapai | CI |
| Waktu perbaikan | CRITICAL ≤ 3 hari, HIGH ≤ 14 hari | T-01, T-02, T-05 tertutup 0 hari setelah ditemukan (ikut PR pindah) | – | Tercapai | tanggal temuan → PR |

## 6. Risiko yang diterima
Diputuskan Raka (2026-11-10) dari temuan bagian 2; ditinjau ulang setiap
awal semester, atau saat komponen pindah ke AWS.

| Temuan | Keputusan | Alternatif yang ditolak | Akibat yang diterima |
|--------|-----------|-------------------------|----------------------|
| T-04 password `latihan` (compose, `deploy/k8s/00-rahasia.yaml`, `ci.yml`, `web/phpunit.xml`) | hanya untuk database lab yang port-nya tidak dibuka; produksi memakai password Neon acak dan `APP_KEY` dari Render *Environment*; `allow-rules` di `trivy-secret.yaml` **hanya** cocok dengan kata itu (password lain tetap membuat CI merah, bagian 2) | pindah ke `.env`: compose tidak jalan lagi tanpa langkah tambahan di setiap codespace dan di CI, padahal password itu tertulis di modul | bila kata itu dipakai ulang di akun lain, risikonya nyata; aturan kelompok melarangnya |
| T-03 1 CRITICAL tanpa perbaikan, 75 HIGH menunggu digest resmi | CI merah hanya untuk CRITICAL yang sudah ada perbaikannya (`ignore-unfixed`); HIGH ditunggu lewat Dependabot `docker` mingguan, tenggat 14 hari sejak image resmi diperbarui | `apt-get upgrade` di Dockerfile: build tidak bisa diulang persis dan menyimpang dari starter | image produksi membawa CVE yang perbaikannya belum masuk image resmi |
| T-06 AWS-0132 (HIGH, tanpa KMS), AWS-0089 (LOW, tanpa log akses) | diterima untuk bucket lab Floci tanpa data pribadi, alasannya di `.trivyignore.yaml`; produksi = bucket privat Supabase, target SSE-KMS + log akses | – | bucket lab tanpa kunci enkripsi sendiri |
| T-05b KSV-0020/0021, T-10 DS-0026 (LOW) | diterima: uid 33 (`www-data`) bawaan `php:apache`; health check ada di compose, probe Kubernetes, dan Render | uid ≥ 10000: image turunan sendiri hanya untuk temuan LOW, padahal Pod sudah `runAsNonRoot`, `readOnlyRootFilesystem`, drop ALL | – |
| T-09 `/api/*` tanpa pembatas laju | pembatas di tepi jaringan (rate-based rule AWS WAF di rancangan target) | `throttle` Laravel: k6 dan uji serentak K-14 dari satu alamat ikut tertolak | sampai WAF ada, API baca bisa dibanjiri; Render Free tidak menagih per permintaan |

Dipasang sebagai kode: `trivy-secret.yaml` (allow-rules) dan
`.trivyignore.yaml` (alasan per temuan).

## 7. Prioritas untuk P13 (Tabel 11, satu baris per anggota)
| No | ID temuan | Kuadran | Jenis optimasi P13 | Rencana perbaikan | Ukuran sebelum (alat · angka) | Target | NIM |
|----|-----------|---------|--------------------|-------------------|-------------------------------|--------|-----|
| 1 | T-08, T-07 | Kerjakan dulu | hardening | HSTS + CSP dasar di `web/docker/apache.conf`; 2FA anggota | `curl -sI` · 0 dari 2 header | 2 header ada, k6 tidak turun | 99000001 |
| 2 | kapasitas per instance | Kerjakan dulu | worker/scale | jumlah worker Apache dari ukur | k6 `uji-maks.js` 20 VU ke `/api/buku/status` | pilih worker terkecil sebelum req/s mendatar | 99000003 |
| 3 | katalog minggu ujian | Rencanakan | cache | cache Nginx **hanya** halaman `publik` (`/buku`, sampul) | k6 `uji.js` 100 VU `/buku` · p95 49,2 ms; `uji-maks` ±216–364 req/s | p95 < 10 ms, sesi tidak pernah di-cache | 99000004 |
| 4 | biaya + `requests` lab | Kalau sempat | rightsizing | ukuran Pod/task dari pemakaian nyata, jadwal skala | `docker stats` · app 30–64 MiB vs requests 128Mi | biaya target terjadwal | 99000002 |

Matriks prioritas (Tabel 10): **kerjakan dulu** T-08 (Render publik),
T-07, worker; **rencanakan** LB + replika, cache, HPA; **kalau sempat**
rightsizing; **tunda** AWS-0132 (KMS berbayar), CVE tanpa perbaikan
(menunggu Debian), T-09 (menunggu WAF).

## 8. Pembaruan setelah P13–P14 (dibuktikan ulang dengan alat yang sama)
| Pemeriksaan | Sebelum | Sesudah |
|-------------|---------|---------|
| `curl -sI localhost:8080/buku` (T-08) | 3 header keamanan | + `Strict-Transport-Security: max-age=31536000`, `Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; object-src 'none'` |
| butir 16, 2FA (T-07) *(ilustrasi)* | 3 dari 4 anggota | 4 dari 4 (2026-11-11) |
| k6 `uji.js` 100 VU `/buku` setelah hardening | p95 median 41,6 ms | 31,1 ms (kinerja tidak turun, [optimasi.md](optimasi.md) C.4) |
| `requests` Pod (`trivy config`, `kubectl --dry-run`) | web 128Mi, worker/scheduler 96Mi | 96Mi, 64Mi; HIGH/CRITICAL tetap 0 |
| Semgrep `p/default`, `trivy fs` secret, Larastan 5 | 0 · 0 · 0 | 0 · 0 · 0 |
| `trivy config` per folder `infra/` (P14 E.7) | – | db-dev 0, db-neon 0, antrean 0 (SSE aktif), storage-file AWS-0132 + AWS-0089 (diterima, T-06) |
| Modul baru P14 (job, perintah, API `/api/buku/pinjam/{id}`) | – | Larastan 5: 0; jawaban API tanpa nomor anggota (Feature test `assertJsonMissingPath`) |

CSP sengaja belum membatasi `script-src`: Alpine.js (Breeze) memakai
`eval` untuk atribut `x-data`; membatasi skrip butuh build Alpine khusus
CSP. Dicatat sebagai rencana, bukan risiko yang diterima.
