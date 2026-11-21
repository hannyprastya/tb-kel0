# Arsitektur · Perpustakaan Kampus (Kelompok 0) · v3 final · 2026-11-20

Perpustakaan pusat kampus swasta (±6.000 mahasiswa, ±300 dosen dan staf)
memindahkan katalog, pinjam-kembali, dan pengingat jatuh tempo ke aplikasi
web. Penggunanya anggota (cari buku, terima pengingat), petugas sirkulasi
(catat pinjam dan kembali), dan pengunjung (buku tamu). Masalah utamanya:
katalog tidak bisa dicari dari luar perpustakaan, stok sering selisih, dan
anggota lupa tanggal kembali. Beban naik ±5× di minggu ujian.

Dokumen terkait: [kebutuhan](kebutuhan.md) · [keputusan](keputusan.md) ·
[komponen](komponen.md) · [jaringan](jaringan.md) · [evaluasi](evaluasi.md)
· [ADR](adr/) · [infra/](../infra/README.md)

Riwayat: v1 (P9, konteks + container) dan v2 (P14, container dengan
komponen baru) tersimpan di riwayat Git file ini.

## Ringkasan: yang berjalan vs rancangan target

| Aspek | Berjalan sekarang (tanpa kartu kredit) | Target 10.000 pengguna (rencana) |
|-------|----------------------------------------|----------------------------------|
| Compute | Render Free, 1 instance, Singapura | ECS Fargate, 4 task × 0,5 vCPU/1 GB, 2 zona, auto scaling 2–6 |
| Database | Neon Free PostgreSQL 17, URL pooled, PITR 6 jam | RDS PostgreSQL 17 Multi-AZ db.t4g.medium + RDS Proxy |
| Berkas | belum ada di produksi; lab: bucket di Floci (`infra/storage-file`) | S3 privat + CloudFront untuk sampul; scan lewat URL bertanda tangan |
| Pengingat | belum ada di produksi; lab: SQS + DLQ + fungsi PHP di Floci (P14 F) | EventBridge Scheduler → app → SQS + DLQ → Lambda → email/WA |
| CI/CD | GitHub Actions → GHCR → deploy hook Render (bila diaktifkan) | sama; deploy ke ECS lewat OIDC (tanpa access key) |
| Observabilitas | uptime monitor ke `/health`; OTel → Grafana LGTM **hanya di lab** | ADOT/OTel → CloudWatch + X-Ray, alarm SLO, uptime monitor |
| Keamanan | HTTPS Render, secret di Render Environment, scan di CI | + WAF, security group berlapis, Secrets Manager, KMS |
| Region data | Singapura (data contoh saja) | Jakarta `ap-southeast-3` (ADR 0003) |

## 1. Diagram konteks (C4 level 1)

```mermaid
flowchart LR
  anggota["Anggota perpustakaan<br/>cari buku, terima pengingat"]
  petugas["Petugas sirkulasi<br/>catat pinjam dan kembali"]
  tamu["Pengunjung<br/>isi buku tamu"]
  sistem["Sistem Perpustakaan Kampus<br/>katalog, pinjam-kembali, pengingat"]
  pesan["Layanan email / WhatsApp<br/>milik pihak lain"]
  siakad["SIAKAD kampus<br/>status mahasiswa aktif"]
  anggota -- "cari katalog, lihat stok" --> sistem
  petugas -- "catat pinjam dan kembali" --> sistem
  tamu -- "isi buku tamu" --> sistem
  sistem -- "kirim pengingat H-1" --> pesan
  pesan -. "pesan pengingat" .-> anggota
  sistem -. "cek anggota aktif (rencana)" .-> siakad
  classDef kita fill:#0B1250,stroke:#0B1250,color:#fff
  classDef luar fill:#EEF0F8,stroke:#8A8FA0,stroke-dasharray: 5 5
  class sistem kita
  class pesan,siakad luar
```

## 2. Diagram container (C4 level 2) · yang berjalan sekarang

Kotak kuning bergaris putus-putus belum melayani pengguna: sudah dibuat dan
dites di lab (compose di codespace, Floci), tetapi belum ada di produksi.

