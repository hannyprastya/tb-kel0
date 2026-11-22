# Optimasi · Perpustakaan Kampus (P13)

Prinsip slide 5: ukur → ubah satu hal → ukur ulang dengan skenario yang
sama → bandingkan. Biaya dan titik impas serverless: [biaya.md](biaya.md).

> **Contoh hasil, diukur 2026-11-22** di laptop yang sedang dipakai proses
> lain (load average dicatat per putaran: 6–50). Karena itu setiap
> perbandingan diulang bergantian (sebelum, sesudah, sebelum, sesudah) dan
> yang dipakai adalah median. Hasil lab HPA dan Linkerd (Bagian A–B)
> **tidak diukur di repo contoh** (tidak boleh membuat klaster di mesin
> dosen); angka di bagian itu adalah angka acuan Modul P13, ditandai
> *(acuan modul)*.

## Tabel 3. Pembagian optimasi
| Optimasi | Slide | Anggota | Skenario ukur |
|----------|-------|---------|---------------|
| C.1 Tambah worker Apache | 8, 12 | Dimas Ardiansyah (99000003) | k6 `uji-otel.js` (20 VU, 20% `/lambat`) |
| C.2 Load balancer + replika | 6–7, 9 | Dimas Ardiansyah (99000003), lanjutan C.1 | `uji.js` 100 VU + `uji-maks.js` 50 VU 30 s |
| C.3 Cache Nginx | 10–11 | Nadia Kusuma (99000004) | `uji.js` + `uji-maks.js` |
| C.4 Hardening dari prioritas P12 | 27 | Raka Pratama (99000001) | temuan P12 + `uji.js` 100 VU |
| C.5 Rightsizing biaya | 28 | Salsa Nuraini (99000002) | `docker stats` + harga AWS Jakarta |

## C.1 Worker Apache
Dari P11 B.5: kapasitas 1 worker ±14,5 req/s, beban 18,7 req/s. Minimal
2 worker (29 req/s) agar antrean tidak tumbuh; dipilih **4** supaya worker
sibuk < 50% dan masih ada cadangan untuk `/lambat`. RAM: 4 × ±3,6 MiB per
proses jauh di bawah 512 MB Render Free.

| WORKERS | p95 | req/s | MEM app | PIDS |
|---------|-----|-------|---------|------|
| 1 | 1,55 s | 12,5 | 11,5 MiB | 2 |
| 2 | 0,57 s | 17,2 | 13,5 MiB | 3 |
| **4 (dipilih)** | **0,31 s** | **18,5** | 14,4 MiB | 5 |
| 8 | 0,32 s | 18,3 | 19,2 MiB | 9 |

Image sama, jumlah worker lewat env (`WORKERS`, `app/Dockerfile` baris akhir).
2 worker: antrean berhenti tumbuh tetapi p95 masih di atas 0,5 s; 8 worker
tidak lebih cepat (bottleneck pindah ke `pg_sleep` dan database).

## C.2 Load balancer + 2 replika (`deploy/compose/lb.yml`, `deploy/compose/nginx.conf`)
| Skenario | 1 replika | 2 replika |
|----------|-----------|-----------|
| `uji-maks.js` 50 VU, 3 putaran req/s | 431 · 491 · 220 → median **431** | 624 · 792 · 268 → median **624** |
| `uji-maks.js` p95 (median) | 197 ms | 138 ms |
| `uji.js` 100 VU req/s | 96,9 · 94,6 | 94,0 · 93,1 |
| `uji.js` 100 VU p95 | 72,7 · 168 ms | 196,9 · 52,8 ms |
| Tahan gagal: replika 2 dimatikan detik ke-20 (100 VU) | – | **0 gagal** dari 5.584; log Nginx 20 baris `Connection reset/refused ... upstream`, permintaan dialihkan |

- Dengan jeda 1 s, 100 VU belum menjenuhkan satu replika, jadi `uji.js`
  tidak menunjukkan beda; p95-nya bergeser mengikuti beban laptop
  (53–197 ms) dan **tidak bisa disimpulkan**. Manfaat LB terlihat di
  kapasitas maksimum dan uji tahan gagal.
- Putaran ke-3 kedua kolom jatuh (220 dan 268) karena laptop sedang sibuk;
  karena itu dipakai median.
- **Amdahl:** S = 624 ÷ 431 = 1,45 → 1 ÷ S = (1 − p) + p/2 → p = 2 ×
  (1 − 0,69) = **0,62**. Bagian serial ±38%: satu database dan CPU laptop
  yang sama untuk app, db, Nginx, dan k6.

