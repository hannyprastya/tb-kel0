# Komponen Arsitektur · Perpustakaan Kampus

## 1. Dasar
Kebutuhan: [kebutuhan.md](kebutuhan.md) · Keputusan: [keputusan.md](keputusan.md)
· Jaringan: [jaringan.md](jaringan.md) · Diagram: [arsitektur.md](arsitektur.md)

## 2. Angka yang dipakai
| Aspek                          | Nilai | Sumber |
|--------------------------------|-------|--------|
| Pengguna bersamaan saat puncak | 100 (sekarang) · 1.000 (target), 20 · 200 req/s | kebutuhan.md (P9) |
| Kapasitas per instance         | 96 req/s pada 100 VU tanpa error, p95 77 ms (1 instance, 4 worker, laptop); kapasitas maksimum tanpa jeda ±430 req/s | uji beban k6 (P10 Tabel 3, P13 C.2) |
| CPU per request                | aplikasi 4 ms (×2 untuk vCPU cloud = 8 ms), database 5 ms | P8 Bagian A, kebutuhan.md |
| Target p95 dan uptime          | p95 katalog ≤ 300 ms @100 VU; 5xx < 0,5%; availability ≥ 99,5% (target 99,9%) | SLO (P11, pengujian.md Tabel 7) |
| Worker per instance            | 4 worker Apache (p95 `/lambat` 0,31 s; 1 worker 1,55 s) | P11 Bagian B, P13 C.1 |
| Replika / HPA min–maks         | min 2, maks 6, target CPU 60% | P13 Bagian A (`k8s/hpa.yaml`) |
| Lokasi data dan regulasi       | Jakarta `ap-southeast-3`; UU PDP (data anggota) | keputusan.md (P9), ADR 0003 |

Ukuran per task: 0,5 vCPU ÷ 0,008 dtk = ±62 req/s per task (CPU-bound);
4 task = ±250 req/s ≥ 200 req/s puncak + cadangan. Di luar minggu ujian
cukup 2 task (minimum supaya satu task boleh mati).

## 3. Pola dan monolit
Pola: **container terkelola + 3-tier** (CDN/ALB → aplikasi → database)
untuk jalur pengguna, ditambah **event-driven** untuk pengingat (antrean +
worker). Aplikasi tetap **monolit rapi**: beban tiap bagian (katalog,
pinjam, buku tamu) sama ringannya (±4 ms CPU per request), yang paling dulu
habis adalah database bersama, dan tim kecil. Memecah tidak punya alasan
terukur (ADR 0007).

