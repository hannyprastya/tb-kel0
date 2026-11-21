#!/usr/bin/env bash
# tests/fungsional.sh (P10 D.2 + fitur kasus): uji fungsional F-01..F-12
# terhadap compose yang sudah jalan (docker compose up -d --wait).
# Dipakai di CI (langkah Test) dan di codespace/laptop:
#   bash tests/fungsional.sh            # semua, termasuk F-07 (db dimatikan)
#   TANPA_F07=1 bash tests/fungsional.sh   # tanpa mematikan db
# Keluar dengan kode 1 bila ada test case yang tidak sesuai harapan.
set -u
URL=${URL:-http://localhost:8080}
gagal=0

cek() {   # cek ID harapan aktual keterangan
  local status=Lulus
  if [ "$3" != "$2" ]; then status=GAGAL; gagal=1; fi
  printf "%-5s %-6s harap %-4s dapat %-4s %s\n" "$1" "$status" "$2" "$3" "$4"
}
kode() { curl -s -o /dev/null -m 10 -w "%{http_code}" "$@"; }
pinjam() {   # pinjam BUKU ANGGOTA -> "KODE NO_PINJAM" (no kosong bila gagal)
  local out
  out=$(curl -s -o /dev/null -m 10 -w "%{http_code} %{redirect_url}" \
        -d "buku=$1&anggota=$2" "$URL/buku/pinjam")
  echo "${out%% *} $(echo "$out" | sed -n 's/.*pinjam=\([0-9]*\).*/\1/p')"
}
tersedia() {   # tersedia BUKU -> jumlah eksemplar tersedia (dari JSON)
  curl -s -m 10 "$URL/buku/status" \
    | jq -r ".buku[] | select(.id == $1) | .tersedia"
}

echo "Uji fungsional ke $URL ($(date +%T))"
cek F-01 200 "$(kode "$URL/")" "halaman utama buku tamu"
cek F-02 302 "$(kode -d "nama=Ani (uji)" "$URL/tamu")" "simpan nama"
cek F-03 400 "$(kode -d "nama=" "$URL/tamu")" "nama kosong ditolak"
cek F-04 400 "$(kode -d "nama[]=x" "$URL/tamu")" "nama berupa array ditolak"
cek F-05 404 "$(kode "$URL/tidak-ada")" "alamat tidak ada"
badan=$(curl -s -m 10 "$URL/health")
cek F-06 200 "$(kode "$URL/health")" "/health saat db normal: $badan"

if [ -z "${TANPA_F07:-}" ]; then   # desain /health dalam (P10 slide 23)
  docker compose stop db >/dev/null 2>&1
  badan=$(curl -s -m 10 "$URL/health")
  cek F-07 503 "$(kode "$URL/health")" "/health saat db mati: $badan"
  docker compose start db >/dev/null 2>&1
  for _ in $(seq 1 30); do         # tunggu db menerima koneksi lagi
    [ "$(kode "$URL/health")" = 200 ] && break
    sleep 1
  done
fi

# Fitur kasus 0 (perpustakaan). Data contoh: buku 1 (3 eksemplar),
# buku 2 (2 eksemplar), buku 3 (1 eksemplar).
kode "$URL/buku" >/dev/null        # membuat tabel + data contoh bila baru
read -r k no <<<"$(pinjam 1 A-0001)"
cek F-08 302 "$k" "pinjam buku 1 (no. pinjam $no)"
cek F-09 400 "$(kode -d "buku=1&anggota=" "$URL/buku/pinjam")" \
  "pinjam tanpa no. anggota ditolak"
sisa=$(tersedia 3)
nos=()
for _ in $(seq 1 "$sisa"); do      # habiskan eksemplar buku 3
  read -r _ n <<<"$(pinjam 3 A-0002)"; nos+=("$n")
done
read -r k _ <<<"$(pinjam 3 A-0003)"
cek F-10 409 "$k" "buku 3 habis dipinjam -> ditolak, stok tetap $(tersedia 3)"
cek F-11 302 "$(kode -d "pinjam=$no" "$URL/buku/kembali")" \
  "kembalikan pinjaman $no"
cek F-11b 404 "$(kode -d "pinjam=$no" "$URL/buku/kembali")" \
  "kembalikan dua kali ditolak"
for n in "${nos[@]}"; do kode -d "pinjam=$n" "$URL/buku/kembali" >/dev/null; done

# F-12: 30 permintaan pinjam serentak ke buku 2; yang berhasil harus sama
# dengan eksemplar tersedia, sisanya 409, dan stok tidak pernah minus.
awal=$(tersedia 2)
hasil=$(seq 1 30 | xargs -P 30 -I{} curl -s -o /dev/null -m 15 \
  -w "%{http_code} %{redirect_url}\n" -d "buku=2&anggota=S-{}" \
  "$URL/buku/pinjam")
ok=$(grep -c "^302" <<<"$hasil")
tolak=$(grep -c "^409" <<<"$hasil")
akhir=$(tersedia 2)
cek F-12 "$awal/0" "$ok/$akhir" \
  "serentak 30: berhasil $ok, ditolak $tolak, tersedia $awal -> $akhir"
for n in $(sed -n 's/.*pinjam=\([0-9]*\).*/\1/p' <<<"$hasil"); do
  kode -d "pinjam=$n" "$URL/buku/kembali" >/dev/null   # kembalikan lagi
done

if [ "$gagal" -ne 0 ]; then echo "ADA YANG GAGAL"; exit 1; fi
echo "Semua test case lulus"
