# Sistem Perpustakaan Kampus · Kelompok 0 (Kasus 0)

> **CONTOH DARI DOSEN, BUKAN TUGAS KELOMPOK MANA PUN.**
> Repo ini menunjukkan bentuk repo Tugas Besar yang sudah selesai (P9–P15)
> untuk **Kasus 0 · Perpustakaan kampus**, kasus yang sengaja **tidak ada di
> daftar 10 kasus (Tabel K)**. Nama anggota dan NIM (`9900000x`) fiktif.
> Pakai sebagai contoh bentuk dan cara berpikir, bukan sebagai bahan salin:
> laporan atau kode yang **50% atau lebih sama** dengan dokumen lain
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
| 99000002 | Salsa Nuraini | Data & IaC (akun Neon, `infra/db-neon`) |
| 99000003 | Dimas Ardiansyah | Pengujian & observabilitas (k6, OTel, alert) |
| 99000004 | Nadia Kusuma | Arsitektur & dokumentasi (C4, ADR, evaluasi) |

## Aplikasi dasar
Buku tamu PHP 8.4 + PostgreSQL dari repo `cc-99000001-app` milik Raka,
dipilih karena satu-satunya yang sudah jalan di compose **dan** di kind
dengan readiness `/health` dan liveness `/healthz` (P8). Fitur kasus
(katalog, pinjam, kembali, status JSON) ditambahkan di `app/index.php` tepat
di atas cabang 404, sehingga nomor baris yang dirujuk modul tetap sama.

| Rute | Fungsi |
|------|--------|
| `GET /` · `POST /tamu` | buku tamu pengunjung |
| `GET /buku?q=` | katalog + cari judul/penulis |
| `POST /buku/pinjam` · `POST /buku/kembali` | pinjam (409 bila eksemplar habis) dan kembali |
| `GET /buku/status` | ringkasan stok (JSON, untuk k6 dan monitor) |
| `GET /health` · `GET /healthz` | cek dalam (DB, maks. 3 s) dan dangkal |
| `GET /lambat` | rute latihan P11 (query 0,3 s) |
| `POST /pengingat/kirim` | titip pengingat jatuh tempo ke antrean (P14, butuh Floci) |

**URL demo:** `https://tb-kel0.onrender.com` (contoh; aktif hanya setelah
deploy Render dikonfigurasi, lihat bagian CI/CD).

## Arsitektur singkat
- **Berjalan sekarang (tanpa kartu kredit):** GitHub Actions → GHCR →
  Render (1 instance, health check `/health`) → Neon PostgreSQL 17 (URL
  pooled, PITR 6 jam), uptime monitor ke `/health`. Lab di codespace:
  compose, Nginx LB + cache, OpenTelemetry → Grafana LGTM, kind + HPA,
  Floci untuk bucket/antrean/fungsi.
- **Target 10.000 pengguna:** AWS Jakarta (`ap-southeast-3`), CloudFront +
  WAF → ALB → ECS Fargate (2–6 task, 2 zona) → RDS PostgreSQL Multi-AZ via
  RDS Proxy; S3 untuk sampul/scan; EventBridge Scheduler → SQS + DLQ →
  Lambda untuk pengingat. ±US$338/bulan (multi-AZ).

Diagram dan alasan: [docs/arsitektur.md](docs/arsitektur.md).

## Cara menjalankan
Semua perintah dari **akar repo**, di Codespaces atau laptop dengan Docker.
`docker-compose.yml` sengaja tetap di akar, jadi `docker compose up` jalan
tanpa opsi; berkas compose lain ada di `deploy/compose/` dan dipakai
berlapis lewat `COMPOSE_FILE` (dipisah titik dua, berkas pertama tetap
`docker-compose.yml`). Path relatif di semua berkas compose dihitung dari
folder berkas compose **pertama**, yaitu akar repo.

```bash
# aplikasi + database (P7); image dibangun dari folder app/
docker compose up -d --build --wait
curl -fsS localhost:8080/health          # {"status":"ok","db":"ok"}
bash tests/fungsional.sh                 # uji F-01..F-12 (F-07 mematikan db sebentar)
bash tests/pantau.sh                     # jam + kode /health tiap 2 s (P10 F.3)

# load test k6 (P10), hanya ke compose sendiri
docker run --rm -i --network tb-kel0_default -e URL=http://app:8080 \
  grafana/k6:1.8.2 run --vus 25 --duration 1m - < tests/k6/uji.js

# OpenTelemetry + Grafana (P11): Grafana di http://localhost:3000
export NIM=99000003 COMPOSE_FILE=docker-compose.yml:deploy/compose/otel.yml
docker compose up -d --build --wait

# batas worker Apache (P11 B): lapisan workers di atas compose yang dipakai
COMPOSE_FILE=$COMPOSE_FILE:deploy/compose/workers.yml WORKERS=1 \
  docker compose up -d --wait

# load balancer + cache (P13 C.2-C.3): berdiri sendiri, bukan lapisan
docker compose down && unset COMPOSE_FILE
docker compose --project-directory . -f deploy/compose/lb.yml \
  up -d --build --scale app=2

# Kubernetes lokal (P13): kind + HPA
docker build -t cc-nim-app:1.1 app && kind load docker-image cc-nim-app:1.1 --name cc-lab
kubectl apply -f deploy/k8s/
```