## 4. Komponen
| Kebutuhan | Kategori | Layanan (padanan) | Alasan | Ditolak | Lokasi |
|-----------|----------|-------------------|--------|---------|--------|
| 200 req/s puncak, aplikasi 2 vCPU | compute | ECS Fargate 4 × 0,5 vCPU / 1 GB (Azure Container Apps · Cloud Run) | image yang sama dengan P10, tanpa OS (ADR 0001) | 1 VM besar (satu titik gagal); EKS (ADR 0005) | Jakarta, 2 zona, privat-app |
| lonjakan ±5× di minggu ujian | skalabilitas | auto scaling CPU 60%, min 2 maks 6 + jadwal naik ke 4 sebelum ujian | angka HPA P13; jadwal ujian sudah diketahui | kapasitas tetap 4 task sepanjang tahun (US$38/bulan lebih mahal) | – |
| data relasional, stok tidak boleh minus, 99,9% | database | RDS PostgreSQL 17 Multi-AZ db.t4g.medium + RDS Proxy (Azure DB for PostgreSQL Flexible HA · Cloud SQL HA) | transaksi + CHECK, PITR, failover ±1–2 menit (ADR 0002) | PostgreSQL di container sendiri | Jakarta, privat-db; data pribadi (UU PDP) |
| koneksi baru tiap request memakan CPU DB | database | RDS Proxy (PgBouncer terkelola) | P8: CPU DB per request > CPU aplikasi | menaikkan ukuran DB saja (lebih mahal) | privat-app |
| 29 GB sampul dan scan, scan berisi data pribadi | storage | S3 privat + versioning + SSE-KMS (Blob Storage · Cloud Storage) | murah, tahan 3 zona, versi lama tidak hilang | disk container (hilang setiap deploy, P14 BACA-DULU) | Jakarta |
| 490 Mbps puncak, 98% dari sampul | jaringan (CDN) | CloudFront + OAC ke S3 (Front Door · Cloud CDN) | aplikasi hanya melayani HTML ±10 Mbps | sajikan sampul dari aplikasi | edge; hanya sampul, scan lewat URL bertanda |
| satu pintu HTTPS ke 2 zona | jaringan | ALB + VPC `10.0.0.0/16` (Application Gateway · Cloud Load Balancing) | health check `/health`, TLS terkelola | Nginx di VM (SPOF baru) | publik-a/b |
| pengingat ±300/hari, boleh tunda 1 jam | antrean/worker | SQS + DLQ + Lambda PHP (Service Bus + Functions · Pub/Sub + Cloud Run functions) | halaman tidak menunggu API email; retry + DLQ (ADR 0006) | kirim sinkron di request | Jakarta |
| jadwal 07.00 WIB | antrean/jadwal | EventBridge Scheduler (Functions timer · Cloud Scheduler) | tidak bergantung task yang bisa di-scale-in | cron di dalam container | Jakarta |
| password DB tidak di repo/image | keamanan | Secrets Manager + IAM role task (Key Vault · Secret Manager) | rotasi dan audit | env var polos di task definition | Jakarta |
| serangan web umum dan bot | keamanan | AWS WAF (managed rules) di CloudFront (Front Door WAF · Cloud Armor) | OWASP Top 10 dasar tanpa kode tambahan | tanpa WAF | edge |
| SLO, alert, trace | observabilitas | ADOT (OpenTelemetry) → CloudWatch + X-Ray (Azure Monitor · Cloud Monitoring) | kode OTel P11 sama, data tetap di Jakarta | Grafana Cloud Free (data di luar negeri) | Jakarta |
| deploy tanpa access key | keamanan | GitHub Actions + OIDC → IAM role deploy | kredensial sementara ±1 jam (P10 slide 14) | access key di GitHub Secrets | – |

## 5. Event-driven (Bagian C)

### 5.1 Pekerjaan lambat (Tabel 3)
| Pekerjaan | Sinkron/asinkron | Queue/pub-sub | Alasan |
|-----------|------------------|---------------|--------|
| Kirim pengingat H-1 jatuh tempo (email/WA) | asinkron | queue | API luar 1–5 s dan kadang gagal; satu pesan = satu pengingat, dikerjakan satu worker |
| Catat pinjam/kembali | sinkron | – | petugas butuh jawaban langsung (302/409) dan stok harus konsisten dalam satu transaksi |
| Membuat thumbnail sampul saat diunggah (rencana) | asinkron | queue (event S3 → antrean → fungsi) | mengolah gambar lambat; boleh tampil beberapa detik kemudian |
| Kabar "buku yang ditunggu sudah kembali" (rencana) | asinkron | pub/sub | satu event untuk beberapa penerima (anggota yang mengantre + dasbor petugas) |
| Rekap peminjaman bulanan | terjadwal | – (cron) | sekali sebulan, satu proses |

Antrean dipakai untuk pengingat (ADR 0006); kodenya `infra/antrean`
(antrean + DLQ), `serverless/` (fungsi), dan rute `POST /pengingat/kirim`.

### 5.2 Waktu sampai DLQ
- Timeout worker **T = 10 s** (API email/WA ±1–5 s, cadangan 2×).
- Visibility timeout = 6 × T = **60 s** (aturan AWS).
- maxReceiveCount = **5**: layanan WA kadang menolak sementara (rate limit)
  beberapa menit; 3 kali terlalu cepat menyerah.
