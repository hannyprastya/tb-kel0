<?php

namespace App\Models\Kasus;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Satu peminjaman (satu eksemplar, satu anggota). Nomor anggota saja,
 * tanpa nama (minimasi data, UU PDP).
 *
 * @property int $id
 * @property int $buku_id
 * @property string $no_anggota
 * @property \Illuminate\Support\Carbon $jatuh_tempo
 * @property \Illuminate\Support\Carbon|null $dikembalikan_pada
 * @property int|null $user_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read Buku $buku
 */
class Pinjam extends Model
{
    protected $table = 'pinjam';

    protected $fillable = ['buku_id', 'no_anggota', 'jatuh_tempo', 'dikembalikan_pada', 'user_id'];

    protected function casts(): array
    {
        return [
            'jatuh_tempo' => 'date',
            'dikembalikan_pada' => 'datetime',
        ];
    }

    /** @return BelongsTo<Buku, $this> */
    public function buku(): BelongsTo
    {
        return $this->belongsTo(Buku::class);
    }

    /** @return BelongsTo<User, $this> */
    public function petugas(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Peminjaman yang belum dikembalikan.
     *
     * @return Builder<static>
     */
    public static function aktif(): Builder
    {
        return static::query()->whereNull('dikembalikan_pada');
    }

    /**
     * Catat pengembalian. Baris pinjam dikunci (FOR UPDATE) dan syarat
     * "belum dikembalikan" dicek ulang setelah kunci didapat, jadi satu
     * peminjaman hanya bisa dikembalikan sekali walau tombol ditekan dua
     * kali. Null = tidak ada atau sudah dikembalikan.
     */
    public static function kembalikan(int $id): ?self
    {
        return DB::transaction(function () use ($id) {
            $pinjam = self::aktif()->whereKey($id)->lockForUpdate()->first();
            if ($pinjam === null) {
                return null;
            }
            $pinjam->update(['dikembalikan_pada' => now()]);
            DB::table('buku')->where('id', $pinjam->buku_id)->whereColumn('tersedia', '<', 'stok')
                ->increment('tersedia', 1, ['updated_at' => now()]);

            return $pinjam;
        });
    }

    public function terlambat(): bool
    {
        return $this->dikembalikan_pada === null && $this->jatuh_tempo->lt(now()->startOfDay());
    }
}
