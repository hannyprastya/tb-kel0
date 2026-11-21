# 0008 · Batas waktu koneksi database 3 detik + halaman 503 ramah
Status: Diterima · Tanggal: 2026-11-20 · Penulis: Dimas Ardiansyah (99000003)

## Konteks
Sebelum P15, saat database menggantung (`docker compose pause db`) halaman
`/`, `/buku`, dan `POST /tamu` menunggu 5,0 s lalu menjawab **500 kosong**;
saat database mati (`stop`) jawabannya 500 kosong dalam ±0,01 s
(evaluasi P15 Tabel 5, diukur ulang 2026-11-22). Tanpa `PDO::ATTR_TIMEOUT`
PDO menunggu 30 s. Satu permintaan yang menunggu memegang satu dari 4
worker Apache (P13 C.1), jadi 4 permintaan yang menggantung sudah cukup
membuat semua halaman antre. Alert P11 menghitung respons 5xx.

## Keputusan
Batas waktu koneksi diturunkan dari 5 s ke **3 s**, dan semua jalur
database (`/`, `POST /tamu`, rute `/buku*`, `/pengingat/kirim`) menangkap
`PDOException` lewat `maaf()`: catat `DB gagal: …` ke log, jawab **503 +
`Retry-After: 60`**, tampilkan judul dan pesan "coba lagi 1 menit lagi",
formulir tidak ditampilkan. `/health` tetap 503 JSON, `/healthz` tetap 200.

## Alternatif yang ditolak
- Tanpa batas waktu (bawaan 30 s): worker habis dalam hitungan detik saat
  database menggantung.
- Retry otomatis 1 → 2 → 4 s di dalam request: pengguna menunggu sampai 19 s
  dan database yang baru pulih dihantam ulang (D.4 no. 1).
- Menjawab 200 dengan pesan ramah: alert 5xx menjadi buta (D.4 no. 2).
- Circuit breaker: butuh status bersama antar proses PHP (APCu/Redis); belum
  sepadan untuk satu database.

## Konsekuensi
- (+) Sesudah: `pause` → 503 ramah dalam **3,0 s** (dulu 500 dalam 5,0 s);
  `stop` → 503 ramah dalam ±0,005 s. Alert tetap Firing ±48–52 s setelah
  gangguan (pengujian.md Tabel 5).
- (+) Data tidak hilang; setelah db hidup semua rute kembali 200 dalam ±2 s.
- (-) Koneksi yang sebenarnya akan berhasil dalam > 3 s (mis. Neon bangun
  dari tidur) ikut dianggap gagal: sesekali permintaan pertama di Render 503.
- (-) Kegagalan **di tengah** query (setelah koneksi berhasil) di rute kasus
  masih 500; `/lambat` (rute latihan) sengaja tidak diubah.
