# Postmortem latihan gangguan P15: database putus (G1) dan restart aplikasi (G2)
2026-11-20 · SEV-2 (G1), SEV-3 (G2) · status: selesai · tanpa menyalahkan
IC: Salsa Nuraini (G1 ke-1), Raka Pratama (G1 ke-2) · pelaku: Dimas
Ardiansyah, Raka Pratama · pencatat waktu: Nadia Kusuma · notulis: Nadia
Kusuma

> Angka G1 diukur pada 2026-11-22 dengan stack P11 + versi C di repo contoh
> (loop pantau C.5 + riwayat alert Grafana). Baris G2 bertanda *(ilustrasi)*:
> repo contoh belum punya layanan Render sungguhan.

## Hipotesis (ditulis sebelum mulai)
- G1 database putus: alert Firing ≤ 1 menit, pulih ≤ 3 menit, pengguna
  melihat pesan ramah 503 (bukan halaman kosong).
- G2 restart container di Render: uptime monitor mendeteksi ≤ 5 menit,
  pulih otomatis ≤ 2 menit.

## Ringkasan
Database lab dihentikan dua kali; halaman menampilkan pesan ramah 503,
alert 5xx menyala dalam ±48 s, dan runbook memulihkan layanan dalam
77–90 s. Restart layanan Render tidak terdeteksi monitor karena lebih
singkat dari interval cek.

## Dampak
| Ke- | Kode | T0 (503 pertama) | T1 (Firing) | T2 (`/health` 200 stabil) | Deteksi | Pulih | Budget terpakai (216 menit) | Hipotesis |
|-----|------|------------------|-------------|---------------------------|---------|-------|-----------------------------|-----------|
| 1 | G1 | 05:43:02 | 05:43:50 | 05:44:19 | 48 s | 77 s | 1,28 ÷ 216 = 0,59% | terpenuhi |
| 2 | G1 | 05:47:22 | 05:48:10 | 05:48:52 | 48 s | 90 s | 1,50 ÷ 216 = 0,69% | terpenuhi |
| 3 | G2 | 10:02:10 *(ilustrasi)* | tidak terdeteksi | 10:02:58 *(ilustrasi)* | – | 48 s | 0,37% | deteksi **tidak** terpenuhi |
| | **Rata-rata G1** | | | | **48 s** | **84 s** | **0,64%** | |

Baris 503 di loop pantau: 61 (G1 ke-1) dan 81 (G1 ke-2), masing-masing
untuk `/` dan `/health`. Data tidak hilang (count `tamu`, `buku`, `pinjam`
sama sebelum dan sesudah).

## Kronologi G1 ke-2 (WIB; nomor langkah = sequence diagram arsitektur.md bagian 6)
| Jam | Kejadian | Tindakan |
|-----|----------|----------|
| 05:47:21 | pelaku: `docker compose stop db` | – |
| 05:47:22 | T0: loop pantau `/:503 /health:503`; langkah 3 → 10–11 (503 ramah, `Retry-After: 60`) | pencatat waktu mencatat |
| 05:47:40 | alert Pending (A = 0,162) | IC membuka dasbor SLO |
| 05:48:10 | T1: alert Firing (A = 0,716) | IC: SEV-2, buka Runbook URL |
| 05:48:15 | runbook 3.2: `docker compose ps -a` → db `Exited`; log `DB gagal: ... could not translate host name "db"` | diagnosis: db mati |
| 05:48:50 | runbook 3.3: `docker compose start db` | – |
| 05:48:52 | T2: `/health` 200, stabil 1 menit | kabar pulih |
| 05:49:50 | alert Normal | latihan ditutup |

## Akar masalah dan faktor pendukung
- **Mengapa sistem membolehkan ini terjadi?** Satu database tanpa standby
  (SPOF, evaluasi.md bagian 1); di produksi Neon Free juga tanpa HA.
- Deteksi G2 bergantung pada interval monitor 5 menit; restart Render
  ±48 s selesai di antara dua cek, jadi tidak ada alat yang melihatnya.
- Pemulihan G1 masih menunggu orang (35–40 s dari Firing ke `start db`):
  membaca runbook dan memastikan penyebabnya.

## Yang berjalan baik / kurang / beruntung
- Baik: pesan ramah 503 tampil di semua rute DB (termasuk `/buku`), alert
  tetap menyala karena status tetap 5xx; deteksi konsisten 48 s.
- Baik: runbook langkah 3.2 sudah memakai `ps -a` (tindak lanjut P11).
- Kurang: G2 tidak terdeteksi; pencatat waktu harus menebak T0 dari tab
  *Events* Render.
- Beruntung: database lab hanya berhenti, tidak rusak; latihan pulih
  (B.3) sudah membuktikan dump bisa dipulihkan bila perlu.

## Tindakan
| Tindakan | Jenis | Pemilik | Tenggat | Tautan |
|----------|-------|---------|---------|--------|
| Interval uptime monitor 1 menit selama demo UAS (dijeda lagi sesudahnya) | deteksi | Raka | P16 | runbook bagian 4 |
| Tambah satu anggota sebagai admin tim Render dan Neon | pulihkan | Raka, Salsa | 2026-11-25 | evaluasi.md bagian 1 |
| Jadwalkan `pg_dump` harian database kelompok (RPO 24 jam benar-benar berlaku) | pulihkan | Salsa | 2026-11-27 | ADR 0009 |
| Target: RDS Multi-AZ (failover ±1–2 menit tanpa orang) | cegah | Nadia | rancangan | ADR 0002, evaluasi.md bagian 2 |
