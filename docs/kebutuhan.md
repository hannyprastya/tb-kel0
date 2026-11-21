# Kebutuhan Sistem · Perpustakaan Kampus (Kasus 0)

> Contoh dari dosen untuk kasus di luar Tabel K. Angka di bawah dihitung
> dari hasil ukur aplikasi kelompok sendiri (P8 Bagian A) dan asumsi yang
> ditulis sumbernya.

Perpustakaan pusat sebuah kampus swasta (±6.000 mahasiswa, ±300 dosen dan
staf) ingin memindahkan katalog dan layanan pinjam-kembali ke aplikasi web.
Masalah yang dirasakan sekarang: katalog hanya bisa dicari di komputer
perpustakaan, stok eksemplar dicatat manual sehingga sering selisih, dan
anggota sering lupa tanggal kembali karena tidak ada pengingat. Sampul buku
dan scan e-dokumen (skripsi, modul) tersebar di harddisk petugas.

## 1. Workload
- **Jenis:** web (OLTP kecil: cari katalog, catat pinjam/kembali) ditambah
  pekerjaan latar (kirim pengingat jatuh tempo) dan penyimpanan berkas
  (sampul, scan PDF).
- **Pola beban:** musiman. Puncak di minggu UTS dan UAS (±4 minggu per
  semester), pukul 08.00–15.00. Saat puncak ≈ 5× hari biasa (perkiraan
  petugas: ±400 transaksi pinjam/kembali per hari di minggu ujian, ±80 di
  hari biasa). Malam dan libur semester hampir sepi.
- **Paling dulu habis:** CPU database. Hasil ukur P8: per permintaan
  katalog, CPU database (3,7–5,1 ms) sebanding dengan CPU aplikasi
  (3,6–4,3 ms), karena setiap permintaan membuka koneksi baru dan mengecek
  tabel. Aplikasi bisa ditambah instancenya, database tetap satu.
  Untuk berkas, yang habis adalah **jaringan** (sampul dan PDF jauh lebih
  besar dari halaman HTML).
- **Strategi kapasitas:** terjadwal + auto scaling. Kapasitas dinaikkan
  sebelum minggu ujian (jadwal akademik sudah diketahui), auto scaling
  menangani sisa lonjakan; pengingat lewat antrean supaya tidak membebani
  halaman.

## 2. Asumsi
| Asumsi                           | Nilai     | Sumber / alasan |
|----------------------------------|-----------|-----------------|
| Pengguna bersamaan saat puncak   | 10%       | minggu ujian: banyak mahasiswa mencari buku referensi di jam yang sama (angka slide 9) |
| Jeda antar request               | 5 dtk     | orang membaca hasil cari sebelum klik lagi (slide 9) |
| Waktu CPU aplikasi per request   | 4 ms      | hasil ukur P8: CPU app ±148% pada 411 req/s → 1,48 ÷ 411 = 0,0036 dtk, dibulatkan |
| Faktor koreksi vCPU cloud        | 2×        | asumsi: satu vCPU cloud lebih lambat dari satu core laptop M1 yang dipakai mengukur → **8 ms** dipakai di hitungan |
| Waktu CPU database per request   | 5 ms (×2 = 10 ms) | hasil ukur P8: CPU db ±210% pada 411 req/s |
| RAM aplikasi saat dibebani       | 17 MB     | `docker stats` P8 (4 worker Apache) |
| Data transaksi per pengguna/bulan | 2 KB     | ±2 peminjaman × ±1 KB per baris + indeks |
| Koleksi berkas awal              | 13 GB     | ±20.000 sampul × 150 KB = 3 GB + ±2.000 scan e-dokumen × 5 MB = 10 GB (data petugas) |
| Berkas baru per tahun            | 1,5 GB    | ±300 scan skripsi baru × 5 MB |
| Ukuran satu halaman katalog      | 306 KB    | HTML 50 baris ±6 KB (diukur 693 B untuk 3 baris) + 20 sampul kecil × 15 KB |
| Headroom                         | 30%       | slide 10 |

Hasil ukur P8 diambil di laptop yang sedang dipakai proses lain (load
average ±15), jadi angka CPU per request cenderung **lebih besar** dari
aslinya; untuk perencanaan itu aman.

