# Komponen Arsitektur · Perpustakaan Kampus

## 1. Dasar
Kebutuhan: [kebutuhan.md](kebutuhan.md) · Keputusan: [keputusan.md](keputusan.md)
· Jaringan: [jaringan.md](jaringan.md) · Diagram: [arsitektur.md](arsitektur.md)
· Aplikasi: starter Laravel + modul perpustakaan (ADR 0004)

## 2. Angka yang dipakai
| Aspek                          | Nilai | Sumber |
|--------------------------------|-------|--------|
| Pengguna bersamaan saat puncak | 100 (sekarang) · 1.000 (target), 20 · 200 req/s | kebutuhan.md (P9) |
| Kapasitas per instance (laptop) | 97 req/s pada 100 VU tanpa error, p95 katalog 49 ms; tanpa jeda ±342–405 req/s (4 worker) | pengujian.md Tabel 3, optimasi.md C.1–C.2 |
| CPU per request (Laravel) | aplikasi 6,8 ms (×2 untuk vCPU cloud ≈ 14 ms), database 4,1 ms (dipakai 5 ms) | kebutuhan.md bagian 6 (P13) |
| RAM per proses | web 30–64 MiB, worker ±31 MiB, scheduler ±29 MiB | `docker stats` P13 C.5 |
| Target p95 dan uptime          | p95 katalog ≤ 300 ms @100 VU; 5xx < 0,5%; availability ≥ 99,5% (target 99,9%) | SLO P11, pengujian.md Tabel 7 |
| Worker Apache per instance     | 4 (1 worker: 101–117 req/s; 8 worker tidak lebih cepat) | P13 C.1 |
| Replika / HPA min–maks         | web min 2, maks 6, target CPU 60%; worker 1–2; scheduler tepat 1 | P13 Bagian A (`deploy/k8s/50-hpa.yaml`, `40-latar.yaml`) |
| Pengingat                      | ±100–300/hari (10.000 pengguna); lab: 200 dititip 0,9 s, terkirim 5,5 s oleh 1 worker | ADR 0005 |
| Lokasi data dan regulasi       | Jakarta `ap-southeast-3`; UU PDP (data anggota) | keputusan.md (P9), ADR 0003 |

Ukuran per task web: 0,5 vCPU ÷ 0,014 dtk ≈ 36 req/s (CPU-bound). Dengan
cache CDN untuk halaman `publik` ±67 req/s sampai ke aplikasi → 4 task =
±143 req/s, cadangan ±2×; tanpa cache auto scaling sampai 6 task = ±214
req/s ≥ 200. Di luar minggu ujian cukup 2 task (minimum supaya satu task
boleh mati).

## 3. Pola dan monolit
Pola: **container terkelola + 3-tier** (CDN/ALB → aplikasi → database)
untuk jalur pengguna, ditambah **event-driven** untuk pengingat (tugas
terjadwal → antrean → worker). Aplikasi tetap **monolit rapi**: satu image
Laravel dijalankan sebagai tiga proses (web, worker, scheduler) yang bisa
diskalakan sendiri-sendiri; beban per rute sama ringannya (±6,5–6,8 ms
CPU), dan yang paling dulu habis adalah database bersama.

Keputusan tetap monolit (Nadia, 2026-11-17):
- **Ditolak:** microservices (katalog, sirkulasi, notifikasi masing-masing
  satu layanan + database sendiri): tiga pipeline, panggilan antar layanan
  lewat jaringan, dan transaksi pinjam yang sekarang satu `COMMIT` menjadi
  transaksi terdistribusi. Memecah katalog menjadi layanan baca sendiri juga
  tidak perlu: cache halaman `publik` (P13 C.3) sudah menaikkan kapasitas
  baca katalog dari ±342 ke ±35.000 req/s di lab.
- **Akibat:** satu image, satu pipeline, satu tempat melihat log dan trace;
  aturan stok tetap satu transaksi (K-14 lulus). Bila satu bagian rusak,
  image baru untuk semua bagian harus di-deploy. Ditinjau ulang bila tim > 8
  orang atau satu bagian butuh skala yang berbeda jauh.

