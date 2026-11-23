# Sistem Perpustakaan Kampus · Kelompok 0 (Kasus 0)

> **CONTOH DARI DOSEN, BUKAN TUGAS KELOMPOK MANA PUN.**
> Repo ini menunjukkan bentuk repo Tugas Besar yang sudah selesai (P9–P15)
> untuk **Kasus 0 · Perpustakaan kampus**, kasus yang sengaja **tidak ada di
> daftar 10 kasus (Tabel K)**. Nama anggota dan NIM (`99000001`–`99000004`)
> fiktif. Pakai sebagai contoh bentuk dan cara berpikir, bukan sebagai bahan
> salin: laporan atau kode yang **50% atau lebih sama** dengan dokumen lain
> (termasuk repo contoh ini) dianggap **plagiat dan bernilai 0** (Panduan
> Tugas Besar Bagian 9). Angka hasil uji di sini milik sistem contoh ini;
> angka kelompokmu pasti berbeda.

Perpustakaan pusat sebuah kampus swasta ingin katalog bisa dicari dari mana
saja, stok eksemplar tidak lagi selisih, dan anggota diingatkan sehari
sebelum jatuh tempo, termasuk saat ramai di minggu ujian.

## Anggota
| NIM | Nama | Peran |
|-----|------|-------|
| 99000001 | Raka Pratama | Aplikasi & CI/CD (pemilik repo, akun Render) |
| 99000002 | Salsa Nuraini | Data & IaC (akun Neon) |
| 99000003 | Dimas Ardiansyah | Pengujian & observabilitas (k6, OTel, alert) |
| 99000004 | Nadia Kusuma | Arsitektur & dokumentasi (C4, ADR, evaluasi; akun Supabase) |

## Aplikasi
P9–P11: buku tamu PHP satu berkas (`index.php`) dari repo `cc-99000001-app`
+ fitur kasus. **Sejak P12: starter Laravel 13** (PHP 8.4, PostgreSQL 17)
dengan fitur kasus sebagai **modul** di `web/` ([web/KASUS-00.md](web/KASUS-00.md));
alasan dan akibatnya di [ADR 0004](docs/adr/0004-pindah-ke-laravel.md).

| Rute | Siapa | Fungsi |
|------|-------|--------|
| `GET /buku?q=` · `GET /buku/{id}/sampul` | siapa saja (publik, boleh di-cache) | katalog + cari, sampul dari object storage |
| `GET /buku/{id}` | anggota yang login | rincian + tautan scan e-dokumen 5 menit |
| `GET /buku/kelola` · `POST /buku` · `POST /buku/{id}/berkas` | petugas | tambah buku, unggah sampul/scan |
| `POST /buku/pinjam` · `POST /buku/kembali` | petugas | pinjam (409 bila eksemplar habis) dan kembali (404 bila sudah) |
| `GET /api/buku/status` · `GET /api/buku?q=` · `GET /api/buku/{id}` · `GET /api/buku/pinjam/{id}` | siapa saja | JSON untuk k6, uptime monitor, uji (tanpa nomor anggota) |
| tugas `kasus:pengingat-jatuh-tempo` (07.00 WIB) → job `KirimPengingatJatuhTempo` | scheduler, worker | pengingat H-1, idempoten (P14) |
| `GET /health` · `GET /healthz` | monitor | cek dalam (DB, maks. 3 s) dan dangkal |
| `/`, `/tamu`, `/berkas`, `/login`, `/register`, `/dashboard` | – | fitur bawaan starter (buku tamu, unggah, Breeze) |

Petugas = akun yang sudah mendaftar lalu dijadikan petugas:
`docker compose exec app php artisan kasus:petugas EMAIL`.

**URL demo:** belum aktif di repo contoh ini (deploy Render baru jalan
setelah langkah *Deploy* di bawah diisi pemilik repo).

## Arsitektur singkat
- **Berjalan sekarang (tanpa kartu kredit):** GitHub Actions → GHCR →
  Render (1 container: Apache + worker + scheduler, health check `/health`)
  → Neon PostgreSQL 17 (URL pooled, PITR 6 jam) + Supabase Storage (bucket
  privat). Lab di codespace: compose (app, worker, scheduler, db, Floci),
  Nginx LB + cache halaman publik, OpenTelemetry → Grafana LGTM, kind + HPA.
- **Target 10.000 pengguna:** AWS Jakarta (`ap-southeast-3`), CloudFront +
  WAF → ALB → ECS Fargate web (2–6 task, 2 zona) + worker + scheduler → RDS
  PostgreSQL Multi-AZ via RDS Proxy; S3 untuk sampul/scan; SQS + DLQ untuk
  pengingat. ±US$360/bulan (multi-AZ), ±US$322 dengan jadwal skala.

