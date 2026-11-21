# Evaluasi Arsitektur · Perpustakaan Kampus (P15)

Dasar: [arsitektur.md](arsitektur.md) (Diagram 1 = berjalan, Diagram 2 =
target), [kebutuhan.md](kebutuhan.md), SLO di [pengujian.md](pengujian.md).
Angka latihan (deteksi, pulih, restore) diukur 2026-11-22; angka bertanda
*(ilustrasi)* atau *(asumsi)* tidak diukur di repo contoh ini.

## 1. SPOF (Diagram 1, sistem yang berjalan)
| Komponen | Kalau mati | SPOF? | Mitigasi (sudah / rencana) + dampak biaya |
|----------|------------|-------|-------------------------------------------|
| web, 1 instance Render Free | semua halaman mati sampai instance baru hidup (G2: ±48 s *(ilustrasi)*); juga "tidur" setelah 15 menit sepi | ya | rencana: ≥ 2 instance berbayar atau target ECS 2–6 task di 2 zona (+US$90/bulan) |
| PostgreSQL Neon Free | `/`, `/buku`, pinjam, kembali: 503 ramah; `/healthz` tetap 200 | ya | sudah: timeout 3 s + 503 ramah (ADR 0008), PITR 6 jam; rencana: RDS Multi-AZ (+US$76/bulan dibanding single) |
| GHCR | pengguna tidak terdampak; deploy versi baru gagal | untuk pemulihan | sudah: image yang berjalan tetap di Render; rencana: runbook menyebut "tunda deploy" |
| GitHub Actions | pengguna tidak terdampak; tidak ada build/deploy | tidak | deploy manual dari Render (*Deploy an existing image*) |
| Uptime monitor | gangguan tidak terdeteksi dari luar | untuk deteksi | sudah: alert Grafana di lab; rencana: interval 1 menit saat demo (postmortem.md) |
| Salinan backup harian (rencana) | belum ada: bila project Neon terhapus, data di luar 6 jam PITR hilang | ya (data) | rencana: `pg_dump` harian (ADR 0009), US$0 |
| **Satu orang pemegang akun Render dan Neon** (non-teknis) | tidak ada yang bisa restart/restore saat ia tidak ada | ya | rencana: undang satu anggota lain ke tim Render/Neon (US$0) |
| Region Singapura (Render + Neon) | semua mati bila region bermasalah | ya | target: Jakarta 2 zona; lintas region tidak dipilih (ADR 0009) |

## 2. Ketersediaan
Contoh slide 13 dihitung ulang: seri web 99,9% × DB 99,9% = 0,998001 ≈
**99,8%** → 0,2% × 8.760 = 17,5 jam/tahun. Dua web paralel: 1 − 0,001² =
99,9999%, seri dengan DB HA 99,99%: 0,999999 × 0,9999 = 0,999899 ≈
**99,99%** → 0,88 jam ≈ 53 menit/tahun.

**Berjalan** (yang dilewati satu permintaan: Render web → Neon; GHCR dan
monitor tidak). Paket gratis tanpa SLA, jadi dipakai asumsi dari uptime
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
| RPO | ≤ 24 jam (sekarang), ≤ 5 menit (target) | kesalahan data dalam 6 jam terakhir: PITR Neon (RPO ±detik). Project Neon hilang: **tanpa salinan** sampai `pg_dump` harian 01.30 dijadwalkan (rencana, ADR 0009) → setelah itu ≤ 24 jam: data di antara dua backup hilang. Target RDS: PITR, log tiap ±5 menit → ≤ 5 menit |
| RTO | ≤ 4 jam (sekarang), ≤ 1 jam (target) | terukur: deteksi 48 s (G1) + restore 3 s (latihan pulih: dump 7,6 KB, `tamu` 3, `buku` 3, `pinjam` 10 baris, count sama) + ganti `DATABASE_URL` di Render dan deploy ±3 menit *(ilustrasi)* ≈ **4 menit** + waktu orang memutuskan. Target: failover Multi-AZ otomatis ±1–2 menit |
| Strategi DR | backup & restore (slide 15) | cukup untuk RTO 4 jam. Target: Multi-AZ + PITR + snapshot harian di region Jakarta; pilot light lintas region **tidak dipilih** (ADR 0009) |
| Lokasi salinan | sesuai keputusan.md | latihan: dump dari database compose (pengganti Neon karena repo contoh tidak punya akun Neon), dipulihkan ke `tamu_pulih` di compose lalu dihapus. Rencana: salinan di laptop pembina (Indonesia), tidak di repo (`*.dump` di `.gitignore`) |