## 4. Komponen
| Kebutuhan | Kategori | Layanan (padanan) | Alasan | Ditolak | Lokasi |
|-----------|----------|-------------------|--------|---------|--------|
| 200 req/s puncak, aplikasi 2 vCPU (dengan cache publik) | compute web | ECS Fargate 4 × 0,5 vCPU / 1 GB (Azure Container Apps · Cloud Run) | image yang sama dengan CI (ADR 0001) | 1 VM besar (satu titik gagal); EKS (optimasi.md, keputusan produksi) | Jakarta, 2 zona, privat-app |
| lonjakan ±5× di minggu ujian | skalabilitas | auto scaling CPU 60%, min 2 maks 6 + jadwal naik ke 4 sebelum ujian | angka HPA P13; jadwal ujian sudah diketahui | kapasitas tetap 4 task sepanjang tahun (US$38/bulan lebih mahal) | – |
| pengingat ±300/hari, boleh tunda 1 jam | compute latar | ECS service **worker** 1–2 × 0,25 vCPU / 0,5 GB, `queue:work` (Container Apps job · Cloud Run worker) | image, model, log sama dengan web; idempotensi di tabel `notifikasi` (ADR 0005) | Lambda + Bref (runtime kustom, paket kedua) | Jakarta, privat-app |
| tugas harian 07.00 WIB, tepat sekali | jadwal | ECS service **scheduler** 1 × 0,25 vCPU / 0,5 GB, `schedule:work` + `onOneServer` (Container Apps · Cloud Run) | jadwal ada di kode (`routes/kasus-jadwal.php`), diuji PHPUnit | EventBridge Scheduler → endpoint HTTP rahasia (rute tambahan yang terbuka); cron di task web (ikut ter-scale, jalan berkali-kali) | Jakarta, privat-app |
| titip job tanpa kehilangan pesan | antrean | SQS + DLQ (`infra/antrean`, Service Bus · Pub/Sub); produksi latihan: tabel `jobs` di Neon | retry + DLQ terkelola, tidak menambah beban database | antrean di tabel database (menambah query polling tiap 3 s per worker) | Jakarta |
| data relasional, stok tidak boleh minus, 99,9% | database | RDS PostgreSQL 17 Multi-AZ db.t4g.medium + RDS Proxy (Azure DB for PostgreSQL Flexible HA · Cloud SQL HA) | transaksi + CHECK, PITR, failover ±1–2 menit (ADR 0002) | PostgreSQL di container sendiri | Jakarta, privat-db; data pribadi (UU PDP) |
| sesi login dan cache Laravel | database | tabel `sessions`/`cache` di RDS yang sama (bawaan starter) | tanpa komponen baru; sesi hanya untuk petugas dan anggota yang login | ElastiCache Redis (±US$25/bulan, satu komponen lagi) | privat-db |
| koneksi baru tiap request memakan CPU DB | database | RDS Proxy (PgBouncer terkelola) | P8/P13: CPU DB per request sebanding dengan aplikasi | menaikkan ukuran DB saja (lebih mahal) | privat-app |
| 29 GB sampul dan scan, scan berisi data pribadi | storage | S3 privat + versioning + SSE-KMS (Blob Storage · Cloud Storage); produksi latihan: **Supabase Storage** (bucket privat, API S3) | disk `s3` Laravel cukup ganti env `AWS_*`; scan hanya lewat tautan bertanda tangan 5 menit | disk container (hilang setiap deploy) | Jakarta |
| katalog + sampul = mayoritas trafik minggu ujian | jaringan (CDN) | CloudFront + OAC, cache **hanya** jawaban `Cache-Control: public` (Front Door · Cloud CDN) | P13 C.3: p95 ÷13, kapasitas ±100×; tanpa cache butuh 8 task | cache semua halaman (halaman bersesi tersaji ke orang lain) | edge |
| satu pintu HTTPS ke 2 zona | jaringan | ALB + VPC `10.0.0.0/16` (Application Gateway · Cloud Load Balancing) | health check `/health`, TLS terkelola | Nginx di VM (SPOF baru) | publik-a/b |
| password DB, `APP_KEY`, kunci storage tidak di repo/image | keamanan | Secrets Manager + IAM role task (Key Vault · Secret Manager) | rotasi dan audit | env var polos di task definition | Jakarta |
| serangan web umum, bot, banjir API | keamanan | AWS WAF (managed rules + rate-based) di CloudFront (Front Door WAF · Cloud Armor) | OWASP Top 10 dasar + pembatas laju (T-09) tanpa kode | `throttle` di aplikasi (ikut membatasi uji k6) | edge |
| SLO, alert, trace | observabilitas | ADOT (OpenTelemetry) → CloudWatch + X-Ray (Azure Monitor · Cloud Monitoring) | instrumentasi `opentelemetry-auto-laravel` P11 sama, data tetap di Jakarta | Grafana Cloud Free (data di luar negeri) | Jakarta |
| deploy tanpa access key | keamanan | GitHub Actions + OIDC → IAM role deploy | kredensial sementara ±1 jam (P10 slide 14) | access key di GitHub Secrets | – |

