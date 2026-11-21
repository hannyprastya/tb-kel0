terraform {
  required_providers {
    docker = {
      source  = "kreuzwerker/docker"
      version = "~> 3.6"
    }
  }
}

provider "docker" {} # alamat dari env DOCKER_HOST (P6 B.2)

resource "docker_network" "tb" {
  name = "tb-${var.nim}"
}

resource "docker_volume" "data" {
  name = "dbdata-${var.nim}"
}

resource "docker_image" "postgres" {
  name         = "postgres:17-alpine"
  keep_locally = true # image tetap disimpan setelah destroy
}

resource "docker_container" "db" {
  name  = "db-${var.nim}"
  image = docker_image.postgres.image_id
  env   = ["POSTGRES_USER=app", "POSTGRES_PASSWORD=${var.db_password}"]
  networks_advanced {
    name = docker_network.tb.name
  }
  volumes {
    volume_name    = docker_volume.data.name
    container_path = "/var/lib/postgresql/data"
  }
  # opsi log bawaan daemon Docker (OrbStack/Docker Desktop) jangan dianggap
  # perubahan (P6 B.5)
  lifecycle {
    ignore_changes = [log_opts]
  }
}
