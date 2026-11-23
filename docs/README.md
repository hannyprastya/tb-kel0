# docs/ · daftar isi dan urutan baca

Dokumen Tugas Besar Kelompok 0, ditulis bertahap P9–P15. Satu baris per
dokumen: pertemuan yang menghasilkannya dan pertanyaan yang dijawabnya.
Kode dan konfigurasi yang dirujuk ada di luar folder ini (peta folder:
[README.md](../README.md#peta-folder)). Sejak P12 aplikasinya starter
Laravel + modul perpustakaan ([ADR 0004](adr/0004-pindah-ke-laravel.md));
dokumen P9–P11 tetap, path dan angka yang berubah diperbarui.

## Urutan baca yang disarankan
Dari masalah ke keputusan, lalu bukti, lalu penilaian akhir.

| No | Dokumen | Pertemuan | Pertanyaan yang dijawab |
|----|---------|-----------|-------------------------|
| 1 | [kebutuhan.md](kebutuhan.md) | P9 | Berapa beban, data, dan target layanan yang harus dipenuhi (1.000 dan 10.000 pengguna)? |
| 2 | [keputusan.md](keputusan.md) | P9 | Provider dan region mana yang dipilih, dengan kriteria dan bobot apa? |
| 3 | [arsitektur.md](arsitektur.md) | P9, P12 | Seperti apa sistem yang berjalan dan rancangan targetnya (C4)? |
| 4 | [pengujian.md](pengujian.md) | P10, P11, P12 | Kapan sistem dianggap lulus, dan apa hasil uji fungsional, k6, MTTR, SLO, dan alert? |
| 5 | [runbook.md](runbook.md) | P11, P12 | Apa yang dilakukan saat deploy, rollback, atau alert 5xx? |
| 6 | [postmortem-latihan-1.md](postmortem-latihan-1.md) | P11 | Apa yang terjadi di latihan insiden pertama dan apa yang diperbaiki? |
| 7 | [keamanan.md](keamanan.md) | P12 | Apa temuan scan, seberapa patuh sistem, dan mana yang dikerjakan dulu? |

## ADR (keputusan arsitektur, `adr/`)
Baca setelah keputusan.md; setiap ADR menjawab "kenapa begini, bukan yang lain?".

| ADR | Pertemuan | Pertanyaan yang dijawab |
|-----|-----------|-------------------------|
| [0001](adr/0001-container-terkelola.md) | P9 | Kenapa aplikasi jalan sebagai container di layanan terkelola? |
| [0002](adr/0002-postgresql-terkelola.md) | P9 | Kenapa PostgreSQL terkelola dengan connection pooling? |
| [0003](adr/0003-region-jakarta.md) | P9 | Kenapa AWS region Jakarta untuk data asli? |
| [0004](adr/0004-pindah-ke-laravel.md) | P12 | Kenapa pindah dari buku tamu PHP ke starter Laravel, dan apa akibatnya? |

Keputusan yang lebih kecil ditulis di dokumen pertemuannya, bukan sebagai ADR: risiko yang diterima (keamanan.md bagian 6).