```mermaid
flowchart LR
  user["Anggota / petugas<br/>browser"]
  subgraph prod["Produksi&nbsp;latihan&nbsp;·&nbsp;Render&nbsp;+&nbsp;Neon&nbsp;·&nbsp;Singapura"]
    web["Aplikasi web<br/>PHP 8.4 + Apache · container"]
    db[("Database<br/>PostgreSQL 17 · Neon Free")]
  end
  subgraph lab["Lab&nbsp;·&nbsp;compose&nbsp;di&nbsp;codespace"]
    lgtm["Grafana LGTM<br/>trace · log · metrik · alert"]
    lb["Nginx<br/>load balancer + cache"]
    file[("Object storage<br/>S3 di Floci")]
    q[["Antrean + DLQ<br/>SQS di Floci"]]
    fn["Fungsi pengingat<br/>PHP + Bref di Floci"]
  end
  subgraph gh["GitHub"]
    ci["GitHub Actions<br/>scan · build · test · push"]
    ghcr[("GHCR<br/>image tb-kel0:HASH")]
  end
  mon["Uptime monitor<br/>GET /health tiap 5 menit"]
  user -- "HTTPS" --> web
  web -- "SQL, TLS" --> db
  ci -- "push image" --> ghcr
  ghcr -- "pull saat deploy" --> web
  mon -- "HTTPS" --> web
  lb -. "HTTP, ke replika lab" .-> web
  web -. "OTLP, HTTP 4318" .-> lgtm
  web -. "kirim pesan, HTTPS" .-> q
  q -. "picu, maks. 3x" .-> fn
  fn -. "tulis bukti, HTTPS" .-> file
  classDef luar fill:#EEF0F8,stroke:#8A8FA0,stroke-dasharray: 5 5
  classDef rencana fill:#fff,stroke:#F2A900,color:#5A6070,stroke-dasharray: 5 5
  class gh,mon luar
  class lgtm,lb,file,q,fn rencana
```

## 3. Diagram container (C4 level 2) · rancangan target

Komponen kuning = tambahan P14 untuk berkas dan pengingat (ADR 0006).
Subnet sesuai [jaringan.md](jaringan.md).

```mermaid
flowchart LR
  user["Anggota / petugas<br/>browser"]
  subgraph cloud["AWS&nbsp;·&nbsp;Jakarta&nbsp;ap-southeast-3&nbsp;(rencana)"]
    cdn["CDN + WAF<br/>CloudFront · AWS WAF"]
    lb["Load balancer<br/>ALB · subnet publik"]
    web["Aplikasi web<br/>PHP · ECS Fargate · subnet privat-app"]
    px["Pooler koneksi<br/>RDS Proxy · privat-db"]
    db[("Database<br/>RDS PostgreSQL Multi-AZ · privat-db")]
    file[("Object storage<br/>S3 privat · sampul dan scan")]
    sch["Penjadwal<br/>EventBridge Scheduler · 07.00 WIB"]
    q[["Antrean + DLQ<br/>SQS · pengingat jatuh tempo"]]
    fn["Worker pengingat<br/>Lambda PHP (Bref)"]
    sec["Secrets Manager<br/>password DB, kunci API"]
    obs["Observabilitas<br/>CloudWatch + X-Ray via ADOT"]
  end
  pesan["Layanan email / WhatsApp"]
  gh["GitHub Actions + GHCR<br/>CI/CD, OIDC"]
  user -- "HTTPS" --> cdn
  cdn -- "HTTPS" --> lb
  cdn -- "HTTPS, sampul" --> file
  lb -- "HTTP 8080" --> web
  web -- "SQL, TLS" --> px
  px -- "SQL, TLS" --> db
  web -- "HTTPS, URL bertanda" --> file
  sch -- "HTTPS, POST /pengingat/kirim" --> lb
  web -- "kirim pesan, HTTPS" --> q
  q -- "picu, maks. 5x lalu DLQ" --> fn
  fn -- "API, HTTPS" --> pesan
  web -- "baca secret saat start" --> sec
  web -- "OTLP" --> obs
  gh -- "deploy image, HTTPS" --> web
  classDef baru fill:#F2A900,stroke:#F2A900,color:#0B1250
  classDef luar fill:#EEF0F8,stroke:#8A8FA0,stroke-dasharray: 5 5
  class file,sch,q,fn baru
  class pesan,gh luar
```

