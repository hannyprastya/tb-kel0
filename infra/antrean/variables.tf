variable "nim" {
  type = string
}
# dari hitungan Bagian C (docs/komponen.md bagian 5): timeout worker 10 s,
# visibility = 6 x 10 s = 60 s, maxReceiveCount 5 -> DLQ setelah +-5 menit
variable "visibility_detik" {
  type    = number
  default = 60
}
variable "max_terima" {
  type    = number
  default = 5
}
