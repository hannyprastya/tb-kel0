# Optimasi · Perpustakaan Kampus (P13)

Prinsip slide 5: ukur → ubah satu hal → ukur ulang dengan skenario yang
sama → bandingkan. Biaya dan titik impas serverless: [biaya.md](biaya.md).

> **Contoh hasil, diukur 2026-11-23** pada starter Laravel + modul
> perpustakaan (2.005 judul), di laptop 10 core yang sedang dipakai proses
> lain (load average 10–140, berubah-ubah selama uji). Karena itu
> perbandingan diulang bergantian (sebelum, sesudah, sebelum, sesudah) dan
> yang dipakai adalah **median**. Hasil lab HPA dan Linkerd (Bagian A–B)
> **tidak diukur di repo contoh** (tidak boleh membuat klaster di mesin
> dosen); angka di bagian itu adalah angka acuan Modul P13, ditandai
> *(acuan modul)*.

## Tabel 3. Pembagian optimasi
| Optimasi | Slide | Anggota | Skenario ukur |
|----------|-------|---------|---------------|
| C.1 Jumlah worker Apache | 8, 12 | Dimas Ardiansyah (99000003) | k6 `uji-maks.js` 20 VU 30 s ke `/api/buku/status` |
| C.2 Load balancer + replika | 6–7, 9 | Dimas Ardiansyah (99000003), lanjutan C.1 | `uji-maks.js` 50 VU 30 s ke `/api/buku/status` (tidak di-cache); `uji.js` 100 VU + 1 replika dimatikan |
| C.3 Cache Nginx (hanya halaman `publik`) | 10–11 | Nadia Kusuma (99000004) | `uji.js` 100 VU + `uji-maks.js` 50 VU ke `/buku` |
| C.4 Hardening dari prioritas P12 | 27 | Raka Pratama (99000001) | `curl -sI` + `uji.js` 100 VU `/buku` |
| C.5 Rightsizing biaya | 28 | Salsa Nuraini (99000002) | `docker stats` + harga AWS Jakarta |

## C.1 Worker Apache
Image starter sudah membaca `WORKERS` (bawaan 4); diukur ulang karena
Laravel lebih berat dari buku tamu. Skenario tanpa jeda (`uji-maks.js`,
20 VU, 30 s) ke rute JSON yang membaca database, dua putaran per nilai
(1, 2, 4, 8 lalu 8, 4, 2, 1).

| WORKERS | p95 (ms) | req/s | CPU app | MEM app | PIDS |
|---------|----------|-------|---------|---------|------|
| 1 | 310 · 194 | 101 · 117 | 69–72% | 31 MiB | 2 |
| 2 | 131 · 164 | 212 · 203 | 126–128% | 33 MiB | 3 |
| **4 (dipilih)** | **66 · 93** | **405 · 359** | 198–215% | 36 MiB | 5 |
| 8 | 88 · 89 | 394 · 402 | 320–412% | 43 MiB | 9 |

Throughput naik hampir lurus sampai 4 worker, lalu mendatar: 8 worker
memakai CPU dua kali lebih banyak tanpa tambahan req/s (bottleneck pindah
ke database dan CPU laptop bersama). Dipilih **4**: kapasitas ±360–405
req/s per instance pada laptop, RAM ±36 MiB. Dari P11 B.5: 1 worker
melayani ±101–117 req/s → waktu layanan ±9–10 ms per permintaan.

## C.2 Load balancer + 2 replika (`deploy/compose/lb.yml`, `deploy/compose/nginx.conf`)
Diukur lewat Nginx (`http://nginx`) ke `/api/buku/status` (grup `api`,
tidak pernah di-cache), 1 dan 2 replika bergantian, 5 putaran.

| Skenario | 1 replika | 2 replika |
|----------|-----------|-----------|
| `uji-maks.js` 50 VU req/s | 341 · 192 · 399 · 389 · 342 → median **342** | 281 · 170 · 498 · 490 · 469 → median **469** |
| `uji-maks.js` p95 (median) | 244 ms | 179 ms |
| Tahan gagal: replika 2 dihentikan detik ke-21 (`uji.js` 100 VU, 1 menit) | – | **0 gagal** dari 5.921; log Nginx 10 baris `connect() failed (111: Connection refused) while connecting to upstream`, permintaan dialihkan; max 2.015 ms (`proxy_connect_timeout 2s`) |