## 3. Tabel kebutuhan
| Kebutuhan                        | 1.000 pengguna | 10.000 pengguna |
|----------------------------------|----------------|-----------------|
| Bersamaan saat puncak            | 100            | 1.000           |
| Request per detik                | 20             | 200             |
| vCPU aplikasi (+ headroom)       | 0,21 → **1 vCPU** | 2,08 → **2 vCPU total** (4 × 0,5 vCPU, 2 zona) |
| vCPU database (+ headroom)       | 0,26 → 1 vCPU (burstable) | 2,6 → **2 vCPU** setelah koneksi di-pool (lihat catatan) |
| RAM aplikasi                     | 0,67 GB → **1 GB** | 1 GB per instance × 4 |
| Disk database 1 tahun (+ backup) | 24 MB → 48 MB (minimal 20 GB disk) | 240 MB → 480 MB (minimal 20 GB disk) |
| Object storage 1 tahun (+ versi) | 14,5 GB → 29 GB | 14,5 GB → 29 GB (koleksi tidak bergantung jumlah pengguna) |
| Bandwidth puncak (halaman + sampul) | 49 Mbps     | 490 Mbps (sebagian besar sampul → CDN) |
| Bandwidth puncak dari aplikasi saja | 1 Mbps      | 9,6 Mbps |

Langkah hitungan (1.000 → 10.000 pengguna):
1. Bersamaan = 1.000 × 10% = 100 → 10.000 × 10% = 1.000.
2. RPS = 100 ÷ 5 = 20 → 1.000 ÷ 5 = 200.
3. vCPU aplikasi = 20 × 0,008 = 0,16 → × 1,3 = 0,21 → dibulatkan 1 vCPU.
   10.000: 200 × 0,008 = 1,6 → × 1,3 = 2,08 → 2 vCPU total. Dipecah menjadi
   4 instance kecil 0,5 vCPU supaya bisa tersebar di 2 zona dan tetap
   jalan bila satu instance mati (tidak ada satu titik gagal).
4. vCPU database = 20 × 0,010 = 0,2 → × 1,3 = 0,26. 10.000: 200 × 0,010 =
   2,0 → × 1,3 = 2,6 vCPU. **Catatan:** sebagian besar CPU database habis
   untuk membuka koneksi baru dan mengecek tabel di setiap request (ukur
   ulang dengan `ab`: `/health` yang hanya koneksi + `SELECT 1` memakai
   ±2,8 ms CPU database per request, `/buku` ±3,7 ms, jadi ±75%). Dengan
   connection pooling angka ini turun; karena itu rancangan target memakai
   pooler (ADR 0002) dan 2 vCPU.
5. RAM = (17 MB + 500 MB OS) × 1,3 = 672 MB → 1 GB per instance.
6. Disk database = 1.000 × 2 KB × 12 = 24 MB; × 2 untuk backup = 48 MB.
   10.000: 240 MB → 480 MB. Kecil sekali, jadi ukuran disk ditentukan batas
   minimal layanan (20 GB), bukan data.
7. Object storage = 13 GB + 1,5 GB = 14,5 GB; × 2 (versioning) = 29 GB.
8. Bandwidth = 20 × 306 KB × 8 = 49 Mbps; 200 × 306 KB × 8 = 490 Mbps.
   Tanpa sampul (hanya HTML 6 KB): 20 × 6 × 8 = 0,96 Mbps; 200 × 6 × 8 =
   9,6 Mbps. Selisih inilah alasan sampul disajikan lewat CDN.

## 4. Target layanan
- **Uptime:** 99,5% per bulan untuk 1.000 pengguna (boleh mati ±3 jam 39
  menit/bulan, tabel nines P10: setara portal informasi kampus); **99,9%**
  untuk 10.000 pengguna, karena pinjam-kembali sudah menjadi layanan inti
  (±43,8 menit/bulan).
- **Latency:** p95 halaman katalog `/buku` di bawah **1 detik** pada 100
  pengguna bersamaan (k6, P10); target internal (SLO P11) lebih ketat.
- **Data:** RPO ≤ 24 jam dan RTO ≤ 4 jam untuk 1.000 pengguna; RPO ≤ 5
  menit dan RTO ≤ 1 jam untuk 10.000 pengguna (dihitung ulang di P15).
- **Pengingat:** sampai ke anggota paling lambat 1 jam setelah jadwal kirim
  (07.00 WIB, H-1 jatuh tempo).

## 5. Kebutuhan fungsional
1. Anggota mencari buku berdasarkan judul atau penulis dan melihat jumlah
   eksemplar yang tersedia.
2. Petugas mencatat peminjaman (no. buku + no. anggota) dengan jatuh tempo 7
   hari. Eksemplar yang sedang dipinjam semua tidak bisa dipinjamkan lagi;
   stok **tidak boleh minus** walau dua petugas mencatat di detik yang sama.
3. Petugas mencatat pengembalian; satu peminjaman hanya bisa dikembalikan
   satu kali.
4. Anggota menerima pengingat H-1 jatuh tempo (email/WhatsApp), dikirim di
   belakang layar (tidak membuat halaman lambat).
5. Sampul dan scan e-dokumen disimpan di object storage privat; sampul bisa
   dibuka publik lewat CDN, e-dokumen hanya untuk anggota (rencana).
6. Pengunjung mengisi buku tamu perpustakaan (aplikasi dasar P5–P7).
7. Petugas dan monitor bisa membaca ringkasan stok dalam JSON
   (`/buku/status`).