## 5. Event-driven (Bagian C)

### 5.1 Pekerjaan lambat (Tabel 3)
| Pekerjaan | Sinkron/asinkron | Queue/pub-sub | Alasan |
|-----------|------------------|---------------|--------|
| Kirim pengingat H-1 jatuh tempo (email/WA) | asinkron | queue | API luar 1–5 s dan kadang gagal; satu job = satu pengingat, dikerjakan satu worker |
| Catat pinjam/kembali | sinkron | – | petugas butuh jawaban langsung (201/409) dan stok harus konsisten dalam satu transaksi |
| Unggah sampul/scan | sinkron | – | ±1–5 MB langsung ke object storage, petugas menunggu konfirmasi |
| Membuat thumbnail sampul (rencana) | asinkron | queue (job setelah unggah) | mengolah gambar lambat; boleh tampil beberapa detik kemudian |
| Kabar "buku yang ditunggu sudah kembali" (rencana) | asinkron | pub/sub | satu event untuk beberapa penerima (anggota yang mengantre + dasbor petugas) |
| Rekap peminjaman bulanan | terjadwal | – (scheduler) | sekali sebulan, satu proses |

Antrean dipakai untuk pengingat (ADR 0005); kodenya
`web/app/Jobs/Kasus/KirimPengingatJatuhTempo.php`,
`web/app/Console/Commands/Kasus/PengingatJatuhTempo.php`,
`web/routes/kasus-jadwal.php`, dan `infra/antrean` (SQS + DLQ).

### 5.2 Waktu sampai gagal dan DLQ
- Timeout job **T = 10 s** (API email/WA ±1–5 s, cadangan 2×), di job
  (`$timeout = 10`).
- Visibility timeout SQS = 6 × T = **60 s** (aturan AWS).
- Percobaan: **5 kali**, jeda 1, 3, 10, 20 menit = ±34 menit (`$tries`,
  `$backoff` di job; mengalahkan `--tries=3` worker). Lalu job masuk tabel
  `failed_jobs`.
- `maxReceiveCount` = **5**: pesan masuk DLQ hanya bila worker mati
  (OOM, deploy) sebelum sempat mencatat gagal.
- Bila layanan email mati **15 menit** pukul 07.00: percobaan 1 (07.00), 2
  (07.01), 3 (07.04) gagal; percobaan 4 (07.14) atau 5 (07.34) berhasil.
  Pengingat sampai paling lambat ±34 menit terlambat, di dalam batas 1 jam,
  **tanpa operator**. Lebih dari ±34 menit → `failed_jobs`; operator
  (runbook bagian 6) menjalankan `php artisan queue:retry all` setelah
  layanan pulih (aman diulang: kunci idempotensi).
- Di lab, satu worker mengirim 200 pengingat dalam ±5,5 s; 300 pengingat
  pukul 07.00 selesai dalam ±10 s bila API email ±0 s, atau ±25 menit bila
  setiap panggilan 5 s (1 worker) → worker dinaikkan ke 2 di minggu ujian.

