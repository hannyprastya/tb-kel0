<?php

namespace App\Models\Kasus;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Satu judul di katalog perpustakaan (Kasus 0). Kolom tersedia = eksemplar
 * yang sedang ada di rak; database menjaga CHECK (tersedia BETWEEN 0 AND
 * stok), jadi stok tidak pernah minus walau kode salah.
 *
 * @property int $id
 * @property string $kode
 * @property string $judul
 * @property string $penulis
 * @property int|null $tahun
 * @property int $stok
 * @property int $tersedia
 * @property string|null $sampul_path
 * @property string|null $sampul_mime
 * @property string|null $scan_path
 * @property string|null $scan_nama
 * @property \Illuminate\Support\Carbon $created_at
 */
class Buku extends Model
{
    protected $table = 'buku';

    protected $fillable = [
        'kode', 'judul', 'penulis', 'tahun', 'stok', 'tersedia',
        'sampul_path', 'sampul_mime', 'scan_path', 'scan_nama',
    ];

    protected function casts(): array
    {
        return [
            'tahun' => 'integer',
            'stok' => 'integer',
            'tersedia' => 'integer',
        ];
    }

    /** @return HasMany<Pinjam, $this> */
    public function pinjam(): HasMany
    {
        return $this->hasMany(Pinjam::class);
    }

    /**
     * Cari judul, penulis, atau kode (tanpa membedakan huruf besar/kecil).
     * Tanda % dan _ dari pengunjung di-escape supaya bukan wildcard.
     *
     * @return Builder<static>
     */
    public static function cari(string $q): Builder
    {
        $pola = '%'.addcslashes($q, '%_\\').'%';

        return static::query()->when($q !== '', fn (Builder $w) => $w->where(
            fn (Builder $x) => $x->where('judul', 'ilike', $pola)
                ->orWhere('penulis', 'ilike', $pola)
                ->orWhere('kode', 'ilike', $pola)));
    }

    /**
     * Pinjamkan satu eksemplar. Satu UPDATE bersyarat "tersedia > 0":
     * PostgreSQL mengunci baris buku itu, jadi dua petugas yang menekan
     * Pinjam di detik yang sama antre, dan yang kedua membaca nilai yang
     * sudah dikurangi. Null = eksemplar sedang dipinjam semua.
     */
    public static function pinjamkan(int $bukuId, string $noAnggota, int $hari, ?int $petugasId): ?Pinjam
    {
        return DB::transaction(function () use ($bukuId, $noAnggota, $hari, $petugasId) {
            $berkurang = DB::table('buku')->where('id', $bukuId)->where('tersedia', '>', 0)
                ->decrement('tersedia', 1, ['updated_at' => now()]);
            if ($berkurang === 0) {
                return null;
            }

            return Pinjam::query()->create([
                'buku_id' => $bukuId,
                'no_anggota' => $noAnggota,
                'jatuh_tempo' => now()->addDays($hari)->toDateString(),
                'user_id' => $petugasId,
            ]);
        });
    }

    /**
     * Ringkasan untuk /api/buku/status (k6, uptime monitor): hanya angka,
     * tanpa nomor anggota.
     *
     * @return array{judul: int, eksemplar: int, tersedia: int, dipinjam: int, terlambat: int, jatuh_tempo_besok: int}
     */
    public static function ringkasan(): array
    {
        $b = DB::table('buku')
            ->selectRaw('count(*) as judul, coalesce(sum(stok), 0) as eksemplar, coalesce(sum(tersedia), 0) as tersedia')
            ->first();
        // tanggal dihitung di PHP (zona waktu aplikasi, Asia/Jakarta)
        $p = DB::table('pinjam')->whereNull('dikembalikan_pada')
            ->selectRaw('count(*) as dipinjam')
            ->selectRaw('count(*) filter (where jatuh_tempo < ?) as terlambat', [now()->toDateString()])
            ->selectRaw('count(*) filter (where jatuh_tempo = ?) as besok', [now()->addDay()->toDateString()])
            ->first();

        return [
            'judul' => (int) ($b->judul ?? 0),
            'eksemplar' => (int) ($b->eksemplar ?? 0),
            'tersedia' => (int) ($b->tersedia ?? 0),
            'dipinjam' => (int) ($p->dipinjam ?? 0),
            'terlambat' => (int) ($p->terlambat ?? 0),
            'jatuh_tempo_besok' => (int) ($p->besok ?? 0),
        ];
    }
}