## 4. Pilar Well-Architected (AWS, 6 pilar)
| Pilar | Status | Bukti | Rencana |
|-------|--------|-------|---------|
| Keunggulan operasional | terpenuhi | deploy otomatis (`.github/workflows/ci.yml`), alert + runbook ([runbook.md](runbook.md), `observability/`), IaC 4 komponen ([infra/](../infra/README.md)), postmortem ([postmortem.md](postmortem.md)) | deploy target lewat OIDC; dasbor produksi (sekarang hanya lab) |
| Keamanan | sebagian | scan di CI, kepatuhan 95% ([keamanan.md](keamanan.md)), DB tidak publik, secret di Render *Environment*, non-root | 1 CVE CRITICAL tanpa perbaikan dipantau; WAF, KMS, Secrets Manager baru di target |
| Keandalan | sebagian | timeout 3 s + 503 ramah (ADR 0008), alert ≤ 52 s, MTTR 21 s, restore dilatih | SPOF web + DB (bagian 1); backup harian belum terjadwal; target Multi-AZ |
| Efisiensi kinerja | terpenuhi | p95 katalog 76,8 ms @100 VU (SLO ≤ 300 ms), 4 worker, cache ×57 ([optimasi.md](optimasi.md)) | connection pooling; schema keluar dari jalur request |
| Optimasi biaya | terpenuhi | berjalan US$0; target dihitung per komponen (bagian 5), jadwal skala −US$38 ([biaya.md](biaya.md)) | AWS Budgets 50/80/100% sebelum resource dibuat |
| Keberlanjutan | sebagian | lab dimatikan setelah dipakai, Render tidur saat sepi, rancangan 2 task di luar minggu ujian | Graviton (arm64) untuk task Fargate setelah image multi-arsitektur dites |

## 5. Biaya bulanan per komponen (AWS Jakarta `ap-southeast-3` · harga terbit 2026-11-11, diambil 2026-11-22)
Sumber: AWS Price List API (daftar harga publik, tanpa login). 730
jam/bulan, tarif aktif 24 jam (kasus terburuk), tanpa diskon, kurs asumsi
Rp16.500. Ukuran dari [komponen.md](komponen.md), replika dari P13.

| Komponen | Berjalan (Free) | A: satu zona | B: multi-AZ | Dasar harga |
|----------|-----------------|--------------|-------------|-------------|
| Compute: Render / ECS Fargate 4 × 0,5 vCPU 1 GB | 0 | 89,97 | 89,97 | US$0,05056/vCPU-jam + 0,00553/GB-jam |
| Load balancer: ALB, ±1 LCU | – | 24,24 | 24,24 | US$0,0252/jam + 0,008/LCU-jam |
| IPv4 publik (2 node ALB + NAT) | – | 10,95 | 10,95 | US$0,005/jam per alamat |
| NAT gateway (1) + 10 GB | – | 43,66 | 43,66 | US$0,059/jam + 0,059/GB |
| Database: Neon / RDS PostgreSQL db.t4g.medium + 20 GB gp3 | 0 | 77,22 (single) | 153,71 (Multi-AZ) | US$0,102 / 0,203 per jam; gp3 0,138 / 0,276 per GB |
| Object storage S3 30 GB | – | 0,75 | 0,75 | US$0,025/GB |
| CloudFront (±40 GB/bulan) | – | 0 | 0 | free tier 1 TB/bulan *(aturan free tier, cek ulang)* |
| Secrets Manager (2 secret) | – | 0,80 | 0,80 | US$0,40/secret |
| WAF (1 ACL + 3 rule + 2 juta request) | – | 9,20 | 9,20 | US$5 + 1/rule + 0,60/juta |
| CloudWatch Logs 5 GB | – | 3,50 | 3,50 | US$0,70/GB |
| SQS, Lambda, EventBridge Scheduler | – | 0 | 0 | di bawah free tier (±9.000 pesan/bulan) |
| Email pengingat (±6.000/bulan) | – | ±1,00 | ±1,00 | *(asumsi)* |
| GitHub Actions + GHCR, uptime monitor | 0 | 0 | 0 | repo publik, paket gratis |
| **Total per bulan** | **US$0** | **US$261,28 (±Rp4,3 juta)** | **US$337,77 (±Rp5,6 juta)** | |