## 4. Deployment diagram 1 · berjalan (2026-11-20)

```mermaid
flowchart LR
  user["PENGGUNA<br/>browser"]
  subgraph gh["GitHub"]
    ci["GitHub Actions<br/>scan · build · test · push"]
    ghcr[("GHCR<br/>image tb-kel0:HASH")]
  end
  subgraph rd["Render&nbsp;·&nbsp;Singapore"]
    web["web · 1 instance<br/>PHP 8.4 + Apache · Free 0,1 CPU 512 MB"]
  end
  subgraph ne["Neon&nbsp;·&nbsp;AWS&nbsp;ap-southeast-1"]
    db[("PostgreSQL 17<br/>Free 0,25 CU · tanpa HA · PITR 6 jam")]
  end
  mon["Uptime monitor<br/>GET /health tiap 5 menit"]
  bk[("RENCANA: salinan backup<br/>pg_dump harian 01.30 · laptop dosen pembina")]
  user -- "HTTPS" --> web
  web -- "SQL, TLS" --> db
  ci -- "push image" --> ghcr
  ghcr -- "pull saat deploy" --> web
  mon -- "HTTPS" --> web
  db -. "backup harian" .-> bk
  classDef luar fill:#EEF0F8,stroke:#8A8FA0,stroke-dasharray: 5 5
  classDef rencana fill:#fff,stroke:#F2A900,color:#5A6070,stroke-dasharray: 5 5
  class gh,mon luar
  class bk rencana
```

GHCR tidak berada di jalur permintaan pengguna; ia hanya dibutuhkan saat
deploy (dipakai di SPOF, evaluasi.md bagian 1).

## 5. Deployment diagram 2 · target multi-AZ (rencana)

```mermaid
flowchart LR
  user["PENGGUNA<br/>browser"]
  cdn["CloudFront + WAF<br/>edge Jakarta"]
  subgraph cl["AWS&nbsp;ap-southeast-3&nbsp;Jakarta&nbsp;·&nbsp;rencana"]
    subgraph vpc["VPC&nbsp;10.0.0.0/16&nbsp;·&nbsp;2&nbsp;zona"]
      lb["ALB<br/>2 node · publik 10.0.1.0/24, 10.0.2.0/24"]
      nat["NAT gateway<br/>1 buah · publik zona a"]
      subgraph z1["Zona&nbsp;a"]
        w1["web · 2 task<br/>0,5 vCPU · 1 GB · 10.0.11.0/24"]
        p1[("RDS primer<br/>db.t4g.medium · 10.0.21.0/24")]
      end
      subgraph z2["Zona&nbsp;b"]
        w2["web · 2 task<br/>0,5 vCPU · 1 GB · 10.0.12.0/24"]
        p2[("RDS standby<br/>db.t4g.medium · 10.0.22.0/24")]
      end
    end
    s3[("S3 Standard<br/>3 zona · versioning")]
    q[["SQS + DLQ<br/>regional"]]
    fn["Lambda pengingat<br/>di luar VPC"]
  end
  user -- "HTTPS" --> cdn
  cdn -- "HTTPS" --> lb
  cdn -- "HTTPS, sampul" --> s3
  lb -- "HTTP 8080" --> w1
  lb -- "HTTP 8080" --> w2
  w1 -- "SQL, TLS lewat RDS Proxy" --> p1
  w2 -- "SQL, TLS lewat RDS Proxy" --> p1
  p1 -- "replikasi sinkron" --> p2
  nat -- "HTTPS" --> q
  q -- "picu" --> fn
  w1 -. "keluar internet" .-> nat
  w2 -. "keluar internet" .-> nat
```

Satu NAT gateway sengaja dipilih (hemat ±US$44/bulan): bila zona a mati,
task di zona b tetap melayani pengguna, tetapi pengiriman pesan ke antrean
dan menarik image baru tertunda sampai NAT pulih. Trade-off ini ditulis di
[evaluasi.md](evaluasi.md) bagian 6.

## 6. Sequence diagram · pinjam buku (jalur normal dan gagal)

Aksi utama kasus. Nomor langkah dirujuk di [postmortem.md](postmortem.md).

