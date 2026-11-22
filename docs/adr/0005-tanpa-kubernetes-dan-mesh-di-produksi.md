# 0005 · Tidak memakai Kubernetes dan service mesh di produksi
Status: Diterima · Tanggal: 2026-11-13 · Penulis: Dimas Ardiansyah (99000003)

## Konteks
Di lab P13 setiap anggota memasang HPA dan Linkerd di kind. Hasilnya
(Tabel 1–2 laporan P13, contoh angka acuan modul): replika naik 3 → 6 dalam
±67 detik dan turun lagi ±5 menit 49 detik setelah beban hilang; Linkerd
memberi mTLS dan metrik emas tanpa mengubah kode. Harganya: control plane
Linkerd + viz ±1 GB RAM di klaster lab, satu proxy per Pod (±5–6 MiB, CPU
naik saat beban), dan langkah tambahan supaya HPA tetap jalan
(`proxy-cpu-request`). Sistem kami: **satu aplikasi + satu database**,
target 4 task di 2 zona, tim IT 1–2 orang.

## Keputusan
Produksi target memakai **ECS Fargate dengan auto scaling bawaan** (min 2,
maks 6 task, target CPU 60%: angka yang sama dengan HPA P13) dan **tanpa
service mesh**. Manifest `deploy/k8s/app.yaml` + `deploy/k8s/hpa.yaml` tetap
dirawat untuk lab dan sebagai exit plan.

## Alternatif yang ditolak
- EKS + HPA (skor matriks 3,15): control plane US$73/bulan (±22% biaya
  target) hanya untuk satu Deployment; upgrade versi klaster tiap ±4 bulan.
- Service mesh (Linkerd/Istio): mTLS antar layanan tidak dibutuhkan bila
  hanya ada web → database (koneksi database sudah TLS, akses dibatasi
  security group). Satu proxy per Pod menambah RAM dan langkah debug.
- Tanpa auto scaling (4 task tetap): aman tetapi membayar 4 task sepanjang
  tahun, padahal puncak hanya ±8 dari 52 minggu (lihat biaya.md).

## Konsekuensi
- (+) Hemat ±US$73/bulan dan tidak ada klaster yang harus di-upgrade.
- (+) Angka auto scaling dari lab HPA tetap dipakai (CPU 60%, min 2, maks 6).
- (-) Tidak ada mTLS antar container; bila kelak ada ≥ 3 layanan yang saling
  memanggil, ADR ini ditinjau ulang.
- (-) Kemampuan Kubernetes kelompok hanya terlatih di lab, bukan produksi.
