# Keputusan Solusi · Perpustakaan Kampus (Kasus 0)

Dasar: [kebutuhan.md](kebutuhan.md). Matriks dikerjakan di spreadsheet
bersama (kolom A kriteria, B bobot, C–F skor; `=SUMPRODUCT($B$2:$B$8;C2:C8)/100`),
lalu disalin ke sini.

Keputusan ini untuk **rancangan target 10.000 pengguna** (organisasi dalam
kasus). Lingkungan latihan Tugas Besar sekarang (Render + Neon, P10) hanya
memakai data contoh; bedanya ditulis di ADR 0003.

## 1. Cek regulasi (syarat wajib dulu)
| Pertanyaan           | Kasus kami | Regulasi → perlakuan |
|----------------------|------------|----------------------|
| Ada data pribadi?    | ya: no. anggota, nama dan email anggota (untuk pengingat), riwayat pinjam | UU PDP 27/2022 + PP 33/2026 → **bobot kepatuhan** (dasar pemrosesan, minimasi data, pemberitahuan kegagalan 3 × 24 jam) |
| Instansi pemerintah? | tidak (kampus swasta) | PP 71/2019 Pasal 21: lingkup privat boleh di luar negeri asal pengawasan efektif → bukan syarat wajib. **Bila kampus PTN: publik → region Indonesia wajib** |
| Kesehatan atau bank? | tidak | Permenkes / POJK → tidak berlaku |
| Dibuka online?       | ya | daftar PSE lingkup privat (Permenkominfo 5/2020 jo. 10/2021) → langkah wajib sebelum dibuka |

Tidak ada alternatif yang gugur karena aturan mutlak. Namun riwayat pinjam
termasuk data perilaku anggota, dan kampus ingin data tetap di Indonesia
supaya audit mudah dan latensi rendah; ini masuk kriteria kepatuhan dan
pemilihan region (ADR 0003).

## 2. Alternatif
Ukuran dari tabel kebutuhan (10.000 pengguna): aplikasi 2 vCPU total, DB 2
vCPU, 2 zona. Harga eceran AWS region Jakarta (`ap-southeast-3`) dari
daftar harga publik AWS (Price List API, terbit 2026-11-11, diambil
2026-11-22), 730 jam/bulan, tanpa diskon.

| Alternatif | Layanan + padanan (2 provider) | Biaya/bulan | Lolos? |
|------------|--------------------------------|-------------|--------|
| ALT 1 · VM (IaaS) | 2 × EC2 t4g.medium + ALB + RDS PostgreSQL (Azure VM + App Gateway · Compute Engine + Cloud LB) | ±US$314 | ya |
| ALT 2 · Container terkelola | ECS Fargate 4 × 0,5 vCPU + ALB + RDS PostgreSQL (Azure Container Apps · Cloud Run) | ±US$338 | ya |
| ALT 3 · Kubernetes terkelola | EKS + 2 node t4g.medium + RDS PostgreSQL (AKS · GKE) | ±US$387 | ya |
| ALT 4 · Serverless | Lambda (PHP lewat Bref) + API Gateway + RDS Proxy + RDS (Azure Functions · Cloud Run functions) | ±US$245 | ya |

Semua alternatif memakai RDS PostgreSQL Multi-AZ (±US$154), jadi selisih
biaya hanya dari lapisan aplikasi. Biaya ALT 4 paling kecil karena beban
rata-rata rendah (±1,9 juta request/bulan: ±1.500 anggota aktif × 20
request per hari, ×5 di minggu ujian) dan sebagian besar masuk free tier
Lambda.

## 3. Kriteria dan bobot (total 100)
| Kriteria | Bobot | Alasan |
|----------|-------|--------|
| Biaya | 25 | anggaran TI perpustakaan kecil (asumsi ±Rp6 juta/bulan untuk semua layanan) |
| Keahlian tim | 20 | tim IT perpustakaan 1–2 orang; yang dikuasai: Docker, compose, Render (P7–P10) |
| Keandalan & skalabilitas | 20 | minggu ujian ±5× beban biasa; target 99,9% |
| Kepatuhan | 15 | UU PDP, data anggota tetap di Indonesia, mudah diaudit |
| Keamanan | 10 | permukaan serangan dan patch yang harus diurus sendiri |
| Waktu rilis | 5 | perpustakaan ingin pindah sebelum semester depan, tetapi tidak mendesak |
| Kontrol | 5 | tidak ada kebutuhan khusus OS/jaringan |

Bobot tertinggi: **biaya**, karena perpustakaan dibiayai dari anggaran unit
dan setiap rupiah di infrastruktur mengurangi anggaran koleksi buku.

