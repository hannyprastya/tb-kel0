# 0006 · Memakai antrean + DLQ untuk pengingat jatuh tempo
Status: Diterima · Tanggal: 2026-11-17 · Penulis: Raka Pratama (99000001)

## Konteks
Pengingat H-1 jatuh tempo dikirim lewat email/WhatsApp. Satu panggilan API
pihak luar ±1–5 detik dan kadang gagal. Pada 10.000 pengguna ada ±100–300
pengingat per hari, menumpuk di minggu ujian. Bila dikirim langsung di
dalam request, halaman petugas menunggu dan worker Apache (4 per instance,
P13 C.1) cepat habis. Pengingat boleh tertunda sampai 1 jam
(kebutuhan.md bagian 4).

## Keputusan
Aplikasi hanya **menitipkan satu pesan per pinjaman** ke antrean
(`POST /pengingat/kirim` → 202) dan **worker terpisah** (Lambda PHP + Bref)
yang mengirim. Parameter dari docs/komponen.md bagian 5: timeout worker
10 s, visibility 60 s, maxReceiveCount 5 → DLQ setelah ±5 menit, DLQ
disimpan 14 hari. Kunci idempotensi `pinjam-<no>-<jatuh tempo>` dibuat
aplikasi, dicek worker sebelum mengirim. Kode: `infra/antrean`,
`serverless/`.

## Alternatif yang ditolak
- Kirim langsung saat request (sinkron): halaman ikut lambat dan gagal bila
  layanan email gagal; tidak ada retry.
- Cron yang langsung mengirim semua email dalam satu proses: satu kegagalan
  di tengah membuat sisa pengingat tidak terkirim dan sulit diulang tanpa
  mengirim dobel.
- Pub/sub (SNS) tanpa antrean: satu event ke banyak pelanggan tidak
  dibutuhkan; yang dibutuhkan pembagian kerja + retry + DLQ.

## Konsekuensi
- (+) Halaman tetap cepat (202 dalam ±0,1 s di lab); kegagalan email
  di-retry otomatis dan pesan rusak terparkir di DLQ untuk diperiksa.
- (+) Terbukti di Floci (P14 F): retry 3× lalu DLQ, kirim ulang id sama
  tetap satu pengingat.
- (-) Komponen bertambah tiga (antrean, DLQ, worker) yang harus dipantau:
  alarm "DLQ tidak kosong" masuk runbook.
- (-) Antrean menjamin paling sedikit sekali, bukan tepat sekali: tanpa cek
  idempotensi anggota bisa menerima pengingat dobel.
