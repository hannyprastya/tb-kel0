<?php

namespace App\Models\Kasus;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengguna yang menjadi petugas sirkulasi perpustakaan (boleh menambah
 * buku, mengunggah sampul/scan, mencatat pinjam dan kembali).
 *
 * @property int $id
 * @property int $user_id
 * @property \Illuminate\Support\Carbon $created_at
 */
class Petugas extends Model
{
    protected $table = 'petugas';

    protected $fillable = ['user_id'];

    /** Apakah pengguna yang sedang masuk ini petugas? Tamu (null) = bukan. */
    public static function adalah(?Authenticatable $user): bool
    {
        return $user !== null
            && static::query()->where('user_id', $user->getAuthIdentifier())->exists();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
