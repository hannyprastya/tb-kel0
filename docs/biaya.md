# Biaya Bulanan dan Kapan Serverless Lebih Murah (P13 D)

Sumber harga: daftar harga publik AWS (Price List API) untuk region Jakarta
`ap-southeast-3`, versi terbit 2026-11-11, diambil 2026-11-22; tarif
serverless pembanding sesuai slide 29 P13. 730 jam/bulan, tanpa diskon
komitmen, kurs asumsi Rp16.500/US$. Angka beban dari
[kebutuhan.md](kebutuhan.md) dan hasil ukur P12/P13 di aplikasi Laravel.
Paket gratis provider (Render, Neon, Supabase) dibaca dari halaman harga
masing-masing pada 2026-11 dan **bisa berubah**; cek ulang sebelum dipakai.

## 1. Biaya yang berjalan sekarang
| Komponen | Paket | Biaya | Batasan yang kami alami / baca |
|----------|-------|-------|--------------------------------|
| Render web service (web + worker + scheduler dalam satu container, `JALANKAN_WORKER=1`) | Free | US$0 | tidur setelah ±15 menit sepi (bangun ±1 menit); selama tidur worker dan scheduler ikut berhenti; 750 jam instance/bulan per workspace; 0,1 CPU, 512 MB; tanpa SLA |
| Neon PostgreSQL | Free | US$0 | 100 CU-jam/bulan per project; PITR 6 jam; tanpa SLA. **Worker Laravel menanyai tabel `jobs` tiap ±3 s**, jadi selama Render bangun Neon tidak pernah tidur (0,25 CU × 24 jam = 6 CU-jam/hari → kuota habis ±16 hari bila Render dibuat selalu bangun) |
| Supabase Storage | Free | US$0 | 1 GB berkas (sampul + scan contoh), bucket privat; project Free dijeda bila lama tidak dipakai *(cek ulang)* |
| GitHub Actions + GHCR | repo publik | US$0 | paket GHCR publik (image tanpa rahasia) |
| Uptime monitor | UptimeRobot Free | US$0 | interval 5 menit, ke `/health`; dijeda di luar masa uji (lihat Neon) |
| Grafana LGTM, Floci, Nginx | lab di codespace | US$0 | memakai kuota Codespaces (120 core-jam/bulan akun Free) |
| **Total** | | **US$0** | |

## 2. Permintaan per bulan (dari kebutuhan.md)
Kebutuhan memakai angka **puncak** (10% bersamaan) untuk ukuran server.
Untuk tagihan berbasis pemakaian dipakai rata-rata (asumsi petugas):
- Aktif per hari biasa: 15% anggota × 20 request per sesi.
  10.000 anggota → 1.500 × 20 = **30.000 request/hari**; minggu ujian ×5 =
  150.000/hari.
- Bulan dengan 2 minggu ujian (10 dari 22 hari kerja):
  12 × 30.000 + 10 × 150.000 = **1,86 juta request/bulan** (kasus terburuk).
- 1.000 anggota: sepersepuluhnya, ±0,19 juta/bulan.

## 3. Titik impas serverless vs VM (Tabel 5)
Durasi = p95 k6 `/buku` pada 100 VU di Laravel (49 ms → dibulatkan
50 ms); memori satu proses Laravel ±64 MB saat dibebani (`docker stats`),
jadi dipakai Lambda 128 MB.

| Langkah | Contoh slide 29 (512 MB, 200 ms) | Kasus kami (128 MB, 50 ms) |
|---------|----------------------------------|----------------------------|
| GB-detik per permintaan | 0,5 × 0,2 = 0,1 | 0,125 × 0,05 = 0,00625 |
| Biaya per 1 juta permintaan | 0,20 + 100.000 × 0,0000167 = US$1,87 | 0,20 + 6.250 × 0,0000167 = **US$0,30** |
| Titik impas dengan VM US$7,59 | 7,59 ÷ 1,87 ≈ 4,1 juta/bulan | 7,59 ÷ 0,30 ≈ **25 juta/bulan** |
| Rata-rata per detik (÷ 2.592.000) | ≈ 1,6 req/detik | ≈ **9,6 req/detik** |
| Beban kami (1,86 juta/bulan) | – | 1,86 × 0,30 = **US$0,56** < 7,59 |

**Jawaban:** pada beban kelompok, compute serverless jauh lebih murah dari
VM (US$0,56 vs US$7,59), karena beban rata-rata kami (±0,7 req/s) jauh di
bawah titik impas 9,6 req/s. Tetapi VM/container harus berukuran untuk
**puncak** (200 req/s), sedangkan serverless ikut beban. Mengapa tetap
tidak memilih serverless untuk aplikasi web (ADR 0001, 0004):
1. Biaya total didominasi database (RDS US$77–154/bulan); selisih compute
   hanya ±US$90/bulan, dan serverless butuh RDS Proxy (US$26) + API Gateway.
2. Laravel di Lambda butuh runtime kustom (Bref) dan cara lain menjalankan
   antrean dan scheduler; image container yang sama dengan lab hilang.
3. Serverless tanpa batas bisa meledak (slide 30): concurrency maksimum dan
   budget alert wajib dipasang dulu.

Sejak pindah ke Laravel (P12) **worker pengingat bukan lagi fungsi
Lambda**, melainkan `php artisan queue:work` dari image yang sama (satu
task kecil). Lambda + Bref P14 F tetap dicatat sebagai alternatif yang
lebih murah (US$0 di free tier) di ADR 0005.

