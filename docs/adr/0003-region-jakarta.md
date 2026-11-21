# 0003 · Provider AWS di region Jakarta (ap-southeast-3) untuk data asli
Status: Diterima · Tanggal: 2026-10-23 · Penulis: Raka Pratama (99000001)

## Konteks
Kampus dalam kasus berstatus swasta: menurut PP 71/2019 Pasal 21 sistemnya
boleh di luar negeri asal pengawasan efektif, jadi lokasi bukan syarat
wajib. Tetapi data anggota (nama, email, riwayat pinjam) adalah data pribadi
menurut UU PDP; PP 33/2026 (berlaku 16 Januari 2027) membuat transfer ke
luar negeri bertingkat dan pemberitahuan kegagalan pelindungan 3 × 24 jam.
Pengguna semuanya di Indonesia. Kode Terraform P6 (bucket) dan emulator
AWS yang dipakai sejak P3 sudah memakai API AWS.

## Keputusan
Untuk data asli kami memilih **AWS region Asia Pacific (Jakarta), kode
`ap-southeast-3`**, dua zona ketersediaan. Lingkungan latihan Tugas Besar
(Render + Neon di Singapura, paket gratis) **hanya berisi data contoh**:
no. anggota fiktif dan nama uji, tanpa data pribadi sungguhan.

## Alternatif yang ditolak
- Tetap di Singapura (Render/Neon berbayar, atau AWS `ap-southeast-1`):
  latensi ±15–25 ms lebih besar dan transfer data pribadi ke luar negeri
  harus diatur lewat perjanjian dan penilaian PP 33/2026.
- Azure Indonesia Central: layanan setara (Container Apps, PostgreSQL
  Flexible Server), tetapi kode Terraform dan emulator kami ber-API AWS;
  harus ditulis ulang.
- Google Cloud Jakarta (`asia-southeast2`): sama alasannya dengan Azure.

## Konsekuensi
- (+) Data tetap di Indonesia: audit lebih mudah, dan bila kampus menjadi
  PTN (lingkup publik, PP 71/2019 Pasal 20) tidak perlu pindah.
- (+) Satu provider untuk compute, database, object storage, antrean, CDN.
- (-) Harga Jakarta sedikit di atas Singapura; satu region saja di Indonesia
  sehingga DR lintas region berarti data keluar negeri.
- (-) Selama latihan, data di Render/Neon Singapura: siapa pun di kelompok
  dilarang memasukkan data pribadi sungguhan (etika P9).
- Ditinjau ulang bila regulasi berubah atau layanan yang dibutuhkan tidak
  tersedia di Jakarta.