- Waktu sampai DLQ ≈ 60 s × 5 = **300 s = 5 menit**. Batas tunda pengguna
  1 jam: masih jauh di bawahnya.
- Bila layanan email mati **15 menit**: setiap pesan dicoba 5 kali dalam ±5
  menit lalu masuk DLQ, jadi pengingat pukul 07.00 terparkir di DLQ. Operator
  (runbook bagian 6) menunggu layanan pulih lalu memindahkan pesan DLQ
  kembali ke antrean (`aws sqs start-message-move-task`); pengingat sampai
  ±20 menit terlambat, masih di dalam batas 1 jam. Lab P14 memakai 10 s × 3
  supaya demo cepat (±33 s).

### 5.3 Titik idempotensi
1. **Kunci:** `pinjam-<no. pinjam>-<tanggal jatuh tempo>`, dibuat aplikasi
   saat menitipkan pesan (bukan id acak di consumer). Pinjaman yang
   diperpanjang mendapat tanggal baru, jadi kunci baru.
2. **Di mana dicek:** worker memeriksa penanda `pengingat/<kunci>` di bucket
   sebelum mengirim; sudah ada → lewati (`serverless/proses.php`). Di lab
   penanda itu sekaligus "bukti terkirim". Celah kecil: dua worker yang
   memproses duplikat di detik yang sama bisa sama-sama mengirim; diterima
   (akibatnya satu pengingat dobel), bisa ditutup dengan tulis bersyarat S3
   (`If-None-Match`).
3. **Bahaya bila tidak idempoten:** setiap retry (sampai 5 kali) dan setiap
   pemicu ulang `POST /pengingat/kirim` mengirim pengingat lagi: anggota
   menerima spam, biaya WA per pesan berlipat, dan nomor pengirim bisa
   diblokir penyedia WA.

### 5.4 Jadwal
Pengingat setiap hari **07.00 WIB = 00.00 UTC** (hari yang sama):
| Layanan | 07.00 WIB setiap hari | Format |
|---------|-----------------------|--------|
| AWS EventBridge Scheduler | `cron(0 0 * * ? *)` | 6 kolom, UTC |
| Kubernetes CronJob · Cloud Scheduler | `0 0 * * *` | 5 kolom |
| Azure Functions timer | `0 0 0 * * *` | 6 kolom, detik di depan, UTC |

Jebakan yang kami temukan: rekap bulanan "tanggal 1 pukul 06.00 WIB" =
**23.00 UTC hari terakhir bulan sebelumnya**, jadi di EventBridge ditulis
`cron(0 23 L * ? *)`, bukan `cron(0 23 1 * ? *)`.

## 6. Komponen sebagai kode (Bagian E)
| Komponen | Folder infra/ | Provider | Pemilik (NIM) |
|----------|---------------|----------|---------------|
| Database dev | `infra/db-dev` | kreuzwerker/docker ~> 3.6 | Dimas Ardiansyah (99000003) |
| Object storage (sampul, scan) | `infra/storage-file` | hashicorp/aws ~> 6.0 → Floci | Nadia Kusuma (99000004) |
| Database terkelola | `infra/db-neon` | kislerdm/neon ~> 0.18.0 | Salsa Nuraini (99000002) |
| Antrean pengingat + DLQ | `infra/antrean` | hashicorp/aws ~> 6.0 → Floci | Raka Pratama (99000001) |

Hasil plan/apply/trivy per komponen: [infra/README.md](../infra/README.md).

## 7. Pertanyaan
1. **Komponen paling sulit dibalik:** database (data pindah lewat
   dump/restore dan jendela henti) dan region (ADR 0003). Keduanya sudah
   punya ADR (0002, 0003).
2. **Alasan terukur tetap monolit:** CPU aplikasi per request ±4 ms untuk
   semua rute, yang menjadi batas adalah database bersama; memecah aplikasi
   tidak mengurangi beban database, hanya menambah pipeline dan panggilan
   jaringan (ADR 0007).
