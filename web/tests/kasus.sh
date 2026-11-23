# tests/kasus.sh: uji fungsional Kasus 0 · Perpustakaan kampus (K-01 dst.).
# Di-source oleh tests/fungsional.sh, jadi fungsi cek, kode, token, kirim,
# tunggu_pulih dan variabel URL, KUKI (sesi pengguna uji yang sudah masuk),
# SUREL, KERJA sudah tersedia. Jalankan dari akar repo.
echo "-- Kasus 0 · Perpustakaan kampus"
docker compose exec -T app php artisan kasus:petugas "$SUREL" >/dev/null

cek K-01 200 "$(kode "$URL/buku")" "katalog publik"
kepala=$(curl -s -m 10 -D - -o /dev/null "$URL/buku")
cc=$(grep -i '^cache-control:' <<<"$kepala" | tr -d '\r' | cut -d' ' -f2-)
cek K-02 "0/max-age=60, public" "$(grep -ci '^set-cookie:' <<<"$kepala")/$cc" \
  "/buku publik: tanpa cookie, boleh di-cache"
cek K-03 302 "$(kode "$URL/buku/kelola")" "sirkulasi tanpa login diarahkan ke /login"

# ---- petugas menambah buku + sampul + scan (object storage)
KODE="K$(date +%s)$RANDOM"            # kode baru setiap kali uji dijalankan
base64 --decode > "$KERJA/sampul.png" <<'PNG'
iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==
PNG
printf '%%PDF-1.4\n%% %s\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%%%EOF\n' "$KODE" > "$KERJA/scan.pdf"
t=$(token /buku/kelola)
read -r k tujuan <<<"$(kirim /buku --form-string "_token=$t" --form-string "kode=$KODE" \
  --form-string "judul=Uji K $KODE" --form-string "penulis=Tim Uji" --form-string "stok=2" \
  -F "sampul=@$KERJA/sampul.png;filename=sampul uji.png" \
  -F "scan=@$KERJA/scan.pdf;filename=scan uji.pdf")"
cek K-04 302 "$k" "tambah buku $KODE + sampul + scan (petugas) -> ${tujuan#"$URL"}"
id=$(curl -s -m 10 -G --data-urlencode "q=$KODE" "$URL/api/buku" | jq -r '.data[0].id')
n=$(curl -s -m 10 -G --data-urlencode "q=$KODE" "$URL/buku" | grep -c "Uji K $KODE")
cek K-05 1 "$n" "cari di katalog publik (q=$KODE) -> buku $id"

kepala=$(curl -s -m 10 -D - -o "$KERJA/sampul-unduh" "$URL/buku/$id/sampul")
cc=$(grep -i '^cache-control:' <<<"$kepala" | tr -d '\r' | cut -d' ' -f2-)
if cmp -s "$KERJA/sampul.png" "$KERJA/sampul-unduh"; then sama=ya; else sama=tidak; fi
cek K-06 "200/ya/max-age=60, public" "$(head -n 1 <<<"$kepala" | cut -d' ' -f2)/$sama/$cc" \
  "sampul dari object storage, isi sama, boleh di-cache"

halaman=$(curl -s -m 10 -b "$KUKI" "$URL/buku/$id")
tautan=$(sed -n 's/.*href="\([^"]*\/scan?[^"]*\)".*/\1/p' <<<"$halaman" | head -n 1 | sed 's/&amp;/\&/g')
curl -s -m 10 -o "$KERJA/scan-unduh" "$tautan"
if cmp -s "$KERJA/scan.pdf" "$KERJA/scan-unduh"; then sama=ya; else sama=tidak; fi
cek K-07 "200/ya" "$(kode "$tautan")/$sama" "unduh scan lewat tautan bertanda tangan, isi sama"
cek K-08 403 "$(kode "${tautan}x")" "tanda tangan tautan diubah ditolak"

printf '<?php echo 1;' > "$KERJA/jahat.png"
t=$(token /buku/kelola)
kirim /buku --form-string "_token=$t" --form-string "kode=J$KODE" --form-string "judul=Jahat" \
  --form-string "penulis=X" --form-string "stok=1" -F "sampul=@$KERJA/jahat.png" >/dev/null
if curl -s -m 10 -b "$KUKI" "$URL/buku/kelola" | grep -q "harus berkas bertipe"; then
  ditolak=ya; else ditolak=tidak; fi
cek K-09 ya "$ditolak" "PHP berkedok .png ditolak sebagai sampul"

# ---- sirkulasi: pinjam sampai habis, kembali sekali (JSON dengan Accept)
t=$(token /buku/kelola)
json() { curl -s -m 30 -b "$KUKI" -H 'Accept: application/json' --data-urlencode "_token=$t" "$@"; }
read -r k _ <<<"$(kirim /buku/pinjam --data-urlencode "_token=$t" -d "buku=$id&anggota=A-0001")"
cek K-10 302 "$k" "pinjam lewat formulir (buku $id, anggota A-0001)"
cek K-11 422 "$(json -o /dev/null -w '%{http_code}' -d "buku=$id" "$URL/buku/pinjam")" \
  "pinjam tanpa no. anggota ditolak"
jawab=$(json -w '\n%{http_code}' -d "buku=$id&anggota=A-0002&hari=1" "$URL/buku/pinjam")
pid=$(head -n 1 <<<"$jawab" | jq -r .id)
k3=$(json -o /dev/null -w '%{http_code}' -d "buku=$id&anggota=A-0003" "$URL/buku/pinjam")
cek K-12 "201/409" "$(tail -n 1 <<<"$jawab")/$k3" "pinjam eksemplar terakhir (no. $pid), lalu habis"
k1=$(json -o /dev/null -w '%{http_code}' -d "pinjam=$pid" "$URL/buku/kembali")
k2=$(json -o /dev/null -w '%{http_code}' -d "pinjam=$pid" "$URL/buku/kembali")
cek K-13 "200/404" "$k1/$k2" "kembalikan no. $pid, lalu kembalikan lagi"

# ---- 30 petugas meminjam judul yang sama (2 eksemplar) serentak
t=$(token /buku/kelola)
kirim /buku --form-string "_token=$t" --form-string "kode=S$KODE" --form-string "judul=Serentak $KODE" \
  --form-string "penulis=Tim Uji" --form-string "stok=2" >/dev/null
id2=$(curl -s -m 10 -G --data-urlencode "q=S$KODE" "$URL/api/buku" | jq -r '.data[0].id')
hasil=$(seq 30 | xargs -P 30 -I{} curl -s -o /dev/null -m 30 -w "%{http_code}\n" \
  -b "$KUKI" -H 'Accept: application/json' --data-urlencode "_token=$t" \
  -d "buku=$id2&anggota=A-9{}" "$URL/buku/pinjam" | sort | uniq -c | awk '{printf "%sx%s ", $1, $2}')
sisa=$(curl -s -m 10 "$URL/api/buku/$id2" | jq -r .tersedia)
cek K-14 "2x201 28x409/0" "${hasil% }/$sisa" "30 pinjam serentak, 2 eksemplar: stok akhir tidak minus"

status=$(curl -s -m 10 "$URL/api/buku/status")
cek K-15 "200/ya" "$(kode "$URL/api/buku/status")/$(jq -e 'has("tersedia") and has("terlambat")' \
  <<<"$status" >/dev/null && echo ya || echo tidak)" "/api/buku/status: $status"