## 4. Biaya rancangan target per komponen
Ukuran dari [kebutuhan.md](kebutuhan.md) (bagian 6: ukur ulang Laravel),
replika dari P13. Tarif aktif 24 jam (kasus terburuk). **Asumsi:** 4 task
web cukup hanya bila halaman `publik` (katalog, sampul) di-cache CDN
(±67 req/s sampai ke aplikasi); tanpa cache dibutuhkan 8 task (+US$89,97).

| Komponen | A: satu zona | B: multi-AZ | Dasar harga |
|----------|--------------|-------------|-------------|
| ECS Fargate web 4 × 0,5 vCPU / 1 GB | 89,97 | 89,97 | US$0,05056/vCPU-jam + 0,00553/GB-jam |
| ECS Fargate worker 1 × 0,25 vCPU / 0,5 GB | 11,25 | 11,25 | sama |
| ECS Fargate scheduler 1 × 0,25 vCPU / 0,5 GB | 11,25 | 11,25 | sama |
| ALB, ±1 LCU | 24,24 | 24,24 | US$0,0252/jam + 0,008/LCU-jam |
| IPv4 publik (2 node ALB + NAT) | 10,95 | 10,95 | US$0,005/jam per alamat |
| NAT gateway (1) + 10 GB | 43,66 | 43,66 | US$0,059/jam + 0,059/GB |
| RDS PostgreSQL db.t4g.medium + 20 GB gp3 | 77,22 (single) | 153,71 (Multi-AZ) | US$0,102 / 0,203 per jam; gp3 0,138 / 0,276 per GB |
| S3 30 GB | 0,75 | 0,75 | US$0,025/GB |
| CloudFront (±40 GB/bulan) | 0 | 0 | free tier 1 TB/bulan *(cek ulang)* |
| Secrets Manager (2 secret) | 0,80 | 0,80 | US$0,40/secret |
| WAF (1 ACL + 3 rule + 2 juta request) | 9,20 | 9,20 | US$5 + 1/rule + 0,60/juta |
| CloudWatch Logs 5 GB | 3,50 | 3,50 | US$0,70/GB |
| SQS (antrean job + DLQ) | 0 | 0 | di bawah free tier (±9.000 pesan/bulan) |
| Email pengingat (±6.000/bulan) | ±1,00 | ±1,00 | *(asumsi)* |
| **Total per bulan** | **US$283,77** | **US$360,26** | |

Total dihitung dari nilai tak dibulatkan (baris di atas dibulatkan ke sen).

| Varian | Total/bulan | Rupiah | Komponen termahal |
|--------|-------------|--------|-------------------|
| A · satu zona (DB tanpa standby) | US$283,77 | ±Rp4,7 juta | Fargate web 4 task (US$89,97) |
| B · multi-AZ (DB standby) | US$360,26 | ±Rp5,9 juta | RDS Multi-AZ (US$153,71, 43%) |

Dibanding rancangan P9 (buku tamu PHP: A US$261,28, B US$337,77), Laravel
menambah dua task kecil Fargate (worker + scheduler, 0,25 vCPU / 0,5 GB,
US$11,25 per task) = **+US$22,49/bulan**; worker Lambda dan EventBridge
Scheduler (US$0, free tier) tidak dipakai lagi.

## 5. Rightsizing dan penghematan (P13 C.5)
| Usulan | Dasar angka | Hemat/bulan |
|--------|-------------|-------------|
| Jadwal: 4 task web hanya di minggu ujian (8 dari 52 minggu), selebihnya 2 task | rata-rata 2,31 task, bukan 4 | **US$38,06** (Fargate web 89,97 → 51,90) |
| Satu NAT gateway, bukan dua | egress hanya untuk SQS, GHCR, API luar | **US$43,66** (sudah masuk angka di atas) |
| Gateway endpoint S3 (gratis) | berkas tidak lewat NAT (US$0,059/GB) | ±US$1–3, tergantung unggahan |
| Scheduler digabung ke task worker (satu container, supervisord seperti mode Render) | scheduler hanya bangun tiap menit | US$11,25, **tidak diambil**: worker diskalakan 1–2 sedangkan scheduler harus tepat satu |
| Lingkungan dev di AWS hanya hidup Senin–Jumat 08.00–22.00 | 70 dari 168 jam = 41,7% | 58% dari biaya dev |
| Fargate Spot untuk 2 task tambahan di minggu ujian | task web tanpa state; bukan untuk database | belum dihitung (harga Spot berubah-ubah) |
| Pod kind: web `requests` 96Mi (dulu 128Mi), worker/scheduler 64Mi (dulu 96Mi) | `docker stats`: web 30–64 MiB, worker ±31 MiB, scheduler ±29 MiB | lebih banyak Pod per node lab |

B dengan jadwal 2 ↔ 4 task: 360,26 − 38,06 = **US$322,20** (±Rp5,3 juta).

Resource menganggur yang dihapus/diatur (P13 C.5 langkah 4): image lama di
GHCR (simpan 10 tag terakhir), branch Neon sisa latihan pulih P15, berkas
uji di bucket Supabase, klaster kind dan container Floci setelah lab,
codespace yang tidak dipakai (Stop).

**Batas pengeluaran (slide 30):** sebelum auto scaling dinyalakan di AWS:
maksimum task web = 6, worker = 2, dan AWS Budgets dengan alert di 50%,
80%, 100% dari anggaran bulanan.