Nama jaringan compose = nama folder + `_default` (`docker network ls`);
`--project-directory .` membuat `lb.yml` tetap memakai nama project, volume,
dan jaringan yang sama. Komponen Terraform: [infra/README.md](infra/README.md).
Antrean, DLQ, dan fungsi PHP di Floci: `serverless/` (langkah P14 F).

## CI/CD (`.github/workflows/ci.yml`)
Setiap PR dan push ke `main` menjalankan job `build-test-deploy`:
composer audit dan PHPStan level 5 (di `app/`) → Semgrep `p/default`,
`trivy config`, dan `trivy fs --scanners secret` (seluruh repo dari akar) →
build image dari `app/` → compose + uji fungsional
F-01..F-12 → Trivy image (gagal bila CRITICAL yang sudah ada perbaikannya)
→ **push ke GHCR** (hanya `main`, dengan `GITHUB_TOKEN` bawaan) →
**deploy ke Render** (hanya bila diaktifkan). Semua action dikunci SHA,
izin token `contents: read, packages: write`.

**Tanpa secret apa pun pipeline tetap hijau:** langkah Deploy dilewati.
Untuk mengaktifkan deploy (pemilik repo):
1. Buat web service Render dari image GHCR (P10 C.2), paket GHCR dijadikan
   *Public* (P10 B.5).
2. *Settings › Secrets and variables › Actions › Secrets*:
   `RENDER_DEPLOY_HOOK` = URL deploy hook Render.
3. Tab *Variables* di halaman yang sama: `RENDER_ENABLED` = `true`.
4. *Settings › Rules › Rulesets*: `main` wajib PR + check `build-test-deploy`
   (P10 A.3).

Rollback dan penanganan alert: [docs/runbook.md](docs/runbook.md).

## Dokumen
Daftar isi, urutan baca, dan pertanyaan yang dijawab tiap dokumen:
[docs/README.md](docs/README.md).

| Dokumen | Isi | Pertemuan |
|---------|-----|-----------|
| [kebutuhan.md](docs/kebutuhan.md) | workload, asumsi, tabel 1.000/10.000 pengguna, target | P9 |
| [keputusan.md](docs/keputusan.md) | regulasi, matriks berbobot, uji kepekaan, region | P9 |
| [arsitektur.md](docs/arsitektur.md) | C4 konteks + container, deployment berjalan/target, sequence | P9, P14, P15 |
| [adr/](docs/adr/) | 9 ADR (0001–0003 inti P9; 0004 risiko; 0005–0007; 0008–0009 P15) | P9–P15 |
| [pengujian.md](docs/pengujian.md) | kriteria lulus, uji fungsional, k6, MTTR, SLO, alert | P10, P11 |
| [runbook.md](docs/runbook.md) | deploy, rollback, alert 5xx, DLQ | P11, P15 |
| [postmortem-latihan-1.md](docs/postmortem-latihan-1.md) · [postmortem.md](docs/postmortem.md) | latihan insiden P11 dan latihan gangguan P15 | P11, P15 |
| [keamanan.md](docs/keamanan.md) | temuan scan, skor kepatuhan, prioritas | P12 |
| [optimasi.md](docs/optimasi.md) · [biaya.md](docs/biaya.md) | sebelum vs sesudah, biaya, titik impas serverless | P13 |
| [komponen.md](docs/komponen.md) · [jaringan.md](docs/jaringan.md) | komponen, event-driven, VPC/subnet | P14 |
| [evaluasi.md](docs/evaluasi.md) | SPOF, ketersediaan, RPO/RTO, Well-Architected, biaya, trade-off | P15 |

## Peta folder
```
tb-kel0/
├── README.md  docker-compose.yml               # compose di akar: docker compose up tanpa opsi
├── .gitignore  trivy-secret.yaml  .trivyignore.yaml   # di akar: berlaku untuk seluruh repo
├── .github/        workflows/ci.yml  dependabot.yml
├── app/            index.php  composer.json  composer.lock  .htaccess
│                   Dockerfile  Dockerfile.otel  .dockerignore
├── serverless/     fungsi PHP (Bref) untuk Floci
├── deploy/
│   ├── compose/    otel.yml  lb.yml  nginx.conf  workers.yml  apache-proses.conf
│   └── k8s/        app.yaml  hpa.yaml
├── infra/          Terraform per anggota
├── observability/  alert + dasbor SLO Grafana sebagai kode
├── tests/          fungsional.sh  pantau.sh  k6/
└── docs/           README.md (daftar isi) + kebutuhan s.d. evaluasi + adr/
```

