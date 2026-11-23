terraform {
  required_providers {
    neon = {
      source  = "kislerdm/neon"
      version = "~> 0.18.0"
    }
  }
}
# database produksi latihan (Render -> Neon). Pemilik: Salsa Nuraini.
provider "neon" {} # API key dibaca dari env NEON_API_KEY
resource "neon_project" "db" {
  name      = "db-${var.nim}"
  region_id = "aws-ap-southeast-1" # Singapura (belum ada region Indonesia)
  org_id    = var.org_id
  # paket Free: riwayat (PITR) maks. 6 jam (bawaan provider 1 hari ditolak)
  history_retention_seconds = 21600
}