- **Amdahl:** S = 469 ÷ 342 = 1,37 → 1 ÷ S = (1 − p) + p/2 → p = 2 ×
  (1 − 0,730) = **0,54**. Bagian serial ±46%: satu database dan CPU laptop
  yang sama untuk app, db, Nginx, dan k6.
- Putaran ke-2 kedua kolom jatuh (192 dan 170) saat load average laptop
  naik ke ±140; karena itu dipakai median.
- **Jebakan yang kami temui:** Nginx membaca alamat `app` sekali saat
  start. Setelah `--scale app=2` pada stack yang sudah jalan, header
  `X-Server` tetap satu alamat sampai `docker compose restart nginx`;
  putaran pertama 2 replika (334 · 367 · 281 req/s) sebenarnya masih 1
  replika dan dibuang.

## C.3 Cache Nginx (1 replika, hanya halaman grup `publik`)
Aturan cache di `nginx.conf` starter: hanya jawaban ber-`Cache-Control:
public` (halaman grup `publik`: `/buku`, `/buku/{id}/sampul`, `/tamu`)
yang disimpan; permintaan dengan cookie sesi `tb_sesi` dilewati (BYPASS).

| Ukuran | Tanpa cache (langsung ke app) | Dengan cache Nginx |
|--------|-------------------------------|--------------------|
| `uji.js` 100 VU `/buku` p95 | 49,16 ms | **3,73 ms** |
| `uji.js` 100 VU req/s | 97,09 | 99,65 |
| `uji-maks.js` 50 VU `/buku` req/s | 216 · 233 (load ±40–60); 364 (load ±15) | **35.349** |
| `uji-maks.js` p95 | 362–367 ms (load ±40–60) | 4,68 ms |
| app saat dibebani (`docker stats`) | CPU ±247%, db ±150% (364 req/s) | app CPU 3%, Nginx 189% |
| `X-Cache-Status` 11× berturut-turut `/buku` | – | 1 MISS lalu 10 HIT |
| `/buku/kelola` dengan cookie `tb_sesi` · `/` · `/api/buku/status` | – | BYPASS · MISS · MISS (tidak pernah HIT) |

**Data basi:** entri cache baru (`MISS`) untuk `/buku?q=Pengantar`
menulis "3/3"; segera sesudahnya petugas meminjamkan satu eksemplar
(`POST /buku/pinjam` → 201). Katalog lewat Nginx baru menulis "2/3"
setelah **61 detik** (TTL 60 s). Karena itu angka di katalog bukan
kebenaran: petugas memakai jawaban `POST /buku/pinjam` (201/409), dan
`/api/buku/status`, `/health`, halaman bersesi, serta semua POST tidak
di-cache. Panel p95 Grafana (sisi server) tidak melihat HIT cache:
permintaan yang dijawab Nginx tidak pernah sampai ke Laravel.

**Kenapa hanya halaman `publik`:** halaman bersesi (`/`, `/buku/kelola`)
berisi token CSRF dan nama pengguna; menyimpannya di cache bersama berarti
menyajikan halaman satu orang ke orang lain. Uji P10 Tabel 3 menunjukkan
halaman bersesi `/` sudah mahal (p95 1,22 s pada 100 VU), dan itu tidak
bisa diselesaikan dengan cache, melainkan dengan tidak memberi sesi pada
pengunjung katalog (katalog sengaja di grup `publik`).

## C.4 Hardening (prioritas P12 baris 1, temuan T-08)
Temuan buku tamu P12 (T-01 berkas tersaji, T-02 versi di header, T-05
root) sudah tertutup oleh starter (keamanan.md). Yang tersisa di Laravel:
header HSTS dan CSP. Kelompok menambah dua baris di `web/docker/apache.conf`:
`Strict-Transport-Security: max-age=31536000` dan CSP dasar
`frame-ancestors 'self'; base-uri 'self'; object-src 'none'` (tanpa
`script-src`, supaya Vite dan Alpine tidak putus). Image P12 dan P13
dibangun dari kode yang sama kecuali dua baris itu, diukur bergantian
3 pasang.