```
B ÷ A = 337,77 ÷ 261,28 = 1,29×
pasangan DB HA = 153,71 ÷ 337,77 ≈ 46% dari biaya B (komponen termahal)
dengan jadwal 2 ↔ 4 task (biaya.md): B = 299,71 (±Rp4,9 juta)
anggaran asumsi Rp6 juta/bulan: B masih di dalam anggaran, sisa ±Rp0,4–1,1 juta
```

## 6. Trade-off
| Keputusan | Alasan | Didapat | Dikorbankan |
|-----------|--------|---------|-------------|
| RDS Multi-AZ, bukan single (ADR 0002) | target 99,9%, pinjam-kembali butuh DB | failover otomatis ±1–2 menit, SLA DB 99,5% → 99,95% | biaya DB ×2 (US$77 → 154), 46% biaya total |
| Timeout DB 3 s + 503 ramah (ADR 0008, Bagian C) | pengguna tidak menunggu 5–30 s | jawaban 3,0 s saat DB menggantung (dulu 5,0 s), halaman ramah, worker cepat lepas | koneksi lambat yang sebenarnya akan berhasil (> 3 s) ikut dianggap gagal |
| Container terkelola, bukan serverless (ADR 0001) | image sama dengan P10, tim kecil | tanpa menulis ulang aplikasi | ±US$90/bulan lebih mahal dari serverless pada beban rata-rata |
| Satu NAT gateway, bukan dua | egress hanya untuk SQS, GHCR, API luar | hemat US$43,66/bulan | bila zona a mati, task zona b tetap melayani tetapi pengingat dan tarik image tertunda |
| CloudFront di depan semua halaman + cache katalog 1 menit | minggu ujian, cache ×57 | p95 katalog 2,8 ms, origin hampir diam | SLA seri turun ke 99,82%; stok di katalog bisa basi ≤ 1 menit |
| Tanpa Kubernetes dan mesh di produksi (ADR 0005) | 1 aplikasi + 1 DB | hemat US$73/bulan, tanpa upgrade klaster | tanpa mTLS antar container |
| Monolit (ADR 0007) | beban per bagian sama | satu pipeline, transaksi stok tetap satu `COMMIT` | satu bug = deploy ulang semua bagian |
| Berjalan di paket gratis Singapura | tanpa kartu kredit, data contoh | US$0 | 99,0% (asumsi), instance tidur, data di luar Indonesia (hanya data contoh) |

## 7. Jawaban konsep (P15 D.4, ringkas)
1. Retry 1 → 2 → 4 s, maks. 3 kali, tiap percobaan menunggu 3 s: 4 × 3 s +
   (1 + 2 + 4) s = **19 s** paling lama. Circuit breaker (5 gagal → OPEN
   30 s) langsung menjawab 503 tanpa membuka koneksi, jadi database yang
   baru pulih tidak dibanjiri ratusan koneksi sekaligus.
2. Bila `maaf()` menjawab 200, span tidak ditandai ERROR, kueri alert tetap
   0% dan alert buta; yang dirugikan petugas dan anggota (gangguan tidak
   ditangani) dan tim (error budget terlihat utuh).
3. Daftar terakhir saat DB mati butuh cache (Nginx `proxy_cache_use_stale
   error timeout`, atau CDN `stale-if-error`); risikonya data basi dan beda
   antar replika bila cache per instance.
4. RTO ≤ 15 menit: **warm standby / Multi-AZ** di region yang sama (RDS
   Multi-AZ + 2 task selalu hidup); biaya DB ×2 (bagian 5, varian B).
5. Multi-region berarti salinan data di luar Indonesia (satu-satunya region
   AWS di Indonesia); untuk perpustakaan kampus belum sepadan. Strategi DR
   di ADR 0009: backup & restore + Multi-AZ di Jakarta.
6. Sertifikasi terdekat: AWS Certified Cloud Practitioner (P1–P3, P9), lalu
   Solutions Architect Associate (P9, P14, P15); untuk peran pengujian dan
   observabilitas, materi P10–P11.
7. Peta CNCF: container runtime/registry (Docker, GHCR), orkestrasi (kind,
   Kubernetes), service mesh (Linkerd, lab), observability (OpenTelemetry,
   Prometheus, Loki, Tempo, Grafana), CI/CD (GitHub Actions), security
   (Trivy), IaC (Terraform/OpenTofu), database (PostgreSQL). Sengaja tidak
   dipakai: **service mesh di produksi** (ADR 0005) dan GitOps (Argo CD):
   tidak ada klaster produksi yang perlu disinkronkan.
