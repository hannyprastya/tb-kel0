# Evaluasi Arsitektur · Perpustakaan Kampus (P15)

Dasar: [arsitektur.md](arsitektur.md) (Diagram 1 = berjalan, Diagram 2 =
target), [kebutuhan.md](kebutuhan.md), SLO di [pengujian.md](pengujian.md).
Aplikasi: starter Laravel + modul perpustakaan (ADR 0004). Angka latihan
(deteksi, pulih, restore, restart) diukur 2026-11-23; angka bertanda
*(ilustrasi)* atau *(asumsi)* tidak diukur di repo contoh ini.

## 1. SPOF (Diagram 1, sistem yang berjalan)
| Komponen | Kalau mati | SPOF? | Mitigasi (sudah / rencana) + dampak biaya |
|----------|------------|-------|-------------------------------------------|
| web + worker + scheduler, 1 container Render Free (`JALANKAN_WORKER=1`) | semua halaman mati sampai container baru hidup (lab: restart 1,8 s; Render ±48 s *(ilustrasi)*); "tidur" setelah ±15 menit sepi, **worker dan scheduler ikut tidur** (pengingat 07.00 bisa terlewat 1 hari, runbook bagian 7) | ya | rencana: ≥ 2 instance berbayar atau target ECS web 2–6 task di 2 zona + worker/scheduler terpisah (+US$112/bulan) |
| PostgreSQL Neon Free (data, sesi, antrean job) | semua rute DB: 503 ramah (stop ±0,02 s, macet 3,0 s); login dan antrean job ikut berhenti; `/healthz` tetap 200 | ya | sudah: timeout 3 s + 503 ramah di semua rute (postmortem.md), PITR 6 jam; rencana: RDS Multi-AZ (+US$76/bulan dibanding single) |
| Supabase Storage Free | katalog, pinjam, kembali tetap jalan; sampul tidak tampil, unggah dan unduh scan gagal (lab, Floci dihentikan: `/buku` 200, `/api/buku/status` 200, sampul 500 dalam 0,34 s) | sebagian | rencana: S3 di region yang sama + versioning; salinan mingguan (ADR 0006) |
| GHCR | pengguna tidak terdampak; deploy versi baru gagal | untuk pemulihan | sudah: image yang berjalan tetap di Render; rencana: runbook menyebut "tunda deploy" |
| GitHub Actions | pengguna tidak terdampak; tidak ada build/deploy | tidak | deploy manual dari Render (*Deploy an existing image*) |
| Uptime monitor | gangguan tidak terdeteksi dari luar | untuk deteksi | sudah: alert Grafana di lab; rencana: interval 1 menit saat demo (postmortem.md) |
| Salinan backup harian (rencana) | belum ada: bila project Neon terhapus, data di luar 6 jam PITR hilang | ya (data) | rencana: `pg_dump` harian + salinan bucket mingguan (ADR 0006), US$0 |
| **Satu orang pemegang akun Render, Neon, Supabase** (non-teknis) | tidak ada yang bisa restart/restore saat ia tidak ada | ya | rencana: satu admin tambahan per akun (US$0) |
| Region Singapura (Render + Neon + Supabase) | semua mati bila region bermasalah | ya | target: Jakarta 2 zona; lintas region tidak dipilih (ADR 0006) |

## 2. Ketersediaan
Contoh slide 13 dihitung ulang: seri web 99,9% × DB 99,9% = 0,998001 ≈
**99,8%** → 0,2% × 8.760 = 17,5 jam/tahun. Dua web paralel: 1 − 0,001² =
99,9999%, seri dengan DB HA 99,99%: 0,999999 × 0,9999 = 0,999899 ≈
**99,99%** → 0,88 jam ≈ 53 menit/tahun.

**Berjalan** (yang dilewati satu permintaan pinjam/katalog: Render web →
Neon; Supabase hanya untuk sampul/scan, GHCR dan monitor tidak). Paket gratis tanpa SLA, jadi dipakai asumsi dari uptime
terukur P10 *(ilustrasi 99,77% dalam 36 jam)*, dibulatkan ke bawah:
web 99,5% × database 99,5% = **99,0%** → 1% × 8.760 = **87,6 jam/tahun**.
Di bawah target 99,5% (43,8 jam): wajar untuk paket gratis, dan itulah
alasan rancangan target.

**Target** (SLA resmi AWS, dicek dari halaman SLA tiap layanan, *perlu
dicek ulang sebelum dipakai*): CloudFront 99,9%, ALB 99,99%, ECS/Fargate
multi-zona 99,99% (satu zona diasumsikan 99,9%), RDS Proxy 99,99%, RDS
Single-AZ 99,5%, RDS Multi-AZ 99,95%.

