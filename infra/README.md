# infra/ · komponen Tugas Besar sebagai kode (P14)

Satu komponen per anggota, alur bukti slide 27: init + validate → plan →
apply → plan ulang *No changes* → `trivy config` → destroy. Tidak ada
secret di folder ini; state (`*.tfstate`) dan `terraform.tfvars` tidak
di-commit (`infra/.gitignore`). Yang di-commit per folder: `main.tf`,
`variables.tf`, `outputs.tf`, `.terraform.lock.hcl`.

| Komponen | Folder | Target | Provider | Pemilik (NIM) |
|----------|--------|--------|----------|---------------|
| Database dev | db-dev/ | Docker | kreuzwerker/docker ~> 3.6 | Dimas Ardiansyah (99000003) |
| Object storage (sampul, scan) | storage-file/ | Floci 2.4.0 | hashicorp/aws ~> 6.0 | Nadia Kusuma (99000004) |
| Database terkelola | db-neon/ | Neon | kislerdm/neon ~> 0.18.0 | Salsa Nuraini (99000002) |
| Antrean pengingat + DLQ | antrean/ | Floci 2.4.0 | hashicorp/aws ~> 6.0 | Raka Pratama (99000001) |

## Cara menjalankan (setiap folder)
1. Buat `terraform.tfvars` berisi `nim = "NIM"` (tidak di-commit);
   `db-neon` juga `org_id = "org-…"` (Organization ID Neon, P6).
2. Rahasia dan alamat lewat environment:
   - `db-dev`: `TF_VAR_db_password` dan
     `DOCKER_HOST=$(docker context inspect -f '{{.Endpoints.docker.Host}}')`
   - `db-neon`: `NEON_API_KEY`
   - `storage-file`, `antrean`: Floci menyala (P14 E.3) dan
     `AWS_ACCESS_KEY_ID=test AWS_SECRET_ACCESS_KEY=test` (kunci palsu Floci;
     sengaja tidak ditulis di `main.tf` supaya Semgrep 0 temuan).
3. `terraform init`, `validate`, `plan`, `apply`; `plan` ulang = No changes;
   `docker run --rm -v "${PWD}:/src" aquasec/trivy:0.76.0 config /src`;
   `terraform destroy`. OpenTofu: ganti `terraform` dengan `tofu`.

## Contoh hasil (diukur 2026-11-22, Terraform 1.14.0, macOS + OrbStack)

```
db-dev        Plan: 4 to add, 0 to change, 0 to destroy.
              Apply complete! Resources: 4 added, 0 changed, 0 destroyed.
              db_host = "db-99000003:5432 (jaringan tb-99000003)"
              No changes. Your infrastructure matches the configuration.
              db-99000003  5432/tcp          <- tidak ada 0.0.0.0, seperti subnet privat-db
              Destroy complete! Resources: 4 destroyed.
storage-file  Plan: 3 to add, 0 to change, 0 to destroy.   (bucket, blok publik, versioning)
              bucket = "tb-99000004-file"
              No changes. ...
              Destroy complete! Resources: 3 destroyed.
antrean       Plan: 2 to add, 0 to change, 0 to destroy.
              antrean_url = "http://localhost:4566/000000000000/antrean-99000001"
              dlq_arn = "arn:aws:sqs:ap-southeast-3:000000000000:antrean-99000001-dlq"
              VisibilityTimeout 60, maxReceiveCount 5, SqsManagedSseEnabled true
              No changes. ...
              Destroy complete! Resources: 2 destroyed.
db-neon       Success! The configuration is valid.   (plan/apply butuh NEON_API_KEY;
              dijalankan pemilik di akun Neon-nya: 1 to add -> 1 destroyed)
```

## Temuan trivy config dan keputusan
| Folder | Temuan | Keputusan |
|--------|--------|-----------|
| db-dev | 0 | – |
| db-neon | 0 | – |
| antrean | AWS-0096 (HIGH) ×2: antrean tanpa enkripsi | **diperbaiki**: `sqs_managed_sse_enabled = true` di kedua antrean → 0 temuan |
| storage-file | AWS-0090 (MEDIUM): tanpa versioning | **diperbaiki**: `aws_s3_bucket_versioning` (plan: 1 to add) |
| storage-file | AWS-0132 (HIGH): tanpa customer managed key (KMS) | **terima risiko** (ADR 0004): bucket lab Floci tanpa data pribadi; target memakai SSE-KMS. Dicatat di `.trivyignore.yaml` |
| storage-file | AWS-0089 (LOW): tanpa access logging | **terima risiko** (ADR 0004): log akses dipasang di target (bucket log terpisah) |

## Yang belum menjadi kode
VPC, subnet, security group, ALB, ECS, RDS, CloudFront (rancangan target,
[docs/jaringan.md](../docs/jaringan.md)) belum ditulis sebagai Terraform:
tidak bisa diuji tanpa akun AWS berbayar (Floci tidak mengemulasi ALB/ECS
sungguhan). Urutan bila kelak dikerjakan: `network` → `db` → `app`, masing-
masing satu folder dengan state terpisah.