Diagram dan alasan: [docs/arsitektur.md](docs/arsitektur.md).

## Peta folder
```
tb-kel0/
├── README.md  docker-compose.yml                    # compose di akar: docker compose up tanpa opsi
├── .gitignore  trivy-secret.yaml  .trivyignore.yaml  # aturan scan untuk seluruh repo (P12)
├── .github/        workflows/ci.yml  dependabot.yml
├── web/            aplikasi Laravel (semua yang masuk image)
│   ├── Dockerfile  Dockerfile.otel  .dockerignore  docker/
│   ├── app/  bootstrap/  config/  database/  lang/  public/  resources/  routes/  tests/
│   └── KASUS.md  KASUS-00.md                         # aturan modul kasus + modul perpustakaan
├── deploy/
│   ├── compose/    otel.yml  lb.yml  nginx.conf  workers.yml  apache-proses.conf
│   └── k8s/        00-rahasia.yaml … 50-hpa.yaml
├── infra/          Terraform per komponen
├── observability/  alert + dasbor SLO Grafana sebagai kode
├── tests/          fungsional.sh  pantau.sh  k6/
└── docs/           README.md (daftar isi) + dokumen P9–P15 + adr/
```

| Folder | Isi |
|--------|-----|
| akar | `docker-compose.yml` (app, worker, scheduler, db, floci) dan aturan scan untuk seluruh repo: Trivy otomatis membaca `trivy-secret.yaml` dari folder kerja |
| `.github/` | pipeline CI/CD (P10, P12) dan Dependabot (P12) |
| `web/` | kode Laravel; konteks build `docker build web`; docroot hanya `web/public/`. Modul perpustakaan: `routes/kasus.php`, `app/*/Kasus/`, `database/migrations/2026_12_01_*`, `resources/views/kasus/`, `tests/Feature/Kasus/`, `tests/kasus.sh` |
| `deploy/compose/` | lapisan compose lab: OpenTelemetry (P11), batas worker Apache (P11 B), load balancer + cache Nginx (P13) |
| `deploy/k8s/` | manifest kind: Secret, db, Job migrasi, web, worker + scheduler, HPA (P8, P13) |
| `infra/` | satu komponen Terraform per anggota (P14), diuji ke Floci |
| `observability/` | alert error 5xx dan dasbor SLO yang dimuat Grafana (P11) |
| `tests/` | uji fungsional F-01..F-19 (+ K-xx dari `web/tests/kasus.sh`), pemantau `/health`, skrip k6 |
| `docs/` | dokumen P9–P15 + ADR; mulai dari [docs/README.md](docs/README.md) |

Langkah modul P10–P15 masih memakai buku tamu PHP; padanannya di repo ini
(mis. `docker build -t NAMA web`, Larastan, `deploy/compose/…`) ada di
tabel *Padanan langkah modul* di README starter `tb-laravel`.

## Cara menjalankan

### GitHub Codespaces (jalur utama)
1. Halaman repo › *Code › Codespaces › … › New with options*, mesin
   **2-core** (8 GB RAM, 32 GB disk) cukup.
2. Di terminal codespace, dari akar repo:
   ```bash
   docker compose up -d --build --wait     # build pertama beberapa menit
   bash tests/fungsional.sh                # F-01..F-19 dan K-01..K-20 harus "Lulus"
   ```
3. Tab *Ports*: buka port **8080**. Bila CSS tidak termuat, jalankan ulang
   dengan alamat publik codespace:
   ```bash
   export APP_URL=https://${CODESPACE_NAME}-8080.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN} PAKSA_APP_URL=1
   docker compose up -d --wait
   ```
4. Daftar akun di `/register`, lalu jadikan petugas:
   `docker compose exec app php artisan kasus:petugas EMAIL`. Data contoh:
   `docker compose exec app php artisan db:seed --force`.
5. Selesai: *Codespaces › Stop* (kuota dihitung per jam mesin menyala).

Laptop (jalur cadangan): Docker Desktop atau OrbStack, perintah yang sama.

Ukuran yang kami ukur (laptop, 2026-11-23): image produksi 613 MB, image
OTel 614 MB, image uji 727 MB; RAM compose inti ±175 MiB saat diam (app 30,
worker 31, scheduler 30, db 46, Floci 36 MiB), mode satu container ±142 MiB
saat 25 VU. Ditambah Grafana LGTM (P11) ±0,8 GB RAM dan ±2,6 GB disk.
Semua muat di codespace 2-core.

