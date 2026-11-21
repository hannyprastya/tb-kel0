# 0001 · Menjalankan aplikasi sebagai container di layanan terkelola
Status: Diterima · Tanggal: 2026-10-23 · Penulis: Nadia Kusuma (99000004)

## Konteks
Perpustakaan kampus melayani 1.000 anggota aktif sekarang dan dirancang
untuk 10.000 (kebutuhan.md): puncak 200 req/s saat minggu ujian (±5× hari
biasa), aplikasi 2 vCPU total, target uptime 99,9%. Tim IT perpustakaan
hanya 1–2 orang dan yang dikuasai adalah Docker, compose, dan kind
(P7–P8). Kriteria berbobot tertinggi di matriks: biaya (25) lalu keahlian
tim dan keandalan (masing-masing 20).

## Keputusan
Kami menjalankan image Docker yang sama di layanan container terkelola:
**sekarang Render** (paket gratis, lingkungan latihan) dan **target ECS
Fargate di belakang Application Load Balancer** (AWS Jakarta), dengan model
deployment public cloud.

## Alternatif yang ditolak
- ALT 4 Serverless, Lambda + Bref (skor matriks 3,65, termurah ±US$245):
  aplikasi harus dipecah menjadi fungsi dan memakai runtime kustom PHP;
  tanpa RDS Proxy setiap lonjakan membuka ratusan koneksi database.
- ALT 1 VM / EC2 (skor 3,40, ±US$314): OS, SSH, dan patch diurus tim 1–2
  orang; menambah VM saat minggu ujian lambat.
- ALT 3 Kubernetes terkelola / EKS (skor 3,15, ±US$387): control plane
  US$73/bulan dan klaster yang harus dirawat untuk satu aplikasi kecil.

## Konsekuensi
- (+) Satu image berjalan sama di compose, kind, Render, dan Fargate;
  tidak ada OS yang di-patch tim.
- (+) Auto scaling per task (min 2, maks 6) cukup untuk minggu ujian.
- (-) Lebih mahal ±7% dari VM dan ±38% dari serverless pada beban rata-rata.
- (-) Uji kepekaan: pemenang baru berganti bila bobot biaya ≥ 43 (geser 18
  poin). Bila anggaran dipotong besar, serverless perlu ditimbang ulang.
- (-) Lock-in pada task definition ECS dan ALB; exit plan di keputusan.md
  bagian 6 (image OCI standar + PostgreSQL standar + Terraform).
- Ditinjau ulang bila beban puncak > 400 req/s atau anggaran turun > 40%.
