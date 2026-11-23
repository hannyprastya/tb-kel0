# Postmortem latihan gangguan P15: database putus (G1) dan restart aplikasi (G2)
2026-11-20 · SEV-2 (G1), SEV-3 (G2) · status: selesai · tanpa menyalahkan
IC: Salsa Nuraini (G1 ke-1), Raka Pratama (G1 ke-2) · pelaku: Dimas
Ardiansyah, Raka Pratama · pencatat waktu: Nadia Kusuma · notulis: Nadia
Kusuma

> Angka G1 diukur pada 2026-11-23 di aplikasi Laravel dengan stack OTel
> (`deploy/compose/otel.yml`): k6 `uji-otel.js` 20 VU selama 5 menit,
> loop pantau tiap 1 s ke `/buku` dan `/health`, status alert dibaca dari
> API Grafana tiap 2 s. Langkah runbook dijalankan berurutan oleh satu
> skrip; jeda "membaca runbook dan memastikan penyebab" disimulasikan 30 s.
> G2 di lab (restart container `app`) diukur; G2 di Render ditandai
> *(ilustrasi)* karena repo contoh belum punya layanan Render sungguhan.

## Hipotesis (ditulis sebelum mulai)
- G1 database putus: alert Firing ≤ 1 menit, pulih ≤ 3 menit, pengguna
  melihat pesan ramah 503 (bukan halaman error).
- G2 restart container: uptime monitor mendeteksi ≤ 5 menit, pulih otomatis
  ≤ 2 menit.

## Ringkasan
Database lab dihentikan dua kali saat beban berjalan; semua rute
menampilkan pesan ramah 503, alert 5xx menyala dalam 43–52 s, dan runbook
memulihkan layanan dalam 75–83 s. Restart aplikasi hanya ±1,8 s di lab dan
tidak terlihat oleh monitor 5 menit. Latihan tambahan "database macet"
(`pause`) menemukan bahwa rute biasa menjawab **500**, bukan 503; sudah
diperbaiki (bagian Latihan tambahan).

## Dampak
| Ke- | Kode | db dihentikan | T0 (503 pertama) | Pending | T1 (Firing) | db dinyalakan | T2 (`/buku` 200) | Normal | Deteksi | Pulih | Budget terpakai (216 menit) | Hipotesis |
|-----|------|---------------|------------------|---------|-------------|---------------|------------------|--------|---------|-------|-----------------------------|-----------|
| 1 | G1 | 22:33:49 | 22:33:50 | 22:34:11 | 22:34:41 | 22:35:12 | 22:35:13 | 22:36:11 | 51 s | 83 s | 1,38 ÷ 216 = 0,64% | terpenuhi |
| 2 | G1 | 22:39:08 | 22:39:08 | 22:39:21 | 22:39:51 | 22:40:22 | 22:40:23 | 22:41:21 | 43 s | 75 s | 1,25 ÷ 216 = 0,58% | terpenuhi |
| 3 | G2 lab | – | `restart app` | – | tidak terdeteksi | – | +1,8 s | – | – | 1,8 s | 0,01% | deteksi **tidak** terpenuhi |
| 4 | G2 Render | – | 10:02:10 *(ilustrasi)* | – | tidak terdeteksi | – | 10:02:58 *(ilustrasi)* | – | – | 48 s | 0,37% | deteksi **tidak** terpenuhi |
| | **Rata-rata G1** | | | | | | | | **47 s** | **79 s** | **0,61%** | |

k6 selama 5 menit: G1 ke-1 1.580 dari 5.820 permintaan gagal (27,1%), G1
ke-2 1.424 dari 5.820 (24,5%). Loop pantau mencatat 77 dan 69 baris 503.
Data tidak hilang. Log Loki `DB gagal: SQLSTATE[08006] …` membawa
`trace_id` yang membuka trace `GET /buku` berstatus ERROR di Tempo.

## Kronologi G1 ke-2 (WIB; nomor langkah = sequence diagram arsitektur.md bagian 6)
| Jam | Kejadian | Tindakan |
|-----|----------|----------|
| 22:39:08 | pelaku: `docker compose stop db` | – |
| 22:39:08 | T0: pantau `/:503 /health:503`; langkah 2 → alt "koneksi DB putus" (503 ramah, `Retry-After: 60`) | pencatat waktu mencatat |
| 22:39:21 | alert Pending (A = 1,0) | IC membuka dasbor SLO |
| 22:39:51 | T1: alert Firing (A = 1,0) | IC: SEV-2, buka Runbook URL |
| 22:39:52 | runbook 3.2: `docker compose ps -a` → db `Exited (0)`; log `DB gagal: SQLSTATE[08006] [7] could not translate host name "db"` | diagnosis: db mati |
| 22:40:22 | runbook 3.3: `docker compose start db` | – |
| 22:40:23 | T2: `/buku` dan `/health` 200, stabil 1 menit | kabar pulih |
| 22:41:21 | alert Normal | latihan ditutup |

