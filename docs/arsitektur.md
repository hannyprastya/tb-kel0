# Arsitektur · Perpustakaan Kampus (Kelompok 0) · v2 · 2026-11-17

Perpustakaan pusat kampus swasta (±6.000 mahasiswa, ±300 dosen dan staf)
memindahkan katalog, pinjam-kembali, dan pengingat jatuh tempo ke aplikasi
web. Penggunanya anggota (cari buku, terima pengingat), petugas sirkulasi
(catat pinjam dan kembali, unggah sampul/scan), dan pengunjung (katalog,
buku tamu). Masalah utamanya: katalog tidak bisa dicari dari luar
perpustakaan, stok sering selisih, dan anggota lupa tanggal kembali. Beban
naik ±5× di minggu ujian.

Dokumen terkait: [kebutuhan](kebutuhan.md) · [keputusan](keputusan.md) ·
[komponen](komponen.md) · [jaringan](jaringan.md) · [ADR](adr/) ·
[infra/](../infra/README.md)

Riwayat: v1 (P9, satu container web PHP), v1.1 (P12, pindah ke Laravel:
web + worker + scheduler dari satu image, ADR 0004), v2 (P14, komponen
target lengkap) tersimpan di riwayat Git file ini.

## Ringkasan: yang berjalan vs rancangan target

| Aspek | Berjalan sekarang (tanpa kartu kredit) | Target 10.000 pengguna (rencana) |
|-------|----------------------------------------|----------------------------------|
| Compute web | Render Free, 1 container (Apache + worker + scheduler, `JALANKAN_WORKER=1`), Singapura | ECS Fargate web 4 × 0,5 vCPU/1 GB, 2 zona, auto scaling 2–6 |
| Worker + scheduler | di container Render yang sama (supervisord); tidur bersama web | ECS service worker 1–2 × 0,25 vCPU, scheduler 1 × 0,25 vCPU |
| Antrean job | tabel `jobs` di Neon | SQS + DLQ (`infra/antrean`, diuji di Floci) |
| Database | Neon Free PostgreSQL 17, URL pooled, PITR 6 jam (data + sesi + antrean) | RDS PostgreSQL 17 Multi-AZ db.t4g.medium + RDS Proxy |
| Berkas (sampul, scan) | **Supabase Storage** Free, bucket privat, API S3 | S3 privat + CloudFront (sampul); scan lewat tautan bertanda tangan |
| CI/CD | GitHub Actions → GHCR → deploy hook Render (bila diaktifkan) | sama; deploy ke ECS lewat OIDC (tanpa access key) |
| Observabilitas | uptime monitor ke `/health`; OTel → Grafana LGTM **hanya di lab** | ADOT/OTel → CloudWatch + X-Ray, alarm SLO, uptime monitor |
| Keamanan | HTTPS Render, HSTS + CSP dasar, secret di Render *Environment*, scan di CI | + WAF (rate-based), security group berlapis, Secrets Manager, KMS |
| Region data | Singapura (data contoh saja) | Jakarta `ap-southeast-3` (ADR 0003) |

## 1. Diagram konteks (C4 level 1)

```mermaid
flowchart LR
  anggota["Anggota perpustakaan<br/>cari buku, terima pengingat"]
  petugas["Petugas sirkulasi<br/>catat pinjam dan kembali, unggah sampul/scan"]
  tamu["Pengunjung<br/>cari katalog, isi buku tamu"]
  sistem["Sistem Perpustakaan Kampus<br/>katalog, pinjam-kembali, pengingat"]
  pesan["Layanan email / WhatsApp<br/>milik pihak lain"]
  siakad["SIAKAD kampus<br/>status mahasiswa aktif"]
  anggota -- "cari katalog, lihat stok" --> sistem
  petugas -- "catat pinjam dan kembali" --> sistem
  tamu -- "cari katalog tanpa login" --> sistem
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
  subgraph prod["Produksi&nbsp;latihan&nbsp;·&nbsp;Singapura"]
    subgraph rd["Render&nbsp;Free&nbsp;·&nbsp;1&nbsp;container"]
      web["web<br/>Laravel 13 · Apache"]
      wk["worker<br/>queue:work"]
      sc["scheduler<br/>schedule:work"]
    end
    db[("Database<br/>PostgreSQL 17 · Neon Free<br/>data + sesi + tabel jobs")]
    file[("Object storage<br/>Supabase Storage · bucket privat")]
  end
  subgraph lab["Lab&nbsp;·&nbsp;compose&nbsp;di&nbsp;codespace"]
    lgtm["Grafana LGTM<br/>trace · log · metrik · alert"]
    lb["Nginx<br/>LB + cache halaman publik"]
    q[["Antrean + DLQ<br/>SQS di Floci"]]
  end
  subgraph gh["GitHub"]
    ci["GitHub Actions<br/>scan · uji · build · push"]
    ghcr[("GHCR<br/>image tb-kel0:HASH")]
  end
  mon["Uptime monitor<br/>GET /health tiap 5 menit"]
  user -- "HTTPS" --> web
  web -- "SQL, TLS" --> db
  wk -- "ambil job, SQL TLS" --> db
  sc -- "titip pengingat 07.00, SQL TLS" --> db
  web -- "S3 API, HTTPS" --> file
  ci -- "push image" --> ghcr
  ghcr -- "pull saat deploy" --> web
  mon -- "HTTPS" --> web
  lb -. "HTTP, ke replika lab" .-> web
  web -. "OTLP, HTTP 4318" .-> lgtm
  wk -. "QUEUE_CONNECTION=sqs" .-> q
  classDef luar fill:#EEF0F8,stroke:#8A8FA0,stroke-dasharray: 5 5
  classDef rencana fill:#fff,stroke:#F2A900,color:#5A6070,stroke-dasharray: 5 5
  class gh,mon luar
  class lgtm,lb,q rencana
```

