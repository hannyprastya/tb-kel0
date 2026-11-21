# 0002 · Memakai PostgreSQL terkelola dengan connection pooling
Status: Diterima · Tanggal: 2026-10-23 · Penulis: Salsa Nuraini (99000002)

## Konteks
Data inti perpustakaan bersifat relasional dan harus konsisten: buku,
eksemplar tersedia, peminjaman. Dua petugas bisa mencatat pinjam buku yang
sama di detik yang sama; stok tidak boleh minus (kebutuhan fungsional 2).
Volumenya kecil (±240 MB/tahun untuk 10.000 pengguna) tetapi berisi data
pribadi (no. anggota, riwayat pinjam), butuh backup, dan aplikasi P5–P8
sudah memakai PostgreSQL 17 lewat PDO. Hasil ukur P8: CPU database per
request lebih besar dari CPU aplikasi karena setiap request membuka koneksi
baru.

## Keputusan
Kami memakai **PostgreSQL 17 terkelola**: sekarang **Neon Free** (Singapura,
URL pooled, PITR 6 jam), target **Amazon RDS for PostgreSQL Multi-AZ**
(db.t4g.medium, Jakarta) dengan **pooler** (RDS Proxy atau PgBouncer)
supaya koneksi per request tidak menghabiskan CPU database. Konsistensi
stok dijaga di database: `UPDATE ... WHERE tersedia > 0` dan
`CHECK (tersedia BETWEEN 0 AND stok)`.

## Alternatif yang ditolak
- PostgreSQL di container/VM sendiri: backup, patch, dan failover diurus tim
  1–2 orang; tidak ada PITR.
- DynamoDB / database NoSQL: pinjam-kembali butuh transaksi dan constraint
  antar tabel; aplikasi harus ditulis ulang.
- MySQL terkelola: setara, tetapi aplikasi, compose, kind, dan Neon sudah
  PostgreSQL; pindah tidak memberi manfaat.

## Konsekuensi
- (+) Backup otomatis dan PITR (Neon 6 jam; RDS sampai 35 hari, RPO ±5
  menit); failover Multi-AZ ±1–2 menit.
- (+) Aturan stok dijamin database, bukan hanya kode PHP; diuji dengan
  pinjam serentak di P10.
- (-) Multi-AZ menggandakan biaya database (US$77 → US$154/bulan) dan
  menjadi ±46% biaya rancangan target.
- (-) Neon Free tanpa SLA dan bisa "tidur" saat sepi; koneksi pertama lebih
  lama.
- Ditinjau ulang bila CPU database rata-rata > 60% di minggu ujian setelah
  pooling (naik ke kelas instance non-burstable).