## C.3 Cache Nginx (1 replika, hanya `/` dan `/buku`)
| Ukuran | Tanpa cache | Dengan cache |
|--------|-------------|--------------|
| `uji.js` 100 VU p95 | 72,7 ms | **2,77 ms** |
| `uji.js` 100 VU req/s | 96,9 | 99,6 |
| `uji-maks.js` 50 VU req/s | 431 (median C.2) | **24.409** |
| `uji-maks.js` p95 | 197 ms | 7,34 ms |
| app saat dibebani (`docker stats`) | CPU tinggi, 5 PIDS | 0% CPU, 13,6 MiB (hampir semua HIT) |
| `X-Cache-Status` 10× berturut-turut | – | 10 HIT (setelah 1 MISS pertama) |

**Data basi:** setelah pinjam buku 2, katalog yang di-cache masih menulis
"tersedia 2" dan baru berubah menjadi 1 setelah **62 detik** (TTL 1 menit).
Karena itu `/buku/status`, POST, dan `/health` tidak di-cache, dan petugas
memakai hasil `POST /buku/pinjam` (302/409), bukan angka di katalog, sebagai
kebenaran. Panel p95 Grafana (sisi server) tidak melihat HIT cache sama
sekali: permintaan yang dijawab Nginx tidak pernah sampai ke PHP.

## C.4 Hardening (prioritas P12 baris 1)
Dua image dibangun dari commit yang sama, beda hanya perubahan C.4
(`.htaccess`, header, `USER www-data`) + C.1; diukur bergantian 2 kali.

| Pemeriksaan | Sebelum (image P12) | Sesudah |
|-------------|---------------------|---------|
| loop A.2 (`composer.lock` dkk.) | 200 | 403 |
| Header | `Server: Apache/2.4.71 (Debian)`, `X-Powered-By: PHP/8.4.28` | `Server: Apache` |
| `id -u` di container | 0 (root) | 33 (www-data) |
| k6 `uji.js` 100 VU p95 | 32,7 · 38,9 ms | 41,5 · 39,4 ms |
| k6 req/s | 89,8 · 90,9 | 98,3 · 97,8 |
| k6 max | 6,92 · 6,22 s | 0,20 · 0,18 s |

Kinerja tidak turun. Yang berubah besar justru `max`: image lama memakai
KeepAlive On (bawaan), sehingga sebagian permintaan menunggu koneksi yang
"dipegang" klien lain sampai ±6 s; `KeepAlive Off` dari C.1 menghilangkan
ekor panjang itu (pertanyaan "mengapa ada yang menunggu lama?" dari P10
E.4 terjawab). Manifest kind ikut di-hardening (`runAsNonRoot`,
`readOnlyRootFilesystem` + `emptyDir`, drop ALL, seccomp): dicek dengan
`kubectl apply --dry-run=client` dan `trivy config` (0 HIGH/CRITICAL);
perilaku read-only dicoba dengan `docker run --read-only` (Apache dan
PostgreSQL tetap jalan).

## C.5 Rightsizing
| Ukuran | Sebelum | Sesudah | Dasar |
|--------|---------|---------|-------|
| `requests.memory` Pod web (kind) | 128Mi | 64Mi | pemakaian ±14–17 MiB saat dibebani |
| Task Fargate rancangan target | 4 task sepanjang tahun | 2 task, naik ke 4 hanya di minggu ujian (jadwal) + auto scaling maks 6 | rata-rata 2,31 task |
| Biaya rancangan target B | US$337,77/bulan | US$299,71/bulan (−US$38,06) | biaya.md bagian 5 |

## Tabel 4. Sebelum vs sesudah setiap optimasi (skenario sama)
| Optimasi | Metrik utama | Sebelum | Sesudah | Gagal (%) | Catatan |
|----------|--------------|---------|---------|-----------|---------|
| C.1 Worker | p95, req/s (`uji-otel.js`) | 1,55 s · 12,5 | 0,31 s · 18,5 | 0 · 0 | n = 4 |
| C.2 LB + replika | req/s maks (median), tahan gagal | 431 | 624; 0 gagal saat 1 replika mati | 0 · 0 | S = 1,45, p = 0,62 |
| C.3 Cache | p95 100 VU, req/s maks | 72,7 ms · 431 | 2,77 ms · 24.409 | 0 · 0 | rasio HIT 10/10, data basi ≤ 62 s |
| C.4 Hardening | temuan P12 terbuka; p95 | 3 (T-01, T-02, T-05); 33–39 ms | 0; 39–41 ms | 0 · 0 | max 6,9 s → 0,2 s |
| C.5 Rightsizing | US$/bulan target B | 337,77 | 299,71 | – | jadwal 2 ↔ 4 task |

