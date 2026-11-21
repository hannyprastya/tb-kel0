# 0004 · Menerima tiga risiko keamanan dengan sadar (hasil scan P12)
Status: Diterima · Tanggal: 2026-11-10 · Penulis: Raka Pratama (99000001)
Ditinjau ulang: setiap awal semester, atau saat komponen pindah ke AWS.

## Konteks
Scan P12 (Trivy 0.76.0, composer 2.10.3, PHPStan 2.3.1, Semgrep 1.182.1;
[keamanan.md](../keamanan.md)) menemukan temuan yang tidak bisa atau tidak
layak diperbaiki sekarang:
- **T-04** password `latihan` di `docker-compose.yml` dan `k8s/app.yaml`
  (aturan tambahan `trivy-secret.yaml`: 4 temuan + 1 alarm palsu di
  komentar `index.php`).
- **AWS-0132 (HIGH)** dan **AWS-0089 (LOW)** di bucket Terraform P6 (kelak
  `infra/storage-file`): tanpa customer managed key (KMS) dan tanpa access
  logging.
- **T-03** CVE CRITICAL di base image Debian yang belum ada perbaikannya
  (status *affected*), sehingga tidak bisa ditutup dengan build ulang.

## Keputusan
Ketiganya kami terima sebagai risiko dengan batas yang jelas:
1. `latihan` hanya berlaku untuk database container lab (compose di
   codespace/laptop, kind) yang port-nya tidak dibuka ke luar (butir 11
   checklist). Produksi memakai password Neon acak yang hanya ada di Render
   *Environment*. Pengecualian dicatat sebagai `allow-rules` di
   `trivy-secret.yaml`, sehingga password **baru** di berkas lain tetap
   membuat CI merah.
2. AWS-0132 dan AWS-0089 diterima untuk bucket lab di Floci (tanpa data
   pribadi); dicatat di daftar pengecualian scan beserta alasannya.
   Rancangan target memakai SSE-KMS dan access logging.
3. CVE tanpa perbaikan dipantau, tidak memblokir deploy: CI hanya merah untuk
   CRITICAL yang **sudah** ada perbaikannya (`ignore-unfixed`); Dependabot
   mengusulkan digest base image baru setiap minggu.

## Alternatif yang ditolak
- Memindah password lab ke `.env`: compose tidak jalan lagi tanpa langkah
  tambahan di setiap codespace dan pipeline, padahal password itu bukan
  rahasia (sudah tertulis di modul). Tetap jadi usulan bila kelak ada
  password lab yang benar-benar rahasia.
- Memasang KMS di Floci: tidak membuktikan apa pun (emulator) dan di AWS
  berbayar US$1/kunci/bulan + biaya request; dipasang saat pindah ke AWS.
- Mematikan scan image atau menaikkan ambang ke "tanpa CRITICAL sama
  sekali": pipeline akan merah terus untuk hal yang tidak bisa kami
  perbaiki, dan orang terbiasa mengabaikan merah.

## Konsekuensi
- (+) CI tetap bermakna: merah hanya untuk temuan yang bisa ditindaklanjuti.
- (-) Bila `latihan` dipakai ulang di akun lain, risiko berubah menjadi
  nyata; aturan kelompok: password itu tidak boleh dipakai di layanan apa pun.
- (-) CVE tanpa perbaikan tetap ada di image produksi sampai Debian merilis
  perbaikan; dipantau lewat run CI mingguan Dependabot.