```mermaid
sequenceDiagram
  autonumber
  actor P as Petugas
  participant W as Aplikasi web
  participant D as PostgreSQL
  participant M as Uptime monitor
  P->>W: POST /buku/pinjam (no. buku, no. anggota)
  W->>D: koneksi (batas waktu 3 s)
  alt database tersedia
    W->>D: UPDATE buku SET tersedia = tersedia - 1 WHERE tersedia > 0
    alt masih ada eksemplar
      D-->>W: 1 baris
      W->>D: INSERT pinjam (jatuh tempo +7 hari), COMMIT
      W-->>P: 302 · nomor peminjaman
    else eksemplar habis
      D-->>W: 0 baris, ROLLBACK
      W-->>P: 409 · "eksemplar sedang dipinjam semua"
    end
  else koneksi DB putus
    D--xW: gagal / timeout 3 s
    W-->>P: 503 · "Maaf, coba lagi 1 menit lagi" (Retry-After 60)
    loop tiap 5 menit
      M->>W: GET /health
      W-->>M: 503
    end
    Note over M: merah → email ke grup kelompok
  end
```

## 7. Sequence diagram · pengingat jatuh tempo (event-driven, P14)

```mermaid
sequenceDiagram
  autonumber
  participant S as Penjadwal (07.00 WIB)
  participant W as Aplikasi web
  participant D as PostgreSQL
  participant Q as Antrean SQS
  participant F as Worker pengingat
  participant E as Email / WhatsApp
  S->>W: POST /pengingat/kirim
  W->>D: pinjaman aktif, jatuh tempo <= besok
  D-->>W: daftar pinjaman
  W->>Q: satu pesan per pinjaman (id = pinjam-NO-TANGGAL)
  W-->>S: 202 diterima
  Q->>F: pesan (visibility 60 s)
  alt pengingat belum pernah dikirim
    F->>E: kirim pengingat
    F-->>Q: hapus pesan
  else id sudah pernah dikirim
    F-->>Q: lewati, hapus pesan (idempoten)
  else layanan email gagal
    F--xQ: error, pesan muncul lagi setelah 60 s
    Note over Q,F: gagal 5x (±5 menit) → pindah ke DLQ, operator memeriksa
  end
```

## 8. Kebutuhan berangka (ringkas dari kebutuhan.md)
- 1.000 pengguna: 100 bersamaan, 20 req/s, 1 vCPU, 1 GB RAM.
- 10.000 pengguna: 1.000 bersamaan, 200 req/s, aplikasi 2 vCPU total
  (4 × 0,5), database 2 vCPU dengan pooler, object storage 29 GB.
- Target: uptime 99,5% (sekarang) dan 99,9% (target); p95 katalog < 1 s
  pada 100 VU; RPO ≤ 24 jam / RTO ≤ 4 jam (sekarang), RPO ≤ 5 menit /
  RTO ≤ 1 jam (target).

## 9. Risiko dan biaya
Rincian di [evaluasi.md](evaluasi.md): SPOF, ketersediaan seri/paralel,
RPO/RTO, pilar Well-Architected, biaya per komponen, dan trade-off.
Ringkasnya:
- **Biaya bulanan:** berjalan US$0 (paket gratis); target satu zona ±US$261
  (±Rp4,3 juta), multi-AZ ±US$338 (±Rp5,6 juta). Komponen termahal:
  database Multi-AZ (±46%).
- **Risiko utama sekarang:** satu instance web dan satu database tanpa
  standby (SPOF); backup harian belum terjadwal untuk database kelompok;
  akun Render/Neon dipegang satu orang.
- **Risiko utama target:** satu region di Indonesia (DR lintas region =
  data keluar negeri, ADR 0009); satu NAT gateway.

## 10. Checklist review diagram (slide 9)
- [x] Judul berisi jenis diagram + versi/tanggal.
- [x] Batas provider, region, zona/environment terlihat.
- [x] Kotak berisi nama + teknologi + angka (replika, ukuran, tier).
- [x] Semua panah berlabel protokol/aksi, satu arah.
- [x] Sistem luar bergaris putus-putus; berjalan vs rencana dibedakan.
- [x] Tanpa host/IP database, password, key, atau connection string.
- [x] Cocok dengan `infra/` (bucket, antrean, database) dan yang berjalan.
