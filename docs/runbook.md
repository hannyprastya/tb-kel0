# Runbook · Perpustakaan Kampus (Kelompok 0)
Pemilik: Dimas Ardiansyah (99000003) · Diperbarui: 2026-11-20 (setelah
latihan gangguan P15; aplikasi Laravel sejak P12, ADR 0004) · Tanpa
password, token, atau connection string: hanya nama secret.

Isi: 1. Deploy · 2. Rollback · 3. Alert error rate 5xx > 5% · 4. Uptime
monitor merah · 5. Eskalasi · 6. Job pengingat gagal / DLQ · 7. Pengingat
pagi tidak terkirim

## 1. Deploy (normal)
1. Perubahan lewat branch + PR ke `main`; PR harus hijau di check
   `build-test-deploy` dan disetujui satu anggota (ruleset `lindungi-main`).
2. Merge = push ke `main` → pipeline: audit + Semgrep + Trivy config/secret
   → Larastan 5 + `php artisan test` → build `web/` → uji F-01..F-19 +
   K-01..K-20 → scan image → push `ghcr.io/hannyprastya/tb-kel0:<7 karakter
   commit>` → deploy hook Render (bila variabel `RENDER_ENABLED` = `true`).
3. Render menyalakan versi baru (`MIGRASI_SAAT_START=1`: `php artisan
   migrate --force` dulu), menunggu `/health` 200, baru mengalihkan trafik.
   Cek: tab *Events* Render = tag yang sama dengan log Actions.
4. Selesai bila `curl -s -o /dev/null -w "%{http_code}\n" $URL/health` = 200,
   halaman `/buku` tampil, dan log Render memuat baris supervisord
   `success: worker entered RUNNING state` (mode satu container).

## 2. Rollback
- **Render:** layanan › *Events* › deploy sebelumnya yang *Live* ›
  **Rollback** (memakai tag image lama; tidak perlu build ulang). Atau
  *Manual Deploy › Deploy an existing image* dengan tag lama dari GHCR.
- **Kode:** buat PR `git revert <commit>` supaya `main` kembali sama dengan
  yang berjalan; jangan force-push.
- **Database:** skema diubah lewat migrasi Laravel (`web/database/migrations/`),
  hanya menambah tabel/kolom, jadi image lama tetap jalan di skema baru;
  `php artisan migrate:rollback` hanya bila migrasi baru itu sendiri rusak.
  Data salah hapus → pulihkan lewat PITR Neon (riwayat 6 jam) ke **branch
  baru**, cek, baru arahkan `DATABASE_URL` ke sana; lebih lama dari 6 jam →
  `pg_restore` dari dump harian (ADR 0006; latihan: 705 KB, ±1 s).
- **Berkas:** sampul/scan yang tertimpa di Supabase tidak punya versi;
  pulihkan dari salinan mingguan (ADR 0006).
- Pulih bila `/health` 200 dan alert kembali Normal.

## 3. Alert: error rate 5xx > 5% (pending 30 s)
Grafana: *Alerting › Alert rules › tb-kel0 › error rate 5xx > 5%*
(aturan dari `observability/alert-rules.yaml`).

### 3.1 Gejala dan dasbor
- Alert Pending lalu Firing; `/health` 503; halaman `/`, `/buku` 503 ramah
  "Maaf …" + `Retry-After: 60`, `/api/*` 503 JSON
  `{"status":"error","db":"gagal"}`. `/healthz` tetap 200 (proses hidup).
- Dari latihan P15 (pengujian.md Tabel 5): db mati → Pending +13–21 s →
  Firing +43–52 s; setelah db hidup, `/buku` 200 dalam ±1 s, alert Normal
  ±60 s kemudian.
- Dasbor *tb-kel0 · SLO perpustakaan*: panel error rate naik, p95 turun
  (jawaban 503 cepat) atau naik ke ±3 s bila database **macet** (batas 3 s,
  postmortem.md).

### 3.2 Cek cepat (≤ 5 menit)
```
curl -s -o /dev/null -w "%{http_code} %{time_total}\n" $URL/health
docker compose ps -a                          # lab: -a, supaya db yang Exited ikut tampil
docker compose logs app worker --tail 20      # cari "DB gagal: SQLSTATE[...]"
```
- Produksi: Render › *Logs* (cari `DB gagal`); status Neon di console
  Neon; ada deploy dalam 1 jam terakhir? (tab *Actions* dan *Events*).
- `DB gagal ... could not translate host name` / `Connection refused` = db
  mati/hilang; `... timeout expired` dan `/health` menjawab setelah ±3 s =
  db macet (batas 3 s bekerja).
- Grafana: klik `trace_id` di baris log Loki → trace `GET /buku` berstatus
  ERROR di Tempo.