## 4. Matriks keputusan (skor 1–5)
| Kriteria    | Bobot | ALT 1 VM | ALT 2 Container | ALT 3 K8s | ALT 4 Serverless |
|-------------|-------|----------|-----------------|-----------|------------------|
| Biaya                    | 25 | 4 | 4 | 2 | 5 |
| Keahlian tim             | 20 | 3 | 4 | 2 | 2 |
| Keandalan & skalabilitas | 20 | 3 | 4 | 5 | 4 |
| Kepatuhan                | 15 | 4 | 4 | 4 | 4 |
| Keamanan                 | 10 | 2 | 4 | 3 | 4 |
| Waktu rilis              | 5  | 3 | 5 | 3 | 2 |
| Kontrol                  | 5  | 5 | 3 | 4 | 2 |
| **Nilai total**          | 100 | **3,40** | **4,00** | **3,15** | **3,65** |

Alasan skor:
- **Biaya:** ALT 4 termurah (US$245); ALT 1 dan ALT 2 selisih ±7%
  (US$314 vs 338), sama-sama 4; ALT 3 menambah control plane EKS US$73
  dan node yang menganggur di luar minggu ujian.
- **Keahlian:** ALT 2 memakai image Docker yang sama dengan P7–P10; ALT 1
  menuntut patch OS dan konfigurasi Apache di VM; ALT 3 baru dicoba di kind
  (P8, P13); ALT 4 menuntut runtime kustom PHP (Bref, P14 F) dan menulis
  ulang aplikasi menjadi fungsi.
- **Keandalan & skalabilitas:** ALT 3 paling lengkap (HPA, self-healing);
  ALT 2 punya auto scaling per task dan health check; ALT 4 menskalakan
  sendiri tetapi rawan "badai koneksi" ke database (perlu RDS Proxy) dan
  cold start; ALT 1 lambat menambah VM.
- **Kepatuhan:** semua alternatif tersedia di region Jakarta, jadi skornya
  sama. Kriteria ini tidak membedakan alternatif; ia menentukan region
  (ADR 0003).
- **Keamanan:** ALT 1 paling banyak yang diurus sendiri (OS, SSH, patch);
  ALT 3 permukaan konfigurasinya besar (RBAC, network policy, add-on).
- **Waktu rilis:** ALT 2 tinggal memakai image dari pipeline P10.
- **Kontrol:** ALT 1 penuh; ALT 4 paling terbatas (batas waktu, ukuran
  paket, runtime).

## 5. Uji kepekaan
Bobot biaya digeser **+10 poin** (25 → 35; keahlian dan keandalan masing-
masing −5): ALT 2 = 4,00 dan ALT 4 = 3,85. Pemenang tetap, selisih
menyempit dari 0,35 menjadi 0,15.

Bobot biaya digeser **−10 poin** (25 → 15; keahlian dan keandalan masing-
masing +5): ALT 2 = 4,00, ALT 4 = 3,45, ALT 1 dan ALT 3 = 3,30. Pemenang
tetap.

Setiap 1 poin bobot yang dipindah ke biaya (diambil sama rata dari keahlian
dan keandalan) mengurangi selisih ALT 2 − ALT 4 sebesar 0,02. Pemenang baru
berganti bila bobot biaya ≥ 43 (geser ±18 poin). Artinya keputusan cukup
kokoh; risiko yang tersisa: bila anggaran dipotong besar, serverless
menjadi pilihan yang lebih masuk akal (dicatat di ADR 0001).

## 6. Keputusan
- **Alternatif:** ALT 2, container terkelola (ECS Fargate di belakang ALB).
  Alasan: (1) image yang sama dengan yang sudah dites di pipeline P10, tanpa
  menulis ulang aplikasi; (2) auto scaling per task cukup untuk lonjakan
  minggu ujian tanpa mengurus klaster; (3) tidak ada OS yang harus di-patch
  tim yang hanya 1–2 orang.
- **Model deployment:** public cloud.
- **Provider & region:** AWS, Asia Pacific (Jakarta) — **kode region
  `ap-southeast-3`**, 2 zona ketersediaan. Alasan di ADR 0003 (data di
  Indonesia, latensi, kode Terraform P14 sudah memakai provider dan region
  ini).
- **Sekarang (lingkungan latihan):** Render (container dari GHCR) + Neon
  (PostgreSQL terkelola) di Singapura, paket gratis, hanya data contoh.
  Container yang sama, jadi pindah ke Fargate tidak mengubah aplikasi.
- **Risiko lock-in & exit plan:** task definition ECS, ALB, dan IAM khas
  AWS. Exit plan: aplikasi tetap satu image OCI standar yang membaca
  `DATABASE_URL` dari environment (sudah jalan di compose, kind, Render);
  database PostgreSQL standar (`pg_dump`/`pg_restore`, P15 B.3); infrastruktur
  di Terraform per komponen. Pindah ke Azure Container Apps atau Cloud Run
  cukup menulis ulang `infra/`, bukan aplikasi.
- **Ditinjau ulang bila:** pengguna aktif naik di atas 10.000 atau beban
  puncak melewati 400 req/s; anggaran dipotong lebih dari 40% (serverless
  menang di uji kepekaan); kampus berubah status menjadi PTN (region
  Indonesia menjadi syarat wajib, sudah terpenuhi).
