# Kasus 0 · Perpustakaan kampus (modul Laravel, contoh dosen)

Contoh modul untuk kasus **di luar Tabel K**, dipakai repo contoh
`tb-kel0`; tidak ikut zip modul mahasiswa. Bentuknya sama dengan 10 modul
kasus (lihat [KASUS.md](KASUS.md)).

Anggota mencari katalog **tanpa login** (halaman publik, boleh di-cache).
Anggota yang **login** melihat rincian buku dan membuka scan e-dokumen lewat
tautan bertanda tangan 5 menit. **Petugas sirkulasi** (login + terdaftar di
tabel `petugas`) menambah buku, mengunggah sampul/scan ke object storage,
dan mencatat pinjam dan kembali. Stok eksemplar tidak pernah minus walau
banyak petugas meminjamkan judul yang sama di detik yang sama.

## Pasang dan lepas

```bash
cp -R kasus-00-perpustakaan/. web/   # di repo contoh berkasnya sudah di web/
docker compose up -d --build --wait        # migrasi berjalan otomatis
docker compose exec app php artisan kasus:petugas EMAIL_AKUNMU   # jadikan petugas
bash tests/fungsional.sh                   # F-01..F-19 + K-01..K-20
docker compose run --rm --build uji        # PHPUnit, termasuk tests/Feature/Kasus
```

Melepas: `docker compose exec app php artisan migrate:rollback --step=3 --force`,
hapus berkas yang dulu disalin, lalu build ulang.

## Rute

| Metode dan alamat | Grup | Siapa | Isi |
|---|---|---|---|
| `GET /buku?q=` | publik | siapa saja | katalog + cari judul/penulis/kode, 50 baris, boleh di-cache 60 s |
| `GET /buku/{id}/sampul` | publik | siapa saja | gambar sampul dari object storage, boleh di-cache |
| `GET /buku/{id}` | web + auth | anggota (semua yang login) | rincian, sampul, tautan scan 5 menit |
| `GET /buku/{id}/scan` | web + signed | pemegang tautan | unduh scan PDF |
| `GET /buku/kelola` | web + auth | petugas | ringkasan, peminjaman aktif, formulir pinjam/kembali/tambah |
| `POST /buku` | web + auth | petugas | tambah buku (+ sampul JPG/PNG/WebP ≤ 1 MB, scan PDF ≤ 5 MB) |
| `POST /buku/{id}/berkas` | web + auth | petugas | ganti sampul dan/atau scan; berkas lama dihapus |
| `POST /buku/pinjam` | web + auth | petugas | `buku`, `anggota` (A-NNNN), `hari` 1–14 (bawaan 7) → 302, atau JSON 201 / 409 "eksemplar sedang dipinjam semua" |
| `POST /buku/kembali` | web + auth | petugas | `pinjam` → 302, atau JSON 200 / 404 "peminjaman aktif tidak ada" |
| `GET /api/buku/status` | api | siapa saja | `{judul, eksemplar, tersedia, dipinjam, terlambat, jatuh_tempo_besok}` (k6, uptime monitor) |
| `GET /api/buku?q=` | api | siapa saja | 20 judul pertama yang cocok |
| `GET /api/buku/{id}` | api | siapa saja | satu judul + `tersedia` |
| `GET /api/buku/pinjam/{id}` | api | siapa saja | status peminjaman + `pengingat_terkirim` (tanpa nomor anggota) |

Padanan fitur lama (`index.php` P9–P11): `/buku/status` →
`/api/buku/status`; `POST /buku/pinjam` dan `/buku/kembali` sekarang hanya
untuk petugas yang login (dulu terbuka); kode 400 → 422 (validasi
Laravel); 409 dan 404 tetap.

## Fitur Laravel yang dipakai

| Fitur | Di kasus ini |
|---|---|
| Blade + Eloquent + migrasi | tabel `petugas`, `buku`, `pinjam`; model `App\Models\Kasus\{Buku, Pinjam, Petugas}` |
| Login (Breeze) | katalog tanpa login; rincian untuk anggota login; sirkulasi hanya untuk akun di tabel `petugas` (`php artisan kasus:petugas EMAIL`, cabut dengan `--cabut`), selain itu 403 |
| Unggah ke object storage | sampul dan scan, jenis diperiksa dari isi berkas, nama acak `buku/sampul/…`, `buku/scan/…` di disk `s3` |
| Antrean (queue) | job `KirimPengingatJatuhTempo`: idempoten lewat kunci unik `pinjam-ID-TANGGAL` di tabel `notifikasi`; 5 percobaan, jeda 1/3/10/20 menit |
| Tugas terjadwal | `kasus:pengingat-jatuh-tempo` tiap hari 07.00: titip pengingat untuk pinjaman aktif yang jatuh tempo ≤ besok dan belum diingatkan |
| Halaman publik | `/buku`, `/buku/{id}/sampul` (tanpa sesi, `Cache-Control: public`) |

## Jaminan saat ramai

- Stok tidak pernah minus: satu `UPDATE buku SET tersedia = tersedia - 1
  WHERE id = ? AND tersedia > 0` (baris dikunci PostgreSQL, syarat dicek
  ulang setelah kunci) + `CHECK (tersedia BETWEEN 0 AND stok)` di database.
  Uji K-14: 30 pinjam serentak ke judul berisi 2 eksemplar → 2 × 201,
  28 × 409, tersedia 0.
- Satu peminjaman hanya dikembalikan sekali: baris pinjam dikunci
  (`FOR UPDATE`) dan `dikembalikan_pada IS NULL` dicek ulang.
- Database lama dari `index.php` (tabel `buku`/`pinjam` tanpa kolom
  `kode`) tidak dihapus: migrasi mengganti namanya menjadi `buku_lama` dan
  `pinjam_lama`.

## Data

Semua data contoh fiktif (nomor anggota `A-NNNN`, penulis "Tim Penyusun").
Jangan memasukkan nama, nomor induk, atau email anggota sungguhan.