```
A satu zona : 0,999 × 0,9999 × 0,999 × 0,9999 × 0,995  = 0,99281 ≈ 99,28%
              downtime 0,72% × 8.760 = 63 jam/tahun
B multi-AZ  : 0,999 × 0,9999 × 0,9999 × 0,9999 × 0,9995 = 0,99820 ≈ 99,82%
              downtime 0,18% × 8.760 = 15,8 jam/tahun
B tanpa CDN di jalur halaman: 0,9999 × 0,9999 × 0,9999 × 0,9995 = 99,92%
```

Target P9 untuk 10.000 pengguna 99,9% (8,8 jam/tahun) **tidak terbukti
dari SLA** pada varian B karena CloudFront (SLA 99,9%) ikut di jalur seri.
SLA adalah janji kompensasi, bukan ketersediaan nyata, tetapi kami tidak
mau menutupi selisih ini. Dua pilihan yang ditimbang:
1. WAF dipasang di ALB dan CloudFront hanya untuk sampul → 99,92% (memenuhi
   target di atas kertas, tetapi katalog kehilangan cache CDN).
2. CloudFront tetap di depan semua halaman **dengan `stale-if-error`**:
   bila origin gagal, katalog lama tetap tampil (graceful degradation);
   pinjam/kembali tetap butuh database.
Dipilih **pilihan 2** untuk sekarang (manfaat cache P13 C.3 besar), dengan
catatan di trade-off; ditinjau ulang setelah ada data ketersediaan nyata
3 bulan. SLO P11 (99,5% per 30 hari) terpenuhi oleh kedua varian.

## 3. RPO, RTO, strategi DR
| Aspek | Target (kebutuhan.md / SLO P11) | Kondisi sekarang + bukti |
|-------|---------------------------------|--------------------------|
| RPO | ≤ 24 jam (sekarang), ≤ 5 menit (target) | kesalahan data dalam 6 jam terakhir: PITR Neon (RPO ±detik). Project Neon hilang: **tanpa salinan** sampai `pg_dump` harian 01.30 dijadwalkan (rencana, ADR 0006) → setelah itu ≤ 24 jam. Berkas Supabase: salinan mingguan → RPO berkas ≤ 7 hari (diterima: sampul bisa diunggah ulang petugas). Target RDS: PITR, log tiap ±5 menit → ≤ 5 menit |
| RTO | ≤ 4 jam (sekarang), ≤ 1 jam (target) | terukur: deteksi 43–52 s (G1) + restore ±1 s (latihan pulih: dump 705 KB, `buku` 2.015, `pinjam` 230, `tamu` 17, `notifikasi` 216, `users` 9 baris, count sama) + ganti `DATABASE_URL` di Render dan deploy ±3 menit *(ilustrasi)* ≈ **4–5 menit** + waktu orang memutuskan. Target: failover Multi-AZ otomatis ±1–2 menit |
| Strategi DR | backup & restore (slide 15) | cukup untuk RTO 4 jam. Target: Multi-AZ + PITR + snapshot harian + S3 versioning di region Jakarta; pilot light lintas region **tidak dipilih** (ADR 0006) |
| Lokasi salinan | sesuai keputusan.md | latihan: dump dari database compose (pengganti Neon karena repo contoh tidak punya akun Neon), dipulihkan ke database `pulih` di compose lalu dihapus. Rencana: salinan di laptop pembina (Indonesia), tidak di repo (`*.dump` di `.gitignore`) |

## 4. Pilar Well-Architected (AWS, 6 pilar)
| Pilar | Status | Bukti | Rencana |
|-------|--------|-------|---------|
| Keunggulan operasional | terpenuhi | deploy otomatis (`.github/workflows/ci.yml`, Larastan + PHPUnit + F/K di CI), alert + runbook ([runbook.md](runbook.md), `observability/`), IaC 4 komponen ([infra/](../infra/README.md)), postmortem ([postmortem.md](postmortem.md)) | deploy target lewat OIDC; dasbor produksi (sekarang hanya lab) |
| Keamanan | sebagian | scan di CI, kepatuhan 95% ([keamanan.md](keamanan.md)), login + peran petugas, CSRF, DB tidak publik, bucket privat + tautan 5 menit, secret di Render *Environment*, non-root, HSTS/CSP | 1 CVE CRITICAL tanpa perbaikan + 75 HIGH menunggu digest; WAF, KMS, Secrets Manager baru di target |
| Keandalan | sebagian | timeout 3 s + 503 ramah di semua rute (postmortem.md), alert 43–52 s, MTTR 21,2 s, restore dilatih (±1 s), pengingat idempoten + 5 percobaan | SPOF web + DB (bagian 1); worker/scheduler tidur bersama Render; backup harian belum terjadwal; target Multi-AZ |
| Efisiensi kinerja | terpenuhi | p95 katalog 49,2 ms @100 VU (SLO ≤ 300 ms), 4 worker, cache halaman publik p95 3,7 ms ([optimasi.md](optimasi.md)) | connection pooling; sesi untuk pengunjung anonim (`/` p95 1,22 s @100 VU) |
| Optimasi biaya | terpenuhi | berjalan US$0; target dihitung per komponen (bagian 5), jadwal skala −US$38 ([biaya.md](biaya.md)) | AWS Budgets 50/80/100% sebelum resource dibuat |
| Keberlanjutan | sebagian | lab dimatikan setelah dipakai, Render tidur saat sepi, rancangan 2 task di luar minggu ujian, cache mengurangi CPU aplikasi | Graviton (arm64) untuk task Fargate setelah image multi-arsitektur dites |