### 3.3 Mitigasi (jika ... maka ...)
- db lab berhenti → `docker compose start db`, tunggu `/health` 200
  (±1 s setelah db hidup).
- db lab macet (`Paused`) → `docker compose unpause db`.
- Neon tidak bisa dihubungi → cek status Neon; bila kuota CU-jam habis,
  jeda uptime monitor dan beri tahu pemilik akun (Salsa).
- Deploy < 1 jam lalu dan error mulai sesudahnya → rollback (bagian 2).
- 5xx hanya di satu rute dan bukan DB → bug: rollback, lalu PR perbaikan.
- Worker ikut berhenti saat DB putus (log `queue:work` keluar) → tidak
  perlu apa-apa: compose (`restart: unless-stopped`) dan supervisord
  (mode Render, jeda 10 s) menyalakannya lagi.

### 3.4 Selesai
`/health` 200 dan tetap 200 selama 1 menit, alert Normal. Catat jam T0
(503 pertama), T1 (Firing), T2 (pulih) untuk postmortem.

## 4. Uptime monitor merah (Render)
1. Buka URL sekali: instance Free tidur setelah ±15 menit sepi; permintaan
   pertama ±1 menit dan bisa 503 bila Neon juga sedang bangun (batas 3 s).
2. Masih merah setelah 2 menit → bagian 3.2 (Logs Render).
3. Render *Events* menunjukkan deploy gagal (health check tidak lulus) →
   versi lama tetap melayani; perbaiki lewat PR.
4. Monitor tiap 5 menit membuat Render tidak tidur, dan worker yang menanyai
   tabel `jobs` tiap ±3 s membuat Neon tidak tidur → kuota Neon habis ±16
   hari. Monitor hanya dinyalakan di masa uji/demo (biaya.md bagian 1).

## 5. Eskalasi
- 15 menit belum pulih → panggil IC (Raka Pratama) di grup WA kelompok;
  komunikasi oleh Nadia.
- Butuh akses akun: Render = Raka, Neon = Salsa, Supabase = Nadia (SPOF
  orang, evaluasi.md bagian 1; rencana: satu admin tambahan per akun).
- Data pribadi anggota terdampak (bocor atau tidak bisa diakses)? Raka
  (IC) memutuskan, Nadia menulis pemberitahuan ke pengelola perpustakaan
  paling lambat **3 × 24 jam** (PP 33/2026). Di lingkungan latihan hanya
  ada data contoh.

## 6. Job pengingat gagal / DLQ
Job `KirimPengingatJatuhTempo` dicoba 5 kali (jeda 1, 3, 10, 20 menit)
lalu masuk tabel `failed_jobs`. DLQ SQS (target) hanya berisi pesan yang
membuat worker mati sebelum sempat mencatat gagal.
```
docker compose exec app php artisan queue:failed            # daftar job gagal + pesan error
docker compose exec app php artisan queue:retry all         # ulangi setelah penyebab beres
# target (SQS): pesan di DLQ
aws sqs receive-message --attribute-names ApproximateReceiveCount \
  --queue-url $AWS_ENDPOINT_URL/000000000000/antrean-99000001-dlq
```
- Error "layanan email/WA tidak tersedia" → tunggu layanan pulih, lalu
  `queue:retry all`. Aman diulang: kunci `pinjam-NO-TANGGAL` di tabel
  `notifikasi` mencegah pengingat dobel.
- Error `ModelNotFound`/data rusak → bug: perbaiki lewat PR, lalu
  `php artisan queue:forget ID`.
- Pesan normal di DLQ (worker mati saat deploy/OOM) → pindahkan kembali:
  `aws sqs start-message-move-task --source-arn <ARN DLQ>`.

## 7. Pengingat pagi tidak terkirim
Gejala: anggota tidak menerima pengingat; tidak ada baris
`PENGINGAT JATUH TEMPO` pukul 07.00 di log.
1. `docker compose exec app php artisan schedule:list` → ada
   `kasus:pengingat-jatuh-tempo` dengan jadwal `0 7 * * *`?
2. Produksi Render Free: layanan tidur pukul 07.00 = scheduler ikut tidur,
   dan paket Free tidak memberi *Shell* untuk menjalankan perintah manual.
   Pengingat yang terlewat ikut dititip pada jadwal 07.00 berikutnya
   (perintah memilih semua pinjaman aktif yang jatuh tempo ≤ besok dan
   belum diingatkan), jadi terlambat paling lama 1 hari. Pencegahan selama
   masa uji: uptime monitor aktif 06.50–07.10 supaya layanan bangun. Di
   paket berbayar: *Shell* › `php artisan kasus:pengingat-jatuh-tempo`
   (aman diulang: `sudah_terkirim=N` dilewati).
3. Lab: `docker compose ps scheduler worker` harus *Up*; log
   `docker compose logs scheduler --tail 20`.
