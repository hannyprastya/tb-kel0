# Arsitektur · Perpustakaan Kampus (Kelompok 0) · v2 · 2026-11-17

Perpustakaan pusat kampus swasta (±6.000 mahasiswa, ±300 dosen dan staf)
memindahkan katalog, pinjam-kembali, dan pengingat jatuh tempo ke aplikasi
web. Penggunanya anggota (cari buku, terima pengingat), petugas sirkulasi
(catat pinjam dan kembali), dan pengunjung (buku tamu). Masalah utamanya:
katalog tidak bisa dicari dari luar perpustakaan, stok sering selisih, dan
anggota lupa tanggal kembali. Beban naik ±5× di minggu ujian.

Dokumen terkait: [kebutuhan](kebutuhan.md) · [keputusan](keputusan.md) ·
[komponen](komponen.md) · [jaringan](jaringan.md) · [ADR](adr/) ·
[infra/](../infra/README.md)

Riwayat: v1 (P9) tersimpan di riwayat Git file ini.

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

## 2. Diagram container v2 (P14)

Komponen kuning = tambahan P14 untuk berkas dan pengingat (ADR 0006).
Subnet sesuai [jaringan.md](jaringan.md); setiap kotak punya baris di
[komponen.md](komponen.md).

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
