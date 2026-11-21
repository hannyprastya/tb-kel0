# Runbook · Perpustakaan Kampus (Kelompok 0)
Pemilik: Dimas Ardiansyah (99000003) · Diperbarui: 2026-11-20 (setelah
latihan gangguan P15) · Tanpa password, token, atau connection string:
hanya nama secret.

Isi: 1. Deploy · 2. Rollback · 3. Alert error rate 5xx > 5% · 4. Uptime
monitor merah · 5. Eskalasi · 6. Pesan pengingat di DLQ

## 1. Deploy (normal)
1. Perubahan lewat branch + PR ke `main`; PR harus hijau di check
   `build-test-deploy` dan disetujui satu anggota (ruleset `lindungi-main`).
2. Merge = push ke `main` → pipeline: scan → build → uji F-01..F-12 → scan
   image → push `ghcr.io/PEMILIK/tb-kel0:<7 karakter commit>` → deploy hook
   Render (bila variabel `RENDER_ENABLED` = `true`).
3. Render menyalakan versi baru, menunggu `/health` 200, baru mengalihkan
   trafik. Cek: tab *Events* Render = tag yang sama dengan log Actions.
4. Selesai bila `curl -s -o /dev/null -w "%{http_code}\n" $URL/health` = 200
   dan halaman `/buku` tampil.

## 2. Rollback
- **Render:** layanan › *Events* › deploy sebelumnya yang *Live* ›
  **Rollback** (memakai tag image lama; tidak perlu build ulang). Atau
  *Manual Deploy › Deploy an existing image* dengan tag lama dari GHCR.
- **Kode:** buat PR `git revert <commit>` supaya `main` kembali sama dengan
  yang berjalan; jangan force-push.
- **Database:** perubahan skema hanya `CREATE TABLE IF NOT EXISTS` (tidak
  merusak data lama). Data salah hapus → pulihkan lewat PITR Neon (riwayat
  6 jam) ke **branch baru**, cek, baru arahkan `DATABASE_URL` ke sana.
- Pulih bila `/health` 200 dan alert kembali Normal.

## 3. Alert: error rate 5xx > 5% (pending 30 s)
Grafana: *Alerting › Alert rules › tb-kel0 › error rate 5xx > 5%*
(aturan dari `observability/alert-rules.yaml`).

### 3.1 Gejala dan dasbor
- Alert Pending lalu Firing; `/health` 503; halaman `/` dan `/buku` 503
  dengan pesan "Maaf, ... Coba lagi 1 menit lagi" (sejak P15; sebelumnya 500
  kosong). `/healthz` tetap 200 (proses hidup). `/lambat` (lab) tetap 500.
- Dari latihan: db mati → Pending ±18–22 s → Firing ±48–52 s; setelah db
  hidup, Normal ±60 s kemudian (pengujian.md Tabel 5).
- Dasbor *tb-kel0 · SLO perpustakaan*: panel error rate naik, p95 turun
  (jawaban 503 cepat).

### 3.2 Cek cepat (≤ 5 menit)
```
curl -s -o /dev/null -w "%{http_code}\n" $URL/health
docker compose ps -a                 # lab: -a, supaya db yang Exited ikut tampil
docker compose logs app --tail 20    # cari "DB gagal: SQLSTATE..." atau Fatal error
```
- Produksi: Render › *Logs* (cari `DB gagal`); status Neon di console
  Neon; ada deploy dalam 1 jam terakhir? (tab *Actions* dan *Events*).
- `DB gagal ... could not translate host name` = db mati/hilang;
  `... timeout expired` = db menggantung (batas 3 s bekerja).

### 3.3 Mitigasi (jika ... maka ...)
- db lab berhenti → `docker compose start db`, tunggu `/health` 200
  (±2 detik setelah db hidup).
- db lab menggantung (`Paused`) → `docker compose unpause db`.
- Neon tidak bisa dihubungi → cek status Neon; bila kuota CU-jam habis,
  jeda uptime monitor dan beri tahu pemilik akun (Salsa).
- Deploy < 1 jam lalu dan error mulai sesudahnya → rollback (bagian 2).
- 5xx hanya di satu rute dan bukan DB → bug: rollback, lalu PR perbaikan.

### 3.4 Selesai
`/health` 200 dan tetap 200 selama 1 menit, alert Normal. Catat jam T0
(503 pertama), T1 (Firing), T2 (pulih) untuk postmortem.

## 4. Uptime monitor merah (Render)
1. Buka URL sekali: instance Free tidur setelah 15 menit sepi; permintaan
   pertama ±1 menit dan bisa 503 bila Neon juga sedang bangun.
2. Masih merah setelah 2 menit → bagian 3.2 (Logs Render).
3. Render *Events* menunjukkan deploy gagal (health check tidak lulus) →
   versi lama tetap melayani; perbaiki lewat PR.

## 5. Eskalasi
- 15 menit belum pulih → panggil IC (Raka Pratama) di grup WA kelompok;
  komunikasi oleh Nadia.
- Butuh akses akun: Render = Raka, Neon = Salsa (SPOF orang, evaluasi.md
  bagian 1; rencana: undang satu anggota lain ke tim Render/Neon).
- Data pribadi anggota terdampak (bocor atau tidak bisa diakses)? Raka
  (IC) memutuskan, Nadia menulis pemberitahuan ke pengelola perpustakaan
  paling lambat **3 × 24 jam** (PP 33/2026). Di lingkungan latihan hanya
  ada data contoh.

## 6. Pesan pengingat di DLQ (lab Floci; target: alarm CloudWatch "DLQ > 0")
```
aws sqs receive-message --attribute-names ApproximateReceiveCount \
  --queue-url $AWS_ENDPOINT_URL/000000000000/antrean-kel0-dlq
aws logs tail /aws/lambda/proses-kel0 --since 30m --format short
```
- Isi pesan rusak (tanpa `anggota`/`id`) → bug producer: perbaiki lewat
  PR, lalu hapus pesan itu dari DLQ.
- Pesan normal masuk DLQ karena layanan email/WA mati → tunggu layanan
  pulih, lalu pindahkan kembali ke antrean utama:
  `aws sqs start-message-move-task --source-arn <ARN DLQ>`. Aman diulang:
  worker melewati pengingat yang sudah terkirim (kunci idempotensi).