### Perintah yang sering dipakai (dari akar repo)
```bash
docker compose run --rm --build uji                                  # PHPUnit (database "uji")
docker compose run --rm uji vendor/bin/phpstan analyse --memory-limit=1G   # Larastan level 5
docker compose logs -f app worker                                    # log aplikasi dan worker
bash tests/pantau.sh                                                 # jam + kode /health tiap 2 s

# load test k6, hanya ke compose sendiri (jaringan = nama folder + _default)
docker run --rm -i --network tb-kel0_default -e URL=http://app:8080 -e RUTE=/buku \
  grafana/k6:1.8.2 run --vus 25 --duration 1m - < tests/k6/uji.js

# OpenTelemetry + Grafana (P11): http://localhost:3000
export NIM=99000003 COMPOSE_FILE=docker-compose.yml:deploy/compose/otel.yml
docker compose up -d --build --wait

# load balancer + cache Nginx (P13), dua replika app
docker compose down
COMPOSE_FILE=docker-compose.yml:deploy/compose/lb.yml docker compose up -d --build --wait --scale app=2

# mode satu container seperti di Render
JALANKAN_WORKER=1 docker compose up -d --wait --scale worker=0 --scale scheduler=0

docker compose down          # berhenti; -v juga menghapus data database
```

## CI/CD (`.github/workflows/ci.yml`)
Setiap PR dan push ke `main` menjalankan satu job `build-test-deploy`
(nama check di ruleset `main`): `composer audit` + `npm audit` → Semgrep
`p/default` → `trivy config` + `trivy fs --scanners secret` → image uji:
Larastan level 5 dan `php artisan test` (PostgreSQL service) → build image
produksi dari `web/` → compose + `tests/fungsional.sh` (F-01..F-19 +
K-01..K-20, termasuk db dimatikan dan dibuat macet) → Trivy
image (gagal bila CRITICAL yang sudah ada perbaikannya) → **push ke GHCR**
(hanya `main`, `GITHUB_TOKEN` bawaan) → **deploy ke Render** (hanya bila
diaktifkan). Semua action dikunci SHA, izin token `contents: read,
packages: write`. Dependabot (`.github/dependabot.yml`) mengusulkan versi
Composer, npm, digest Docker, SHA action, dan provider Terraform.

**Tanpa secret apa pun pipeline tetap hijau:** langkah Deploy dilewati
kecuali variabel repo `RENDER_ENABLED` = `true` (lalu butuh secret
`RENDER_DEPLOY_HOOK`).

## Deploy: Render + Neon + Supabase Storage
Render Free hanya memberi satu web service, jadi aplikasi berjalan dalam
**mode satu container**: `JALANKAN_WORKER=1` membuat supervisord
menyalakan Apache, queue worker, dan scheduler bersama.

1. Buat `APP_KEY` sekali (simpan di pengelola password, **jangan** di-commit):
   ```bash
   docker run --rm --entrypoint php ghcr.io/hannyprastya/tb-kel0:TAG artisan key:generate --show
   ```
2. Supabase › project › *Storage*: bucket **privat** `tb-kel0-berkas`;
   *Project Settings › Storage › S3 Connection*: salin *Endpoint* dan
   *Region*, buat *S3 access key*.
