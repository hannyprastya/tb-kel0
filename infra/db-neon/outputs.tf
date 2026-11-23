output "connection_uri" {
  value     = neon_project.db.connection_uri
  sensitive = true
}
