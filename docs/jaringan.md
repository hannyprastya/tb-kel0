# Rencana Jaringan · Perpustakaan Kampus (AWS · Jakarta `ap-southeast-3`)

Rancangan di kertas untuk provider dan region di [keputusan.md](keputusan.md).
Lingkungan latihan (Render + Neon) tidak memberi VPC sendiri, jadi belum
ada resource jaringan yang dibuat. Pembanding yang sudah berjalan: komponen
`infra/db-dev` membuat jaringan Docker `tb-NIM` dan database hanya bisa
dicapai dari jaringan itu (`5432/tcp` tanpa `0.0.0.0`), mirip subnet
privat-db di bawah.

## 1. VPC
CIDR: **`10.0.0.0/16`** (kelompok 0) · alamat: 2^(32−16) = **65.536** ·
subnet /24 tersedia: 2^(24−16) = **256** · dipakai: **6** · sisa: 250.

## 2. Subnet
Di AWS satu subnet berada di satu zona (AZ), jadi setiap lapisan dibuat dua
kali (zona a dan b). Nomor diberi jarak supaya ada ruang tumbuh: publik
`.1/.2`, app `.11/.12`, db `.21/.22`.

| Subnet      | AZ | CIDR           | Isi | Route table |
|-------------|----|----------------|-----|-------------|
| publik-a    | a  | `10.0.1.0/24`  | node ALB, NAT gateway (satu-satunya) | `rt-publik`: `10.0.0.0/16 → local`, `0.0.0.0/0 → IGW` |
| publik-b    | b  | `10.0.2.0/24`  | node ALB | `rt-publik` (sama) |
| privat-app-a | a | `10.0.11.0/24` | 2 task web (ECS Fargate), RDS Proxy | `rt-app`: `local`, `0.0.0.0/0 → NAT (publik-a)`, prefix list S3 → gateway endpoint |
| privat-app-b | b | `10.0.12.0/24` | 2 task web, RDS Proxy | `rt-app` (sama; lewat NAT di zona a) |
| privat-db-a | a  | `10.0.21.0/24` | RDS primer | `rt-db`: hanya `10.0.0.0/16 → local` (tanpa rute internet) |
| privat-db-b | b  | `10.0.22.0/24` | RDS standby | `rt-db` (sama) |

Satu NAT gateway untuk dua zona adalah trade-off yang disengaja (hemat
±US$44/bulan; evaluasi.md bagian 6). Akses S3 dari subnet app lewat
**gateway endpoint S3** (gratis), jadi unggah/baca berkas tidak lewat NAT.

## 3. Aturan lalu lintas per komponen
Setiap komponen punya security group (SG) sendiri; aturan masuk merujuk SG
sumber, bukan alamat IP.

| Komponen | Subnet | Masuk (dari → port) | Keluar |
|----------|--------|---------------------|--------|
| CloudFront + WAF | edge (di luar VPC) | internet → 443 | ke ALB :443 (header rahasia origin), ke S3 lewat OAC |
| ALB (`sg-lb`) | publik-a, publik-b | hanya prefix list CloudFront → 443 | ke `sg-web` :8080 |
| Web, ECS Fargate (`sg-web`) | privat-app-a/b | hanya `sg-lb` → 8080 | ke `sg-proxy` :5432; ke S3 (gateway endpoint); ke internet :443 lewat NAT (SQS, Secrets Manager, GHCR, CloudWatch) |
| RDS Proxy (`sg-proxy`) | privat-app-a/b | hanya `sg-web` → 5432 | ke `sg-db` :5432 |
| Database RDS (`sg-db`) | privat-db-a/b | hanya `sg-proxy` → 5432 | – |
| NAT gateway | publik-a | dari subnet privat-app | internet |
| Worker pengingat (Lambda) | **di luar VPC** | dipicu SQS (tidak ada port masuk) | ke S3, SQS, API email/WA :443 lewat internet AWS |
| Penjadwal (EventBridge) | layanan regional | – | ke ALB :443 (`POST /pengingat/kirim`, header rahasia) |

Hanya CloudFront yang menerima dari internet; ALB pun hanya menerima dari
CloudFront. Web, proxy, dan database tidak punya alamat publik. Worker
sengaja di luar VPC karena tidak menyentuh database: tidak butuh NAT dan
tidak menambah koneksi ke RDS.

## 4. Hitungan dan catatan
- **Alamat per subnet /24:** 256 − 5 (dicadangkan AWS: `.0`, `.1`, `.2`,
  `.3`, `.255`) = **251** alamat terpakai.
- **Lapisan app:** 2 subnet × 251 = **502** alamat. Setiap task Fargate
  memakai 1 alamat (ENI), RDS Proxy ±2 per subnet. Replika maksimum
  auto scaling = **6** task (sama dengan `maxReplicas` HPA P13) + rolling
  deploy (paling banyak 2 × 6 = 12 sebentar) + proxy 4 = **±16 alamat**.
  /24 jelas berlebihan (±3% terpakai); dipertahankan karena alamat VPC
  privat tidak berbayar, rutenya sederhana, dan ruang tumbuh lebar
  (mis. layanan kedua atau worker di dalam VPC).
- **Lapisan db:** RDS Multi-AZ memakai 1 alamat per zona; DB subnet group
  wajib ≥ 2 zona, jadi dua /24 tetap dibutuhkan walau hampir kosong.
- **Ruang tumbuh:** 250 subnet /24 belum dipakai; `10.0.0.0/16` tidak
  tumpang tindih dengan VPC kelompok lain (`10.<No>.0.0/16`) bila kelak
  di-peering.
- **Layanan di luar VPC** dan cara aksesnya: S3 (gateway endpoint, privat),
  SQS, Secrets Manager, CloudWatch (HTTPS lewat NAT; rencana lanjutan:
  interface endpoint ±US$19/bulan per endpoint untuk 2 zona, belum
  sepadan). **Sekarang:** Neon diakses lewat internet dengan TLS
  (`sslmode=require`, P5); padanan private endpoint di AWS adalah
  PrivateLink/RDS di subnet privat seperti rancangan di atas.
