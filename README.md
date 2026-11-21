# Sistem Perpustakaan Kampus · Kelompok 0 (Kasus 0)

> **Contoh dari dosen** untuk kasus di luar Tabel K. Nama dan NIM fiktif.
> Menyalin dokumen ini ke laporan kelompok = plagiat (≥ 50% sama → nilai 0).

Perpustakaan pusat kampus ingin katalog bisa dicari dari mana saja, stok
eksemplar tidak lagi selisih, dan anggota diingatkan sebelum jatuh tempo.

## Anggota
| NIM | Nama | Peran (Tabel 0) |
|-----|------|-----------------|
| 99000001 | Raka Pratama | Penulis ADR / koordinator (repo, README) |
| 99000002 | Salsa Nuraini | Analis solusi & biaya (`keputusan.md`) |
| 99000003 | Dimas Ardiansyah | Analis kebutuhan (`kebutuhan.md`) |
| 99000004 | Nadia Kusuma | Perancang diagram (`arsitektur.md`) |

## Aplikasi dasar
Buku tamu dari repo `cc-99000001-app` milik Raka, dipilih karena satu-satunya
yang sudah jalan di compose dan di kind dengan readiness `/health` dan
liveness `/healthz` (P8). Fitur kasus (katalog, pinjam, kembali, status
JSON) ditempel di atas cabang 404 `index.php`.

## Cara menjalankan
- Docker compose (P7): `docker compose up -d --build`, buka `localhost:8080`
  (buku tamu) dan `localhost:8080/buku` (katalog)
- Kubernetes (P8): manifest di `k8s/app.yaml`

## Dokumen desain
- [Kebutuhan](docs/kebutuhan.md) · [Keputusan](docs/keputusan.md)
- [Arsitektur](docs/arsitektur.md) · [ADR](docs/adr/)
