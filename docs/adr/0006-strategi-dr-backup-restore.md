# 0006 · Strategi DR: backup & restore + Multi-AZ di satu region, tanpa lintas region
Status: Diterima · Tanggal: 2026-11-20 · Penulis: Salsa Nuraini (99000002)

## Konteks
Target: RPO ≤ 24 jam / RTO ≤ 4 jam sekarang, RPO ≤ 5 menit / RTO ≤ 1 jam
untuk 10.000 pengguna (kebutuhan.md). Yang ada sekarang: PITR Neon Free 6
jam, belum ada salinan harian untuk database kelompok. Latihan pulih P15
B.3 (2026-11-23, database compose, 2.015 buku): `pg_dump -Fc` 0,2 s
(705 KB), `pg_restore` ke database baru 0,7 s, count `buku`, `pinjam`,
`tamu`, `notifikasi`, `users` sama; RTO terukur ≈ deteksi 43–51 s +
restore ±1 s + ganti `DATABASE_URL` dan deploy ulang ±3 menit *(ilustrasi)*.
Berkas sampul/scan ada di Supabase Storage (bucket privat, terpisah dari
database), tidak ikut `pg_dump`. Data
anggota sebaiknya tetap di Indonesia (ADR 0003), dan AWS hanya punya satu
region di Indonesia.

## Keputusan
- **Sekarang:** backup & restore. `pg_dump -Fc` harian pukul 01.30 WIB
  (cron P5 diarahkan ke Neon kelompok), salinan disimpan di laptop pembina
  di Indonesia, tidak di repo; latihan pulih diulang setiap awal semester.
  Berkas di Supabase disalin mingguan (`aws s3 sync` dengan kunci S3
  Supabase) ke laptop yang sama.
- **Target:** RDS Multi-AZ (failover otomatis) + automated backup/PITR 14
  hari + snapshot harian, S3 dengan versioning, semuanya di `ap-southeast-3`.
  **Tidak** ada salinan lintas region.

## Alternatif yang ditolak
- Pilot light / warm standby di Singapura: RTO bencana region turun dari
  jam ke menit, tetapi data anggota keluar Indonesia (perlu penilaian
  transfer PP 33/2026) dan biaya DB naik ±50%.
- Hanya mengandalkan PITR Neon 6 jam: kesalahan yang baru ketahuan besok
  pagi sudah di luar jendela; project yang terhapus ikut menghapus riwayat.
- Backup disimpan sebagai artifact GitHub Actions: repo publik, isi dump
  berisi data pribadi.

## Konsekuensi
- (+) RPO 24 jam dan RTO ±4–5 menit (+ waktu orang) memenuhi target sekarang;
  target 10.000 pengguna dipenuhi Multi-AZ + PITR (RPO ±5 menit).
- (-) Gangguan seluruh region Jakarta = layanan mati sampai region pulih
  (diterima: perpustakaan masih bisa mencatat manual sementara).
- (-) Backup harian di laptop bergantung satu orang dan satu perangkat
  (SPOF non-teknis, evaluasi.md bagian 1); tindak lanjut di postmortem.md.