## Latihan tambahan: database macet (`docker compose pause db`)
| Rute | Hipotesis | Hasil sebelum | Hasil sesudah perbaikan |
|------|-----------|---------------|-------------------------|
| `/buku`, `/api/buku/status` | 503 ramah dalam ≤ 3 s | **500** dalam 3,03 s | 503 ramah / JSON dalam 3,0 s |
| `/health` | 503 dalam ≤ 3 s | 503 dalam 3,03 s | sama |

Penyebab: saat koneksi habis waktu, `pdo_pgsql` memberi kode driver `7`
(bukan SQLSTATE) walau pesannya `SQLSTATE[08006] [7] … timeout expired`,
sehingga `DbGagal::cek()` menganggapnya bug SQL biasa. Uji F-19 starter
hanya memeriksa `/health`, karena itu kelompok menambah K-20.

Keputusan (Dimas, 2026-11-20):
- Batas waktu koneksi tetap **3 s** (`DB_TIMEOUT` bawaan starter).
  `DbGagal::cek()` juga membaca SQLSTATE dari pesan (`08xxx`,
  `57P01–57P03`), dibuktikan dengan `tests/Unit/DbGagalMacetTest.php` dan
  K-20. Controller modul tidak memakai `try/catch` sendiri (K-19).
- **Ditolak:** tanpa batas waktu (bawaan PDO ±30 s: 4 permintaan yang
  menggantung menghabiskan semua worker Apache); `DB_TIMEOUT=10` (pengguna
  menunggu 10 s untuk halaman error); retry 1 → 2 → 4 s di dalam request
  (sampai 19 s, dan database yang baru pulih dihantam ulang); menjawab 200
  dengan pesan ramah (alert 5xx menjadi buta); circuit breaker (butuh status
  bersama antar proses, belum sepadan untuk satu database).
- **Akibat:** koneksi yang sebenarnya berhasil dalam > 3 s (Neon bangun
  dari tidur) ikut dianggap gagal, sehingga sesekali permintaan pertama di
  Render 503. Berkas starter `app/Support/DbGagal.php` diubah kelompok;
  perubahannya dilaporkan ke pemilik starter supaya ikut masuk starter.

## Akar masalah dan faktor pendukung
- **Mengapa sistem membolehkan ini terjadi?** Satu database tanpa standby
  (SPOF, evaluasi.md bagian 1); di produksi Neon Free juga tanpa HA.
- Deteksi G2 bergantung pada interval monitor 5 menit; restart selesai di
  antara dua cek, jadi tidak ada alat yang melihatnya.
- Pemulihan G1 menunggu orang (±30 s dari Firing ke `start db`):
  membaca runbook dan memastikan penyebab.
- Uji yang ada hanya memeriksa `/health` untuk kasus macet, sehingga 500
  di rute biasa tidak ketahuan sampai latihan.

## Yang berjalan baik / kurang / beruntung
- Baik: pesan ramah 503 tampil di semua rute DB (katalog, API, sirkulasi),
  alert tetap menyala karena status tetap 5xx; deteksi 43–52 s.
- Baik: runbook 3.2 memakai `ps -a` (tindak lanjut P11) dan pesan log
  `DB gagal` langsung menunjuk penyebab.
- Kurang: G2 tidak terdeteksi; kasus "macet" menghasilkan 500 sampai
  diperbaiki.
- Beruntung: database lab hanya berhenti, tidak rusak; latihan pulih (B.3)
  sudah membuktikan dump bisa dipulihkan (705 KB, ±1 s).

## Tindakan
| Tindakan | Jenis | Pemilik | Tenggat | Tautan |
|----------|-------|---------|---------|--------|
| `DbGagal` mengenali SQLSTATE di pesan; unit test + K-20 | cegah | Dimas | 2026-11-20 (selesai) | bagian Latihan tambahan |
| Laporkan temuan "db macet → 500" ke pemilik starter | cegah | Raka | 2026-11-21 | issue repo starter |
| Interval uptime monitor 1 menit selama demo UAS (dijeda lagi sesudahnya, kuota Neon) | deteksi | Raka | P16 | runbook bagian 4 |
| Tambah satu anggota sebagai admin tim Render, Neon, Supabase | pulihkan | Raka, Salsa, Nadia | 2026-11-25 | evaluasi.md bagian 1 |
| Jadwalkan `pg_dump` harian + salinan mingguan bucket Supabase | pulihkan | Salsa | 2026-11-27 | ADR 0006 |
| Target: RDS Multi-AZ (failover ±1–2 menit tanpa orang) | cegah | Nadia | rancangan | ADR 0002, evaluasi.md bagian 2 |