## 5. Biaya bulanan per komponen (AWS Jakarta `ap-southeast-3` · harga terbit 2026-11-11, diambil 2026-11-22)
Sumber: AWS Price List API (daftar harga publik, tanpa login). 730
jam/bulan, tarif aktif 24 jam (kasus terburuk), tanpa diskon, kurs asumsi
Rp16.500. Ukuran dari [komponen.md](komponen.md), replika dari P13.

| Komponen | Berjalan (Free) | A: satu zona | B: multi-AZ | Dasar harga |
|----------|-----------------|--------------|-------------|-------------|
| Compute web: Render (satu container) / ECS Fargate 4 × 0,5 vCPU 1 GB | 0 | 89,97 | 89,97 | US$0,05056/vCPU-jam + 0,00553/GB-jam |
| Worker + scheduler: di container Render / ECS Fargate 2 × 0,25 vCPU 0,5 GB | 0 | 22,49 | 22,49 | sama |
| Load balancer: ALB, ±1 LCU | – | 24,24 | 24,24 | US$0,0252/jam + 0,008/LCU-jam |
| IPv4 publik (2 node ALB + NAT) | – | 10,95 | 10,95 | US$0,005/jam per alamat |
| NAT gateway (1) + 10 GB | – | 43,66 | 43,66 | US$0,059/jam + 0,059/GB |
| Database: Neon / RDS PostgreSQL db.t4g.medium + 20 GB gp3 | 0 | 77,22 (single) | 153,71 (Multi-AZ) | US$0,102 / 0,203 per jam; gp3 0,138 / 0,276 per GB |
| Object storage: Supabase Storage Free / S3 30 GB | 0 | 0,75 | 0,75 | US$0,025/GB |
| CloudFront (±40 GB/bulan) | – | 0 | 0 | free tier 1 TB/bulan *(aturan free tier, cek ulang)* |
| Secrets Manager (2 secret) | – | 0,80 | 0,80 | US$0,40/secret |
| WAF (1 ACL + 3 rule + 2 juta request) | – | 9,20 | 9,20 | US$5 + 1/rule + 0,60/juta |
| CloudWatch Logs 5 GB | – | 3,50 | 3,50 | US$0,70/GB |
| SQS (antrean job + DLQ) | – (tabel `jobs`) | 0 | 0 | di bawah free tier (±9.000 pesan/bulan) |
| Email pengingat (±6.000/bulan) | – | ±1,00 | ±1,00 | *(asumsi)* |
| GitHub Actions + GHCR, uptime monitor | 0 | 0 | 0 | repo publik, paket gratis |
| **Total per bulan** | **US$0** | **US$283,77 (±Rp4,7 juta)** | **US$360,26 (±Rp5,9 juta)** | |

```
B ÷ A = 360,26 ÷ 283,77 = 1,27×
pasangan DB HA = 153,71 ÷ 360,26 ≈ 43% dari biaya B (komponen termahal)
dibanding rancangan buku tamu PHP: +22,49 (worker + scheduler Fargate; Lambda dulu US$0)
dengan jadwal 2 ↔ 4 task web (biaya.md): B = 322,20 (±Rp5,3 juta)
anggaran asumsi Rp6 juta/bulan: B tanpa jadwal tersisa ±Rp0,06 juta (hampir habis),
dengan jadwal ±Rp0,7 juta; tanpa cache CDN (8 task web) B = 450,23 → melewati anggaran
```