| Folder | Isi |
|--------|-----|
| akar | `docker-compose.yml` (app + db, env Floci P14) dan aturan scan yang berlaku untuk seluruh repo (P12): Trivy otomatis membaca `trivy-secret.yaml` dari folder kerja |
| `.github/` | pipeline CI/CD (P10, P12) dan Dependabot (P12) |
| `app/` | kode aplikasi dan semua yang masuk image; konteks build `docker build app` (P7, P13 hardening) |
| `serverless/` | fungsi pengingat (Bref) + berkas antrean untuk Floci, dibungkus terpisah (P14 F) |
| `deploy/compose/` | lapisan compose lab: OpenTelemetry (P11), batas worker (P11 B), LB + cache Nginx (P13) |
| `deploy/k8s/` | manifest kind + HPA (P8, P13) |
| `infra/` | satu komponen Terraform per anggota (P14) |
| `observability/` | alert dan dasbor SLO yang dimuat Grafana (P11) |
| `tests/` | uji fungsional F-01..F-12 (P10), pemantau `/health` (P10 F.3), skrip k6 (P10, P11, P13) |
| `docs/` | dokumen P9–P15 + ADR; mulai dari [docs/README.md](docs/README.md) |

**Di repo kelompok kalian (struktur modul P7–P15) → di contoh ini.** Modul
menaruh semua berkas di akar repo supaya langkahnya pendek; contoh ini
merapikannya ke folder. Isinya sama, hanya letak dan perintahnya berbeda.

| Di repo kelompok (modul) | Di contoh ini |
|--------------------------|---------------|
| `index.php` | `app/index.php` (nomor baris sama) |
| `composer.json`, `composer.lock`, `.htaccess` | `app/composer.json`, `app/composer.lock`, `app/.htaccess` |
| `Dockerfile`, `Dockerfile.otel`, `.dockerignore` | `app/Dockerfile`, `app/Dockerfile.otel`, `app/.dockerignore` |
| `docker build -t NAMA .` | `docker build -t NAMA app` |
| `docker-compose.yml` (`build: .`) | `docker-compose.yml` tetap di akar (`build: ./app`) |
| `docker-compose.otel.yml` | `deploy/compose/otel.yml` |
| `COMPOSE_FILE=docker-compose.yml:docker-compose.otel.yml` | `COMPOSE_FILE=docker-compose.yml:deploy/compose/otel.yml` |
| `docker-compose.workers.yml` | `deploy/compose/workers.yml` |
| `workers.conf` | `deploy/compose/apache-proses.conf` (di container tetap `conf-enabled/workers.conf`) |
| `docker-compose.lb.yml`, `nginx.conf` | `deploy/compose/lb.yml`, `deploy/compose/nginx.conf` |
| `docker compose -f docker-compose.lb.yml up …` | `docker compose --project-directory . -f deploy/compose/lb.yml up …` |
| `k8s/app.yaml`, `k8s/hpa.yaml` | `deploy/k8s/app.yaml`, `deploy/k8s/hpa.yaml` |
| `kubectl apply -f k8s/` | `kubectl apply -f deploy/k8s/` |
| `pantau.sh` | `tests/pantau.sh` |
| `tests/fungsional.sh`, `tests/k6/`, `observability/`, `infra/`, `serverless/` | sama |
| di CI: `composer audit`, PHPStan di akar | di CI: dijalankan di `app/` (`-v "$PWD/app:/app"`) |

## Kontribusi
| No | NIM | Nama | Kontribusi nyata (PR dan bagian) |
|----|-----|------|----------------------------------|
| 1 | 99000001 | Raka Pratama | aplikasi dasar + fitur kasus, `ci.yml` (P10, P12), deploy Render, rute pengingat + `infra/antrean`, ADR 0003/0004/0006 |
| 2 | 99000002 | Salsa Nuraini | `keputusan.md`, Neon + `infra/db-neon`, latihan pulih (P15 B.3), `biaya.md`, ADR 0002/0009 |
| 3 | 99000003 | Dimas Ardiansyah | `pengujian.md`, k6, OTel + alert, runbook, `infra/db-dev`, optimasi worker + LB, ADR 0005/0008 |
| 4 | 99000004 | Nadia Kusuma | `kebutuhan.md`, `arsitektur.md` v1–v3, `komponen.md`, `jaringan.md`, `evaluasi.md`, `infra/storage-file`, ADR 0001/0007 |

**Catatan riwayat Git:** di repo contoh ini commit disatukan per pertemuan
(P9 … P15) oleh satu penulis supaya ceritanya mudah dibaca. Di repo
kelompok sungguhan, setiap anggota bekerja lewat **branch dan pull request
sendiri** dan me-review PR anggota lain; riwayat itulah bukti kontribusi
yang dinilai.

Semua data di aplikasi dan dokumen adalah data contoh, bukan data pribadi
sungguhan.