### 5.3 Titik idempotensi
1. **Kunci:** `pinjam-<no. pinjam>-<tanggal jatuh tempo>`, dibuat dari data
   (`Pinjam::kunciPengingat()`), bukan id acak. Pinjaman yang diperpanjang
   mendapat tanggal baru, jadi kunci baru (Feature test).
2. **Di mana dicek:** job menulis kunci ke tabel `notifikasi` (UNIQUE)
   dengan `insertOrIgnore` di transaksi yang sama dengan pengiriman; 0
   baris masuk = sudah pernah dikirim → lewati. Dua worker yang memproses
   duplikat di detik yang sama tidak bisa sama-sama menulis (UNIQUE), jadi
   celah "dua-duanya mengirim" di rancangan P14 lama (penanda berkas di
   bucket) tertutup.
3. **Bahaya bila tidak idempoten:** setiap percobaan ulang (sampai 5 kali)
   dan setiap perintah harian yang dijalankan ulang mengirim pengingat lagi:
   anggota menerima spam, biaya WA per pesan berlipat, dan nomor pengirim
   bisa diblokir penyedia WA.

### 5.4 Jadwal
Pengingat setiap hari **07.00 WIB**. Laravel scheduler membaca zona
`APP_TIMEZONE` (Asia/Jakarta), jadi kode cukup `->dailyAt('07:00')`.
Padanannya bila jadwal dipindah ke layanan cloud (UTC):
| Layanan | 07.00 WIB setiap hari | Format |
|---------|-----------------------|--------|
| Laravel scheduler (dipakai) | `dailyAt('07:00')` = `0 7 * * *` | 5 kolom, zona aplikasi |
| AWS EventBridge Scheduler | `cron(0 0 * * ? *)` | 6 kolom, UTC |
| Kubernetes CronJob · Cloud Scheduler | `0 0 * * *` | 5 kolom, UTC |
| Azure Functions timer | `0 0 0 * * *` | 6 kolom, detik di depan, UTC |

Jebakan yang kami temukan: rekap bulanan "tanggal 1 pukul 06.00 WIB" =
**23.00 UTC hari terakhir bulan sebelumnya** (EventBridge
`cron(0 23 L * ? *)`); di Laravel cukup `monthlyOn(1, '06:00')` karena
zona aplikasi WIB.

## 6. Komponen sebagai kode (Bagian E)
| Komponen | Folder infra/ | Provider | Pemilik (NIM) |
|----------|---------------|----------|---------------|
| Database dev | `infra/db-dev` | kreuzwerker/docker ~> 3.6 | Dimas Ardiansyah (99000003) |
| Object storage (sampul, scan) | `infra/storage-file` | hashicorp/aws ~> 6.0 → Floci | Nadia Kusuma (99000004) |
| Database terkelola | `infra/db-neon` | kislerdm/neon ~> 0.18.0 | Salsa Nuraini (99000002) |
| Antrean job + DLQ | `infra/antrean` | hashicorp/aws ~> 6.0 → Floci | Raka Pratama (99000001) |

Hasil plan/apply/trivy per komponen: [infra/README.md](../infra/README.md).
Bucket Supabase produksi dibuat lewat dasbor (belum ada provider resmi
yang kami uji).

## 7. Pertanyaan
1. **Komponen paling sulit dibalik:** database (data pindah lewat
   dump/restore dan jendela henti) dan region (ADR 0003). Keduanya sudah
   punya ADR (0002, 0003). Framework (ADR 0004) juga mahal dibalik, tetapi
   sudah dibayar di P12.
2. **Alasan terukur tetap monolit:** CPU aplikasi per request ±6,5–6,8 ms
   untuk katalog maupun API, yang menjadi batas adalah database bersama;
   memecah aplikasi tidak mengurangi beban database, hanya menambah
   pipeline dan panggilan jaringan (bagian 3). Yang dipisah hanya proses
   (web, worker, scheduler) dari image yang sama.
