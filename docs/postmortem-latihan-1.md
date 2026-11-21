# Postmortem (latihan P11): /health gagal ±1,5 menit
2026-11-06 · SEV-2 · status: selesai · tanpa menyalahkan
IC: Raka Pratama · Operasi: Dimas Ardiansyah · Komunikasi: Nadia Kusuma ·
Notulis/fasilitator: Salsa Nuraini

> Jam di bawah berasal dari pengulangan latihan pada 2026-11-22 di repo
> contoh (pengujian.md Tabel 5, latihan 1); urutannya sama dengan latihan
> asli kelompok.

## Ringkasan
Fasilitator menghentikan database lab saat k6 5 menit berjalan. Semua
halaman yang butuh database gagal sampai Operasi menyalakan database lagi
mengikuti runbook.

## Dampak
1.655 dari 5.613 permintaan k6 gagal (29,5% selama 5 menit uji); halaman
`/`, `/buku`, `/lambat` gagal ±84 detik (05:37:48–05:39:12). 1,4 menit dari
216 menit error budget 30 hari (0,65%).

## Linimasa (WIB)
| Jam | Kejadian | Keputusan/tindakan | Peran |
|-----|----------|--------------------|-------|
| 05:37:48 | db dihentikan (fasilitator, tanpa pemberitahuan) | – | Notulis |
| 05:38:10 | alert Pending (A = 0,296) | menunggu pending 30 s, buka dasbor | Operasi |
| 05:38:40 | alert Firing (A = 0,918) | SEV-2: semua fitur mati, data aman, lingkungan lab | IC |
| 05:38:50 | runbook 3.2: `docker compose ps` tidak menampilkan `db` | bingung: db dihapus? | Operasi |
| 05:39:11 | `docker compose start db` (runbook 3.3) | – | Operasi |
| 05:39:13 | `/health` 200 | kabar "pulih" ke grup | Komunikasi |
| 05:40:10 | alert Normal | latihan ditutup | IC |

Deteksi 52 s · sampai mitigasi 83 s · lama dampak ±84 s.

## Akar masalah dan faktor pendukung
- Satu database tanpa standby (SPOF); aplikasi tidak punya jalur cadangan.
- Halaman yang gagal menjawab **500 kosong**, jadi pengguna tidak tahu
  harus menunggu atau melapor.
- Runbook langkah 2 memakai `docker compose ps` tanpa `-a`, sehingga
  container yang berhenti tidak tampil.

## Yang berjalan baik / kurang / beruntung
- Baik: alert menyala dalam 52 s tanpa ada yang menatap layar; Runbook URL
  di alert langsung membuka runbook.
- Kurang: 20 s terbuang karena `docker compose ps` tanpa `-a`.
- Beruntung: gangguannya hanya db berhenti (bukan data rusak), dan terjadi
  di lab.

## Tindak lanjut
| Tindakan | Jenis | Pemilik | Tenggat | Tautan |
|----------|-------|---------|---------|--------|
| Runbook 3.2 memakai `docker compose ps -a` | pulihkan | Dimas | 2026-11-09 | PR `docs/runbook-ps-a` |
| Halaman ramah 503 + batas waktu 3 s | cegah/pulihkan | Dimas | P15 | ADR 0008, PR `fix/db-gagal-ramah` |
| Alert disimpan sebagai kode supaya semua anggota punya aturan yang sama | deteksi | Dimas | 2026-11-10 | `observability/alert-rules.yaml` |