## 3. Diagram container (C4 level 2) · rancangan target

Komponen kuning = tambahan P14 untuk berkas dan pengingat (ADR 0005).
Subnet sesuai [jaringan.md](jaringan.md).

```mermaid
flowchart LR
  user["Anggota / petugas<br/>browser"]
  subgraph cloud["AWS&nbsp;·&nbsp;Jakarta&nbsp;ap-southeast-3&nbsp;(rencana)"]
    cdn["CDN + WAF<br/>CloudFront · AWS WAF<br/>cache hanya halaman publik"]
    lb["Load balancer<br/>ALB · subnet publik"]
    web["web<br/>Laravel · ECS Fargate 2–6 task"]
    wk["worker<br/>queue:work · ECS 1–2 task"]
    sc["scheduler<br/>schedule:work · ECS 1 task"]
    px["Pooler koneksi<br/>RDS Proxy · privat-app"]
    db[("Database<br/>RDS PostgreSQL Multi-AZ · privat-db")]
    file[("Object storage<br/>S3 privat · sampul dan scan")]
    q[["Antrean + DLQ<br/>SQS · job pengingat"]]
    sec["Secrets Manager<br/>APP_KEY, password DB"]
    obs["Observabilitas<br/>CloudWatch + X-Ray via ADOT"]
  end
  pesan["Layanan email / WhatsApp"]
  gh["GitHub Actions + GHCR<br/>CI/CD, OIDC"]
  user -- "HTTPS" --> cdn
  cdn -- "HTTPS" --> lb
  cdn -- "HTTPS, sampul (OAC)" --> file
  lb -- "HTTP 8080" --> web
  web -- "SQL, TLS" --> px
  wk -- "SQL, TLS" --> px
  sc -- "SQL, TLS" --> px
  px -- "SQL, TLS" --> db
  web -- "S3 API, unggah + tautan 5 menit" --> file
  sc -- "titip job 07.00, HTTPS" --> q
  q -- "ambil job, maks. 5x lalu DLQ" --> wk
  wk -- "API, HTTPS" --> pesan
  web -- "baca secret saat start" --> sec
  web -- "OTLP" --> obs
  wk -- "OTLP" --> obs
  gh -- "deploy image, HTTPS" --> web
  classDef baru fill:#F2A900,stroke:#F2A900,color:#0B1250
  classDef luar fill:#EEF0F8,stroke:#8A8FA0,stroke-dasharray: 5 5
  class file,wk,sc,q baru
  class pesan,gh luar
```

## 4. Sequence diagram · pinjam buku (jalur normal dan gagal)

Aksi utama kasus. Nomor langkah dirujuk di postmortem.

```mermaid
sequenceDiagram
  autonumber
  actor P as Petugas (login)
  participant W as web (Laravel)
  participant D as PostgreSQL
  participant M as Uptime monitor
  P->>W: POST /buku/pinjam (buku, anggota, _token CSRF)
  W->>D: koneksi (batas waktu 3 s), cek sesi + peran petugas
  alt database tersedia
    W->>D: UPDATE buku SET tersedia = tersedia - 1 WHERE id = ? AND tersedia > 0
    alt masih ada eksemplar
      D-->>W: 1 baris
      W->>D: INSERT pinjam (jatuh tempo +7 hari), COMMIT
      W-->>P: 302 / 201 · nomor peminjaman
    else eksemplar habis
      D-->>W: 0 baris, ROLLBACK
      W-->>P: 409 · "eksemplar sedang dipinjam semua"
    end
  else koneksi DB putus
    D--xW: gagal / timeout 3 s
    W-->>P: 503 · halaman "Maaf …" (Retry-After 60), log "DB gagal: …"
    loop tiap 5 menit
      M->>W: GET /health
      W-->>M: 503
    end
    Note over M: merah → email ke grup kelompok
  end
```

## 5. Sequence diagram · pengingat jatuh tempo (event-driven, P14)

```mermaid
sequenceDiagram
  autonumber
  participant S as scheduler (07.00 WIB)
  participant D as PostgreSQL
  participant Q as Antrean (SQS / tabel jobs)
  participant K as worker
  participant E as Email / WhatsApp
  S->>D: pinjaman aktif, jatuh tempo <= besok, belum ada di notifikasi
  D-->>S: daftar pinjaman
  S->>Q: satu job per pinjaman (KirimPengingatJatuhTempo)
  Q->>K: job (visibility 60 s, timeout 10 s)
  K->>D: INSERT notifikasi (kunci pinjam-NO-TANGGAL) ON CONFLICT DO NOTHING
  alt baris baru
    K->>E: kirim pengingat
    K->>D: COMMIT
  else kunci sudah ada
    K-->>Q: lewati (idempoten), job selesai
  else layanan email gagal
    K->>D: ROLLBACK (catatan batal)
    K--xQ: lepas lagi, jeda 1 / 3 / 10 / 20 menit
    Note over Q,K: gagal 5x (±34 menit) → failed_jobs, worker mati → DLQ
  end
```