3. Render › web service dari image GHCR (paket GHCR dijadikan *Public*) ›
   *Environment*:

   | Variabel | Nilai |
   |----------|-------|
   | `APP_KEY` | hasil langkah 1 (`base64:…`) |
   | `APP_PEMILIK` | `Kelompok 0 - Perpustakaan Kampus` |
   | `APP_URL` | `https://NAMA-LAYANAN.onrender.com` |
   | `DATABASE_URL` | connection string **pooled** Neon, berakhir `?sslmode=require` |
   | `MIGRASI_SAAT_START` | `1` |
   | `JALANKAN_WORKER` | `1` |
   | `AWS_ENDPOINT` | `https://PROJECT_REF.storage.supabase.co/storage/v1/s3` |
   | `AWS_DEFAULT_REGION` | region project Supabase (mis. `ap-southeast-1`) |
   | `AWS_BUCKET` | `tb-kel0-berkas` |
   | `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | S3 access key Supabase |
   | `AWS_USE_PATH_STYLE_ENDPOINT` | `true` |

4. *Settings › Health Check Path*: `/health`.
5. GitHub repo › *Settings › Secrets and variables › Actions*: secret
   `RENDER_DEPLOY_HOOK`, variabel `RENDER_ENABLED` = `true`; *Rules ›
   Rulesets*: `main` wajib PR + check `build-test-deploy`.

Akibat paket gratis yang perlu diketahui: Render tidur setelah ±15 menit
sepi dan **worker + scheduler ikut tidur** (pengingat 07.00 bisa terlambat,
runbook bagian 7); worker menanyai tabel `jobs` tiap ±3 s sehingga Neon
tidak tidur selama Render bangun (kuota CU-jam, biaya.md bagian 1); email
lupa password hanya ditulis ke log (`MAIL_MAILER=log`).

Nilai di atas yang huruf besar (`NAMA-LAYANAN`, `PROJECT_REF`, `TAG`)
adalah tempat isi, bukan nilai sungguhan. Kunci Supabase, password Neon,
dan `APP_KEY` hanya ada di Render *Environment*. Rollback dan penanganan
alert: [docs/runbook.md](docs/runbook.md).

## Dokumen
Daftar isi, urutan baca, dan pertanyaan yang dijawab tiap dokumen:
[docs/README.md](docs/README.md).

| Dokumen | Isi | Pertemuan |
|---------|-----|-----------|
| [kebutuhan.md](docs/kebutuhan.md) | workload, asumsi, tabel 1.000/10.000 pengguna, target; ukur ulang Laravel | P9, P13 |
| [keputusan.md](docs/keputusan.md) | regulasi, matriks berbobot, uji kepekaan, region | P9 |
| [arsitektur.md](docs/arsitektur.md) | C4 konteks + container, deployment berjalan/target, sequence pinjam dan pengingat | P9, P12, P14, P15 |
| [adr/](docs/adr/) | 6 ADR: 0001–0003 (P9), 0004 pindah ke Laravel (P12), 0005 antrean pengingat (P14), 0006 strategi DR (P15) | P9, P12, P14, P15 |
| [pengujian.md](docs/pengujian.md) | kriteria lulus, uji fungsional, k6, MTTR, SLO, alert (PHP dan Laravel) | P10–P15 |
| [runbook.md](docs/runbook.md) | deploy, rollback, alert 5xx, job gagal/DLQ, pengingat terlewat | P11, P15 |
| [postmortem-latihan-1.md](docs/postmortem-latihan-1.md) · [postmortem.md](docs/postmortem.md) | latihan insiden P11 dan latihan gangguan P15 | P11, P15 |
| [keamanan.md](docs/keamanan.md) | temuan scan, skor kepatuhan, prioritas | P12, P13 |
| [optimasi.md](docs/optimasi.md) · [biaya.md](docs/biaya.md) | sebelum vs sesudah, biaya, titik impas serverless | P13 |
| [komponen.md](docs/komponen.md) · [jaringan.md](docs/jaringan.md) | komponen, event-driven, VPC/subnet | P14 |
| [evaluasi.md](docs/evaluasi.md) | SPOF, ketersediaan, RPO/RTO, Well-Architected, biaya, trade-off | P15 |

## Kontribusi
| No | NIM | Nama | Kontribusi nyata (PR dan bagian) |
|----|-----|------|----------------------------------|
| 1 | 99000001 | Raka Pratama | aplikasi dasar, `ci.yml` (P10, P12), PR `pindah-laravel` + modul perpustakaan, hardening HSTS/CSP (P13 C.4), job + jadwal pengingat, `infra/antrean`, ADR 0003/0004/0005, risiko diterima (`keamanan.md` 6) |
| 2 | 99000002 | Salsa Nuraini | `keputusan.md`, Neon + `infra/db-neon`, `biaya.md` + rightsizing (P13 C.5), latihan pulih (P15 B.3), ADR 0002/0006 |
| 3 | 99000003 | Dimas Ardiansyah | `pengujian.md`, k6, OTel + alert, runbook, optimasi worker + LB, `infra/db-dev`, latihan gangguan + perbaikan `DbGagal` (P15), tanpa mesh (`optimasi.md`), batas waktu 3 s (`postmortem.md`) |
| 4 | 99000004 | Nadia Kusuma | `kebutuhan.md`, `arsitektur.md`, Supabase Storage, cache halaman publik (P13 C.3), `komponen.md`, `jaringan.md`, `evaluasi.md`, `infra/storage-file`, ADR 0001, monolit (`komponen.md` 3) |

**Catatan riwayat Git:** di repo contoh ini commit disatukan per pertemuan
(P9 … P15) oleh satu penulis supaya ceritanya mudah dibaca. Di repo
kelompok sungguhan, setiap anggota bekerja lewat **branch dan pull request
sendiri** dan me-review PR anggota lain; riwayat itulah bukti kontribusi
yang dinilai.

Semua data di aplikasi dan dokumen adalah data contoh, bukan data pribadi
sungguhan.
