output "db_host" {
  value = "${docker_container.db.name}:5432 (jaringan ${docker_network.tb.name})"
}
