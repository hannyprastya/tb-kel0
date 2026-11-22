# docs/ · daftar isi dan urutan baca

Dokumen Tugas Besar Kelompok 0, ditulis bertahap P9–P15. Satu baris per
dokumen: pertemuan yang menghasilkannya dan pertanyaan yang dijawabnya.
Kode dan konfigurasi yang dirujuk ada di luar folder ini (peta folder:
[README.md](../README.md#peta-folder)).

## Urutan baca yang disarankan
Dari masalah ke keputusan, lalu bukti, lalu penilaian akhir.

| No | Dokumen | Pertemuan | Pertanyaan yang dijawab |
|----|---------|-----------|-------------------------|
| 1 | [kebutuhan.md](kebutuhan.md) | P9 | Berapa beban, data, dan target layanan yang harus dipenuhi (1.000 dan 10.000 pengguna)? |
| 2 | [keputusan.md](keputusan.md) | P9 | Provider dan region mana yang dipilih, dengan kriteria dan bobot apa? |
| 3 | [arsitektur.md](arsitektur.md) | P9, P14, P15 | Seperti apa sistem yang berjalan dan rancangan targetnya (C4, deployment, sequence)? |
| 4 | [pengujian.md](pengujian.md) | P10, P11 | Kapan sistem dianggap lulus, dan apa hasil uji fungsional, k6, MTTR, SLO, dan alert? |
| 5 | [runbook.md](runbook.md) | P11, P15 | Apa yang dilakukan saat deploy, rollback, alert 5xx, atau pesan masuk DLQ? |
| 6 | [postmortem-latihan-1.md](postmortem-latihan-1.md) | P11 | Apa yang terjadi di latihan insiden pertama dan apa yang diperbaiki? |
| 7 | [keamanan.md](keamanan.md) | P12 | Apa temuan scan, seberapa patuh sistem, dan mana yang dikerjakan dulu? |
| 8 | [optimasi.md](optimasi.md) | P13 | Optimasi mana yang benar-benar membantu, diukur sebelum vs sesudah? |
| 9 | [biaya.md](biaya.md) | P13 | Berapa biaya bulanan, dan kapan serverless lebih murah daripada VM? |
| 10 | [komponen.md](komponen.md) | P14 | Komponen apa saja yang dibutuhkan, dan di mana pola event-driven dipakai? |
| 11 | [jaringan.md](jaringan.md) | P14 | Bagaimana VPC, subnet, dan aturan lalu lintas rancangan target? |
| 12 | [postmortem.md](postmortem.md) | P15 | Apa hasil latihan gangguan (database putus, restart aplikasi) dan pelajarannya? |
| 13 | [evaluasi.md](evaluasi.md) | P15 | Di mana SPOF, berapa ketersediaan, RPO/RTO, dan apa trade-off rancangan akhir? |

## ADR (keputusan arsitektur, `adr/`)
Baca setelah keputusan.md; setiap ADR menjawab "kenapa begini, bukan yang lain?".

| ADR | Pertemuan | Pertanyaan yang dijawab |
|-----|-----------|-------------------------|
| [0001](adr/0001-container-terkelola.md) | P9 | Kenapa aplikasi jalan sebagai container di layanan terkelola? |
| [0002](adr/0002-postgresql-terkelola.md) | P9 | Kenapa PostgreSQL terkelola dengan connection pooling? |
| [0003](adr/0003-region-jakarta.md) | P9 | Kenapa AWS region Jakarta untuk data asli? |
| [0004](adr/0004-risiko-diterima.md) | P12 | Risiko keamanan mana yang diterima dengan sadar, dan kenapa? |
| [0005](adr/0005-tanpa-kubernetes-dan-mesh-di-produksi.md) | P13 | Kenapa tidak memakai Kubernetes dan service mesh di produksi? |
| [0006](adr/0006-antrean-untuk-notifikasi.md) | P14 | Kenapa pengingat jatuh tempo lewat antrean + DLQ? |
| [0007](adr/0007-monolit-tanpa-microservices.md) | P14 | Kenapa tetap satu aplikasi, tidak dipecah menjadi microservices? |
| [0008](adr/0008-timeout-db-3-detik.md) | P15 | Kenapa batas koneksi database 3 detik dan halaman 503 ramah? |
| [0009](adr/0009-strategi-dr-backup-restore.md) | P15 | Kenapa DR memakai backup & restore + Multi-AZ, tanpa lintas region? |
