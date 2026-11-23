# infra/ · komponen Tugas Besar sebagai kode (P14)

Satu komponen per anggota, alur bukti slide 27: init + validate → plan →
apply → plan ulang *No changes* → `trivy config` → destroy. Tidak ada
secret di folder ini; state (`*.tfstate`) dan `terraform.tfvars` tidak
di-commit (`infra/.gitignore`). Yang di-commit per folder: `main.tf`,
`variables.tf`, `outputs.tf`, `.terraform.lock.hcl`. `storage-file/` dan
`antrean/` berasal dari starter Laravel (P12) dan disesuaikan; `db-dev/`
dan `db-neon/` ditulis anggota sejak P6.

| Komponen | Folder | Target | Provider | Dipakai aplikasi lewat env | Pemilik (NIM) |
|----------|--------|--------|----------|----------------------------|---------------|
| Database dev | `db-dev/` | Docker | kreuzwerker/docker ~> 3.6 | `DATABASE_URL` ke `db-NIM` (jaringan `tb-NIM`) | Dimas Ardiansyah (99000003) |
| Object storage (sampul, scan) | `storage-file/` | Floci 2.4.0 | hashicorp/aws ~> 6.0 | `AWS_BUCKET` = output `bucket` | Nadia Kusuma (99000004) |
| Database terkelola | `db-neon/` | Neon | kislerdm/neon ~> 0.18.0 | `DATABASE_URL` (output sensitif) | Salsa Nuraini (99000002) |
| Antrean job + DLQ | `antrean/` | Floci 2.4.0 | hashicorp/aws ~> 6.0 | `QUEUE_CONNECTION=sqs`, `SQS_QUEUE` = `antrean-NIM` | Raka Pratama (99000001) |

## Cara menjalankan (setiap folder)
1. Floci menyala: `docker compose up -d --wait` dari akar repo (service
   `floci` membuka port 4566 di localhost).
2. Buat `terraform.tfvars` berisi `nim = "NIM"` (tidak di-commit);
   `db-neon` juga `org_id = "org-…"` (Organization ID Neon, P6).
3. Rahasia dan alamat lewat environment, sengaja tidak ditulis di `.tf`:
   - `storage-file`, `antrean`: `export AWS_ACCESS_KEY_ID=test AWS_SECRET_ACCESS_KEY=test`
     (kunci palsu Floci)
   - `db-dev`: `TF_VAR_db_password` dan
     `DOCKER_HOST=$(docker context inspect -f '{{.Endpoints.docker.Host}}')`
   - `db-neon`: `NEON_API_KEY`
4. `terraform init`, `validate`, `plan`, `apply`; `plan` ulang = No changes;
   `docker run --rm -v "${PWD}:/src" aquasec/trivy:0.76.0 config /src`;
   `terraform destroy`. OpenTofu: ganti `terraform` dengan `tofu`.

## Contoh hasil (diukur 2026-11-23, Terraform 1.14.0, macOS + OrbStack, Floci 2.4.0)

```
storage-file  Plan: 3 to add, 0 to change, 0 to destroy.   (bucket, blok publik, versioning)
              bucket = "tb-99000004-berkas"
              No changes. Your infrastructure matches the configuration.
              Destroy complete! Resources: 3 destroyed.
antrean       Plan: 2 to add, 0 to change, 0 to destroy.
              antrean_url = "http://localhost:4566/000000000000/antrean-99000001"
              dlq_arn = "arn:aws:sqs:ap-southeast-3:000000000000:antrean-99000001-dlq"
              No changes. Your infrastructure matches the configuration.
              Destroy complete! Resources: 2 destroyed.
db-dev        Success! The configuration is valid.   (apply 4 resource container/jaringan/
db-neon       Success! The configuration is valid.    volume/image dijalankan pemilik;
                                                      db-neon butuh NEON_API_KEY)
```

Uji sambung ke aplikasi: `QUEUE_CONNECTION=sqs docker compose up -d
--wait` membuat `antrean-tb` + `antrean-tb-dlq` di Floci (`php artisan
lab:siapkan`); job `KirimPengingatJatuhTempo` diambil worker dari SQS dalam
±3 s (K-16 lulus). Untuk memakai komponen buatan Terraform, ubah env
`app`, `worker`, `scheduler`: `AWS_BUCKET=tb-NIM-berkas`,
`SQS_QUEUE=antrean-NIM`, `AWS_DEFAULT_REGION=ap-southeast-3`.

## Temuan trivy config dan keputusan
| Folder | Temuan | Keputusan |
|--------|--------|-----------|
| db-dev | 0 | – |
| db-neon | 0 | – |
| antrean | 0 (AWS-0096 HIGH ×2 tanpa enkripsi sudah diperbaiki: `sqs_managed_sse_enabled = true`) | – |
| storage-file | AWS-0090 (MEDIUM) tanpa versioning | sudah diperbaiki: `aws_s3_bucket_versioning` |
| storage-file | AWS-0132 (HIGH): tanpa customer managed key (KMS) | **terima risiko** (docs/keamanan.md bagian 6): bucket lab Floci tanpa data pribadi; target SSE-KMS. Dicatat di `.trivyignore.yaml` |
| storage-file | AWS-0089 (LOW): tanpa access logging | **terima risiko** (docs/keamanan.md bagian 6): log akses di target (bucket log terpisah) |

## Yang belum menjadi kode
VPC, subnet, security group, ALB, ECS (web, worker, scheduler), RDS,
CloudFront (rancangan target, [docs/jaringan.md](../docs/jaringan.md))
belum ditulis sebagai Terraform: tidak bisa diuji tanpa akun AWS berbayar
(Floci tidak mengemulasi ALB/ECS sungguhan). Produksi latihan (Render,
Supabase Storage) diatur lewat dasbor masing-masing. Urutan bila kelak
dikerjakan: `network` → `db` → `app`, masing-masing satu folder dengan
state terpisah.