## Bagian A–B · HPA dan Linkerd *(acuan modul, tidak diukur di repo contoh)*
- HPA (`deploy/k8s/hpa.yaml`: min 2, maks 6, CPU 60%): replika 3 → 6 ±67 s
  setelah beban `ab -c 10` dimulai, turun ke 2 ±5 menit 49 s setelah beban
  hilang; `ceil(3 × 112 ÷ 60) = 6`. 2 replika 110 req/s vs 6 replika 118
  req/s: database menjadi bagian serial.
- Linkerd edge-26.10.1: mTLS web → db (`tls="true"`), probe kubelet tetap
  plaintext; proxy ±5–6 MiB per Pod, control plane + viz ±1 GB di klaster.
  Wajib anotasi `proxy-cpu-request=10m` supaya HPA tetap jalan.

## Tabel 6. Trade-off
| Optimasi yang diterapkan | Yang didapat | Harganya |
|--------------------------|--------------|----------|
| HPA (A) | replika ikut beban, maks 6 = batas tagihan | turun lambat (±5 menit); butuh metrics-server dan `requests` yang benar |
| Service mesh (B) | mTLS + metrik emas tanpa ubah kode | +1 proxy per Pod, control plane ±1 GB, langkah debug lebih banyak; **tidak dipakai di produksi (ADR 0005)** |
| C.1 4 worker | p95 `/lambat` 1,55 → 0,31 s | +3 proses (±11 MiB); terlalu banyak worker menambah koneksi DB |
| C.2 LB + 2 replika | kapasitas +45%, tahan 1 replika mati | +1 komponen (Nginx) yang bisa menjadi SPOF baru; DB tetap satu |
| C.3 cache | p95 ÷26, kapasitas ×57 | data katalog bisa basi ≤ 1 menit; tidak boleh untuk halaman berisi data pribadi |
| C.4 hardening | 3 temuan tertutup, ekor latency hilang | `.htaccess` harus diberi pengecualian bila kelak ada CSS/gambar; read-only root butuh `emptyDir` |
| C.5 rightsizing | −US$38/bulan | jadwal harus diperbarui tiap semester; risiko kurang kapasitas bila jadwal ujian berubah |

## Pertanyaan konsep (Bagian E)
1. **Paralel:** p = 0,62 dari C.2; bagian serial = database tunggal (dan
   di lab, CPU laptop bersama). Menambah replika ketiga paling banyak
   memberi S = 1 ÷ (0,38 + 0,62/3) = 1,70.
2. **Canary 90/10:** puncak 200 req/s = 12.000 req/menit. Bila v2 gagal 8%:
   canary 10% → 1.200 × 8% = **96 permintaan/menit** gagal; rilis langsung
   → 12.000 × 8% = **960/menit**. Bobot dikembalikan bila kueri 3 P11
   (error rate 5xx) untuk v2 > 0,5% selama 5 menit.
3. **Sidecar vs ambient:** ±6 MiB × 50 Pod = ±300 MiB tambahan untuk proxy
   saja; ambient memakai satu proxy per node (ztunnel), jadi pada klaster
   besar RAM tidak naik per Pod.
4. **Perlu mesh?** Tidak untuk 1 aplikasi + 1 database: ADR 0005.
5. **Batas pengeluaran:** `maxReplicas`/maksimum task 6, reserved
   concurrency Lambda 5, AWS Budgets 50/80/100% (biaya.md bagian 5).
6. **SLO P11 dan target P12:** SLO p95, throughput, error rate, dan alert
   tercapai (pengujian.md Tabel 7); kepatuhan 95% ≥ 90% (keamanan.md).
   Belum: CVE CRITICAL tanpa perbaikan (dipantau) dan database tunggal
   (rancangan target: Multi-AZ). Optimasi berikutnya: connection pooling
   dan memindah `CREATE TABLE IF NOT EXISTS` keluar dari jalur request.
   Ukuran P8 ulang: `/health` (koneksi + cek tabel + `SELECT 1`) sudah
   memakan ±2,8 ms CPU database per request, dari ±3,7 ms pada `/buku`
   (±75%).
