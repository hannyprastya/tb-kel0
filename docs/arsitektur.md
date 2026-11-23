# Arsitektur · Perpustakaan Kampus (Kelompok 0) · v1.1 · 2026-11-10

Perpustakaan pusat kampus swasta (±6.000 mahasiswa, ±300 dosen dan staf)
memindahkan katalog, pinjam-kembali, dan pengingat jatuh tempo ke aplikasi
web. Penggunanya anggota (cari buku, terima pengingat), petugas sirkulasi
(catat pinjam dan kembali), dan pengunjung (buku tamu). Masalah utamanya:
katalog tidak bisa dicari dari luar perpustakaan, stok sering selisih, dan
anggota lupa tanggal kembali. Beban naik ±5× di minggu ujian.

Dokumen terkait: [kebutuhan](kebutuhan.md) · [keputusan](keputusan.md) ·
[ADR](adr/)

Riwayat: v1 (P9, 2026-10-23) memakai satu container web PHP. v1.1 (P12)
setelah pindah ke starter Laravel (ADR 0004): satu image yang sama
dijalankan sebagai tiga container (web, worker, scheduler), ditambah object
storage untuk sampul dan scan.

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

## 2. Diagram container (C4 level 2) · v1.1

Batas sistem = provider dan region dari keputusan.md. Satu image Laravel
(`web/`) dijalankan dengan perintah berbeda: Apache (web), `queue:work`
(worker), `schedule:work` (scheduler). Lingkungan latihan sekarang (Render
+ Neon + Supabase Storage, paket gratis) menjalankan ketiganya di **satu**
container (`JALANKAN_WORKER=1`) dengan data contoh saja (ADR 0003, 0004).

```mermaid
flowchart LR
  user["Anggota / petugas<br/>browser"]
  subgraph cloud["AWS&nbsp;·&nbsp;Jakarta&nbsp;ap-southeast-3"]
    web["Aplikasi web<br/>Laravel 13 · PHP 8.4 + Apache · container"]
    worker["Worker antrean<br/>php artisan queue:work · image sama"]
    sch["Scheduler<br/>php artisan schedule:work · image sama"]
    db[("Database<br/>PostgreSQL terkelola<br/>data + sesi + antrean job")]
    file[("Object storage<br/>sampul dan scan, privat")]
  end
  pesan["Layanan email / WhatsApp"]
  user -- "HTTPS" --> web
  web -- "SQL, TLS" --> db
  web -- "S3 API, HTTPS · unggah, tautan 5 menit" --> file
  web -- "titip job (tabel jobs)" --> db
  worker -- "ambil job, SQL" --> db
  sch -- "picu tugas harian, SQL" --> db
  worker -- "API, HTTPS (rencana P14)" --> pesan
  classDef luar fill:#EEF0F8,stroke:#8A8FA0,stroke-dasharray: 5 5
  class pesan luar
```