## 6. Trade-off
| Keputusan | Alasan | Didapat | Dikorbankan |
|-----------|--------|---------|-------------|
| RDS Multi-AZ, bukan single (ADR 0002) | target 99,9%, pinjam-kembali butuh DB | failover otomatis ±1–2 menit, SLA DB 99,5% → 99,95% | biaya DB ×2 (US$77 → 154), 46% biaya total |
| Pindah ke starter Laravel (ADR 0004) | login, CSRF, antrean, scheduler, disk `s3` dari framework | temuan P12 T-01/T-02/T-05 tertutup, uji PHPUnit + Larastan | CPU per request ±1,7× (4 → 6,8 ms), image 613 MB, rancangan target bergantung cache publik |
| Timeout DB 3 s + 503 ramah di semua rute (postmortem.md, Bagian C) | pengguna tidak menunggu 5–30 s | jawaban 3,0 s saat DB macet (dulu **500** di rute biasa), halaman ramah, worker Apache cepat lepas | koneksi lambat yang sebenarnya akan berhasil (> 3 s) ikut dianggap gagal; satu berkas starter diubah kelompok |
| Mode satu container di Render Free | hanya satu web service gratis | web, worker, scheduler tanpa biaya | ketiganya tidur bersama; pengingat 07.00 bisa terlambat 1 hari (runbook 7); worker yang menanyai DB tiap 3 s menghabiskan kuota Neon bila Render dibuat selalu bangun |
| Worker Laravel di ECS, bukan Lambda (ADR 0005) | image, model, dan log yang sama dengan web | satu cara deploy, idempotensi di database | +US$22,49/bulan; worker di dalam VPC butuh NAT |
| Container terkelola, bukan serverless (ADR 0001) | image sama dengan P10, tim kecil | tanpa menulis ulang aplikasi | ±US$90/bulan lebih mahal dari serverless pada beban rata-rata |
| Satu NAT gateway, bukan dua | egress hanya untuk SQS, GHCR, API luar | hemat US$43,66/bulan | bila zona a mati, task zona b tetap melayani tetapi pengingat dan tarik image tertunda |
| CloudFront di depan semua halaman, cache **hanya** halaman publik 1 menit | minggu ujian; tanpa cache butuh 8 task | p95 katalog 3,7 ms (lab), origin hampir diam, 4 task cukup | SLA seri turun ke 99,82%; stok di katalog bisa basi ≤ 61 s; halaman bersesi tidak terbantu |
| Tanpa Kubernetes dan mesh di produksi (optimasi.md) | 1 aplikasi (3 proses) + 1 DB | hemat US$73/bulan, tanpa upgrade klaster | tanpa mTLS antar container |
| Monolit (komponen.md bagian 3) | beban per bagian sama | satu pipeline, transaksi stok tetap satu `COMMIT` | satu bug = deploy ulang semua bagian |
| Berjalan di paket gratis Singapura | tanpa kartu kredit, data contoh | US$0 | 99,0% (asumsi), instance tidur, data di luar Indonesia (hanya data contoh) |

## 7. Jawaban konsep (P15 D.4, ringkas)
1. Retry 1 → 2 → 4 s, maks. 3 kali, tiap percobaan menunggu 3 s: 4 × 3 s +
   (1 + 2 + 4) s = **19 s** paling lama. Circuit breaker (5 gagal → OPEN
   30 s) langsung menjawab 503 tanpa membuka koneksi, jadi database yang
   baru pulih tidak dibanjiri ratusan koneksi sekaligus.
2. Bila exception handler (`DbGagal`) menjawab 200, span tidak ditandai
   ERROR, kueri alert tetap 0% dan alert buta; yang dirugikan petugas dan anggota (gangguan tidak
   ditangani) dan tim (error budget terlihat utuh).
3. Daftar terakhir saat DB mati butuh cache (Nginx `proxy_cache_use_stale
   error timeout`, atau CDN `stale-if-error`); risikonya data basi dan beda
   antar replika bila cache per instance.
4. RTO ≤ 15 menit: **warm standby / Multi-AZ** di region yang sama (RDS
   Multi-AZ + 2 task selalu hidup); biaya DB ×2 (bagian 5, varian B).
5. Multi-region berarti salinan data di luar Indonesia (satu-satunya region
   AWS di Indonesia); untuk perpustakaan kampus belum sepadan. Strategi DR
   di ADR 0006: backup & restore + Multi-AZ di Jakarta.
6. Sertifikasi terdekat: AWS Certified Cloud Practitioner (P1–P3, P9), lalu
   Solutions Architect Associate (P9, P14, P15); untuk peran pengujian dan
   observabilitas, materi P10–P11.
7. Peta CNCF: container runtime/registry (Docker, GHCR), orkestrasi (kind,
   Kubernetes), service mesh (Linkerd, lab), observability (OpenTelemetry,
   Prometheus, Loki, Tempo, Grafana), CI/CD (GitHub Actions), security
   (Trivy), IaC (Terraform/OpenTofu), database (PostgreSQL). Sengaja tidak
   dipakai: **service mesh di produksi** (optimasi.md) dan GitOps (Argo CD):
   tidak ada klaster produksi yang perlu disinkronkan.