| Pemeriksaan | Sebelum (image P12) | Sesudah (image P13) |
|-------------|---------------------|---------------------|
| `curl -sI /buku` | tanpa HSTS, tanpa CSP | `Strict-Transport-Security`, `Content-Security-Policy` ada |
| k6 `uji.js` 100 VU `/buku` p95 | 371 · 50,1 · 33,6 · 33,1 ms → median 41,6 | 600 · 18,8 · 25,9 · 36,3 ms → median 31,1 |
| k6 req/s | 93,0 · 96,1 · 97,3 · 97,2 | 87,3 · 97,0 · 97,0 · 97,2 |
| `tests/fungsional.sh` | lulus | lulus |

Kinerja tidak berubah (pasangan pertama keduanya jatuh karena laptop
sibuk; selisih median lebih kecil dari sebaran antar putaran). Manifest
kind sudah di-hardening di starter (`runAsNonRoot`, `readOnlyRootFilesystem`
+ `emptyDir`, drop ALL, seccomp): dicek `kubectl apply --dry-run=client`
dan `trivy config` (0 HIGH/CRITICAL).

## C.5 Rightsizing
| Ukuran | Sebelum | Sesudah | Dasar |
|--------|---------|---------|-------|
| `requests.memory` Pod web (kind) | 128Mi | 96Mi | `docker stats`: 30 MiB diam, 64 MiB pada 100 VU |
| `requests.memory` Pod worker, scheduler | 96Mi | 64Mi | ±29–31 MiB, hampir tidak berubah saat dibebani |
| Task web Fargate rancangan target | 4 task sepanjang tahun | 2 task, naik ke 4 hanya di minggu ujian (jadwal) + auto scaling maks 6 | rata-rata 2,31 task |
| Biaya rancangan target B | US$360,26/bulan | US$322,20/bulan (−US$38,06) | biaya.md bagian 5 |

## Tabel 4. Sebelum vs sesudah setiap optimasi (skenario sama)
| Optimasi | Metrik utama | Sebelum | Sesudah | Gagal (%) | Catatan |
|----------|--------------|---------|---------|-----------|---------|
| C.1 Worker | req/s, p95 (`uji-maks`, 20 VU) | 1 worker: 101–117 · 194–310 ms | 4 worker: 359–405 · 66–93 ms | 0 · 0 | 8 worker tidak lebih cepat |
| C.2 LB + replika | req/s maks (median), tahan gagal | 342 | 469; 0 gagal saat 1 replika mati | 0 · 0 | S = 1,37, p = 0,54 |
| C.3 Cache publik | p95 100 VU, req/s maks `/buku` | 49,16 ms · 216–364 | 3,73 ms · 35.349 | 0 · 0 | 10/11 HIT, data basi ≤ 61 s, sesi BYPASS |
| C.4 Hardening | header keamanan; p95 (median) | 0 dari 2; 41,6 ms | 2 dari 2; 31,1 ms | 0 · 0 | perbedaan = derau laptop |
| C.5 Rightsizing | US$/bulan target B | 360,26 | 322,20 | – | jadwal 2 ↔ 4 task |

## Bagian A–B · HPA dan Linkerd *(acuan modul, tidak diukur di repo contoh)*
- HPA (`deploy/k8s/50-hpa.yaml`: min 2, maks 6, CPU 60%): replika 3 → 6
  ±67 s setelah beban dimulai, turun ke 2 ±5 menit 49 s setelah beban
  hilang; `ceil(3 × 112 ÷ 60) = 6`. Database menjadi bagian serial.
- Linkerd edge-26.10.1: mTLS web → db, probe kubelet tetap plaintext; proxy
  ±5–6 MiB per Pod, control plane + viz ±1 GB di klaster. Wajib anotasi
  `proxy-cpu-request=10m` supaya HPA tetap jalan.
- Di Laravel ada dua Deployment tambahan (`worker`, `scheduler`,
  `deploy/k8s/40-latar.yaml`): HPA hanya untuk `web`; worker diskalakan
  manual (scheduler harus tepat satu).

