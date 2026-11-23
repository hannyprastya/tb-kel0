<?php

namespace App\Console\Commands\Kasus;

use App\Models\Kasus\Petugas;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Menjadikan pengguna (yang sudah mendaftar lewat /register) petugas
 * sirkulasi perpustakaan:
 *   php artisan kasus:petugas ani@contoh.test
 *   php artisan kasus:petugas ani@contoh.test --cabut
 * Di compose: docker compose exec app php artisan kasus:petugas EMAIL
 */
class JadikanPetugas extends Command
{
    protected $signature = 'kasus:petugas {email : email akun yang sudah terdaftar} {--cabut : hapus dari daftar petugas}';

    protected $description = 'Jadikan pengguna petugas modul kasus (atau cabut)';

    public function handle(): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();
        if ($user === null) {
            $this->error('Pengguna dengan email itu belum terdaftar.');

            return self::FAILURE;
        }

        if ($this->option('cabut')) {
            Petugas::query()->where('user_id', $user->id)->delete();
            $this->info("{$user->email} bukan petugas lagi.");

            return self::SUCCESS;
        }

        Petugas::query()->firstOrCreate(['user_id' => $user->id]);
        $this->info("{$user->email} sekarang petugas.");

        return self::SUCCESS;
    }
}
