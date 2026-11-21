# Biaya Bulanan dan Kapan Serverless Lebih Murah (P13 D)

Sumber harga: daftar harga publik AWS (Price List API) untuk region Jakarta
`ap-southeast-3`, versi terbit 2026-11-11, diambil 2026-11-22; tarif
serverless pembanding sesuai slide 29 P13. 730 jam/bulan, tanpa diskon
komitmen, kurs asumsi Rp16.500/US$. Angka beban dari
[kebutuhan.md](kebutuhan.md) dan hasil ukur P10/P13.

## 1. Biaya yang berjalan sekarang
| Komponen | Paket | Biaya | Batasan yang kami alami / baca |
|----------|-------|-------|--------------------------------|
| Render web service | Free | US$0 | tidur setelah 15 menit sepi (bangun ±1 menit); 750 jam instance/bulan per workspace; 0,1 CPU, 512 MB; tanpa SLA |
| Neon PostgreSQL | Free | US$0 | 100 CU-jam/bulan per project (habis ±16 hari bila monitor membangunkan terus); PITR 6 jam; tanpa SLA |
| GitHub Actions + GHCR | repo publik | US$0 | paket GHCR publik (image tanpa rahasia, `.dockerignore`) |
| Uptime monitor | UptimeRobot Free | US$0 | interval 5 menit, dijeda di luar masa uji |
| Grafana LGTM, Floci | lab di codespace | US$0 | memakai kuota Codespaces (120 core-jam/bulan akun Free) |
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
Durasi = median k6 P10 pada 100 VU (14,56 ms → 15 ms); memori aplikasi
±17 MB (`docker stats`), jadi dipakai memori Lambda terkecil 128 MB.

| Langkah | Contoh slide 29 (512 MB, 200 ms) | Kasus kami (128 MB, 15 ms) |
|---------|----------------------------------|----------------------------|
| GB-detik per permintaan | 0,5 × 0,2 = 0,1 | 0,125 × 0,015 = 0,001875 |
| Biaya per 1 juta permintaan | 0,20 + 100.000 × 0,0000167 = US$1,87 | 0,20 + 1.875 × 0,0000167 = **US$0,23** |
| Titik impas dengan VM US$7,59 | 7,59 ÷ 1,87 ≈ 4,1 juta/bulan | 7,59 ÷ 0,23 ≈ **33 juta/bulan** |
| Rata-rata per detik (÷ 2.592.000) | ≈ 1,6 req/detik | ≈ **12,7 req/detik** |
| Beban kami (1,86 juta/bulan) | – | 1,86 × 0,23 = **US$0,43** < 7,59 |

**Jawaban:** pada beban kelompok, compute serverless jauh lebih murah dari VM
(US$0,43 vs US$7,59), karena beban rata-rata kami (±0,7 req/s) jauh di
bawah titik impas 12,7 req/s. Tetapi VM harus berukuran untuk **puncak**
(200 req/s), sedangkan serverless ikut beban. Mengapa tetap tidak memilih
serverless untuk aplikasi web (ADR 0001):
1. Biaya total didominasi database (RDS US$77–154/bulan); selisih compute
   hanya ±US$90/bulan, dan serverless butuh RDS Proxy (US$26) + API Gateway.
2. PHP di Lambda butuh runtime kustom (Bref) dan aplikasi dipecah menjadi
   fungsi; tim 1–2 orang belum menguasainya untuk aplikasi utama.
3. Serverless tanpa batas bisa meledak (slide 30): concurrency maksimum dan
   budget alert wajib dipasang dulu.

Serverless tetap dipakai di tempat yang cocok: **worker pengingat** (±300
pesan/hari, masuk free tier Lambda dan SQS, US$0).

## 4. Biaya rancangan target per komponen
Ringkas dari [evaluasi.md](evaluasi.md) bagian 5 (rincian dan sumber di
sana):

| Varian | Total/bulan | Rupiah | Komponen termahal |
|--------|-------------|--------|-------------------|
| A · satu zona (DB tanpa standby) | US$261,28 | ±Rp4,3 juta | Fargate 4 task (US$89,97) |
| B · multi-AZ (DB standby) | US$337,77 | ±Rp5,6 juta | RDS Multi-AZ (US$153,71, 46%) |

## 5. Rightsizing dan penghematan (P13 C.5)
| Usulan | Dasar angka | Hemat/bulan |
|--------|-------------|-------------|
| Jadwal: 4 task hanya di minggu ujian (8 dari 52 minggu), selebihnya 2 task | rata-rata 2,31 task, bukan 4 | **US$38,06** (Fargate 89,97 → 51,90) |
| Satu NAT gateway, bukan dua | egress hanya untuk SQS, GHCR, API luar | **US$43,66** (sudah masuk angka di atas) |
| Gateway endpoint S3 (gratis) | berkas tidak lewat NAT (US$0,059/GB) | ±US$1–3, tergantung unggahan |
| Lingkungan dev di AWS hanya hidup Senin–Jumat 08.00–22.00 | 70 dari 168 jam = 41,7% | 58% dari biaya dev |
| Fargate Spot untuk 2 task tambahan di minggu ujian | task web tanpa state; bukan untuk database | belum dihitung (harga Spot berubah-ubah) |
| Pod web di kind: `requests` 64Mi (P8 128Mi) | `docker stats`/`kubectl top`: ±17 MiB saat dibebani | lebih banyak Pod per node lab |

Resource menganggur yang dihapus/diatur (P13 C.5 langkah 4): image lama di
GHCR (simpan 10 tag terakhir), branch Neon sisa latihan pulih P15, klaster
kind dan container Floci setelah lab, codespace yang tidak dipakai (Stop).

**Batas pengeluaran (slide 30):** sebelum auto scaling atau Lambda
dinyalakan di AWS: `maxReplicas`/maksimum task = 6, *reserved concurrency*
Lambda = 5, dan AWS Budgets dengan alert di 50%, 80%, 100% dari anggaran
bulanan.