## Keputusan produksi: tanpa Kubernetes dan service mesh
Diputuskan Dimas (2026-11-13) dari angka A–B. Sistem kami satu aplikasi
Laravel (web + worker + scheduler dari image yang sama) + satu database,
dengan tim IT 1–2 orang.
- **Keputusan:** produksi target memakai ECS Fargate dengan auto scaling
  bawaan (web min 2, maks 6 task, target CPU 60%: angka HPA lab), worker
  1–2 task, scheduler tepat 1 task, tanpa service mesh. `deploy/k8s/` tetap
  dirawat untuk lab dan sebagai exit plan.
- **Ditolak:** EKS + HPA (control plane US$73/bulan, ±20% biaya target,
  untuk tiga Deployment kecil dari satu image; upgrade klaster tiap ±4
  bulan). Linkerd/Istio (web, worker, dan scheduler tidak saling memanggil,
  jadi mTLS antar layanan tidak dibutuhkan; proxy ±5–6 MiB per Pod padahal
  worker sendiri ±30 MiB). 4 task tetap (membayar puncak sepanjang tahun,
  padahal puncak hanya ±8 dari 52 minggu, biaya.md).
- **Akibat:** hemat ±US$73/bulan dan tidak ada klaster yang harus
  di-upgrade; tidak ada mTLS antar container (ditinjau ulang bila ≥ 3
  layanan saling memanggil); Kubernetes hanya terlatih di lab.

## Tabel 6. Trade-off
| Optimasi yang diterapkan | Yang didapat | Harganya |
|--------------------------|--------------|----------|
| HPA (A) | replika ikut beban, maks 6 = batas tagihan | turun lambat (±5 menit); butuh metrics-server dan `requests` yang benar |
| Service mesh (B) | mTLS + metrik emas tanpa ubah kode | +1 proxy per Pod, control plane ±1 GB; **tidak dipakai di produksi (bagian Keputusan produksi)** |
| C.1 4 worker | kapasitas ×3,5 dari 1 worker | +3 proses (±5 MiB); worker lebih banyak = koneksi DB lebih banyak |
| C.2 LB + 2 replika | kapasitas +37%, tahan 1 replika mati | +1 komponen (Nginx) yang bisa menjadi SPOF baru; harus di-restart setelah scale; DB tetap satu |
| C.3 cache publik | p95 katalog ÷13, kapasitas ×100 lebih | katalog bisa basi ≤ 61 s; hanya untuk halaman tanpa sesi |
| C.4 HSTS + CSP | 2 header keamanan, tanpa biaya kinerja | HSTS sulit dicabut (browser mengingat 1 tahun); CSP belum membatasi `script-src` |
| C.5 rightsizing | −US$38/bulan; Pod lab lebih kecil | jadwal diperbarui tiap semester; `requests` terlalu kecil membuat HPA salah hitung |

## Pertanyaan konsep (Bagian E)
1. **Paralel:** p = 0,54 dari C.2; bagian serial = database tunggal (dan di
   lab, CPU laptop bersama). Replika ketiga paling banyak memberi
   S = 1 ÷ (0,46 + 0,54/3) = 1,56.
2. **Canary 90/10:** puncak 200 req/s = 12.000 req/menit. Bila v2 gagal 8%:
   canary 10% → 1.200 × 8% = **96 permintaan/menit** gagal; rilis langsung
   → **960/menit**. Bobot dikembalikan bila error rate 5xx v2 > 0,5% selama
   5 menit.
3. **Sidecar vs ambient:** ±6 MiB × 50 Pod = ±300 MiB untuk proxy saja;
   ambient memakai satu proxy per node (ztunnel).
4. **Perlu mesh?** Tidak untuk 1 aplikasi (3 proses) + 1 database (bagian Keputusan produksi).
5. **Batas pengeluaran:** `maxReplicas`/maksimum task 6, worker maks 2,
   AWS Budgets 50/80/100% (biaya.md bagian 5).
6. **SLO P11 dan target P12:** SLO p95, throughput, error rate tercapai
   (pengujian.md Tabel 7); kepatuhan 95% ≥ 90% setelah 2FA (keamanan.md).
   Belum: CVE tanpa perbaikan (dipantau) dan database tunggal (target:
   Multi-AZ). Optimasi berikutnya: connection pooling, dan sesi untuk
   pengunjung anonim (halaman `/` bersesi p95 1,22 s @100 VU).
