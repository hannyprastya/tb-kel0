# 0005 · Pengingat jatuh tempo lewat antrean job + worker (dan DLQ di target)
Status: Diterima · Tanggal: 2026-11-17 · Penulis: Raka Pratama (99000001)

## Konteks
Pengingat H-1 jatuh tempo dikirim lewat email/WhatsApp. Satu panggilan API
pihak luar ±1–5 detik dan kadang gagal. Pada 10.000 pengguna ada ±100–300
pengingat per hari, menumpuk di minggu ujian. Bila dikirim di dalam
request, halaman petugas menunggu dan worker Apache (4 per instance, P13
C.1) cepat habis. Pengingat boleh tertunda sampai 1 jam (kebutuhan.md
bagian 4). Sejak P12 aplikasi memakai starter Laravel (ADR 0004) yang
sudah punya antrean job (`ShouldQueue`), service `worker`
(`queue:work --tries=3`), service `scheduler` (`schedule:work`), dan tabel
`notifikasi` dengan kunci unik.

## Keputusan
- Tugas terjadwal `kasus:pengingat-jatuh-tempo` (setiap hari **07.00 WIB**,
  `routes/kasus-jadwal.php`, `onOneServer`) hanya **menitip satu job per
  peminjaman** yang jatuh tempo paling lambat besok; job
  `KirimPengingatJatuhTempo` dikerjakan **worker**, bukan request.
- **Kunci idempotensi** `pinjam-<no>-<jatuh tempo>` dibuat dari data
  (bukan acak) dan dicatat di `notifikasi` (UNIQUE) dalam transaksi yang
  sama dengan pengiriman; job yang diulang, perintah yang dijalankan dua
  kali, atau pesan SQS ganda tetap satu pengingat.
- **Antrean:** produksi latihan memakai antrean **database** (tabel `jobs`
  di Neon, worker di container Render yang sama); target memakai **SQS +
  DLQ** (`infra/antrean`, `QUEUE_CONNECTION=sqs`) dengan service worker
  ECS 1–2 task. Parameter dari docs/komponen.md bagian 5: timeout job 10 s,
  visibility 60 s, **5 percobaan dengan jeda 1, 3, 10, 20 menit** (total
  ±34 menit, masih di bawah batas tunda 1 jam) lalu `failed_jobs`;
  `maxReceiveCount` 5 → DLQ hanya menangkap pesan yang membuat worker mati
  sebelum sempat mencatat gagal; DLQ disimpan 14 hari.

## Alternatif yang ditolak
- Kirim langsung saat request (sinkron): halaman ikut lambat dan gagal bila
  layanan email gagal; tidak ada retry.
- Satu cron yang langsung mengirim semua email dalam satu proses: satu
  kegagalan di tengah membuat sisa pengingat tidak terkirim dan sulit
  diulang tanpa mengirim dobel.
- **Fungsi Lambda + Bref** sebagai worker (cara P14 F di buku tamu): US$0
  di free tier dan tanpa server, tetapi butuh runtime kustom PHP dan
  paket kode kedua di luar image Laravel; worker Laravel memakai image,
  model, dan log yang sama dengan web (+US$11,25/bulan untuk satu task
  Fargate kecil, biaya.md). Ditinjau ulang bila pengingat > 10.000/hari.
- Pub/sub (SNS) tanpa antrean: satu event ke banyak pelanggan tidak
  dibutuhkan; yang dibutuhkan pembagian kerja + retry + DLQ.

## Konsekuensi
- (+) Perintah harian hanya menitip: 200 pengingat dititipkan dalam 0,9 s
  (termasuk `docker compose exec`), dan satu worker mengirim semuanya
  dalam 5,5 s sejak perintah (lab, antrean database, 2026-11-23).
- (+) Dibuktikan di lab: job diulang → 1 pengingat (Feature test), perintah
  dijalankan dua kali → `dititipkan=0` (K-17), lewat SQS Floci → job
  diambil worker (K-16 dengan `QUEUE_CONNECTION=sqs`).
- (+) Pengingat yang terlewat ikut terkirim pada jalan berikutnya: perintah
  memilih semua yang jatuh tempo ≤ besok dan belum punya catatan.
- (−) Komponen bertambah (worker, scheduler, antrean/DLQ) yang harus
  dipantau: "job gagal > 0" dan "DLQ tidak kosong" masuk runbook.
- (−) Render Free tidur setelah ±15 menit sepi; dalam mode satu container
  scheduler ikut tidur, jadi jadwal 07.00 bisa terlewat sampai ada
  pengunjung yang membangunkan layanan (evaluasi.md).
- (−) Antrean menjamin paling sedikit sekali, bukan tepat sekali: tanpa
  kunci idempotensi anggota bisa menerima pengingat dobel.
