<?php

namespace Database\Seeders;

use App\Models\Kasus\Buku;
use App\Models\Kasus\Petugas;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Data contoh Kasus 0 (FIKTIF). Dipanggil DatabaseSeeder:
 *   docker compose exec app php artisan db:seed --force
 */
class KasusSeeder extends Seeder
{
    public function run(): void
    {
        // akun contoh dari DatabaseSeeder dijadikan petugas sirkulasi
        $contoh = User::query()->where('email', 'contoh@example.com')->first();
        if ($contoh !== null) {
            Petugas::query()->firstOrCreate(['user_id' => $contoh->id]);
        }

        foreach ([
            ['B-0001', 'Pengantar Komputasi Awan', 'Tim Dosen', 2025, 3],
            ['B-0002', 'Basis Data Terapan', 'Tim Penyusun', 2024, 2],
            ['B-0003', 'Jaringan Komputer Dasar', 'Tim Redaksi', 2023, 1],
        ] as [$kode, $judul, $penulis, $tahun, $stok]) {
            Buku::query()->firstOrCreate(['kode' => $kode], [
                'judul' => $judul, 'penulis' => $penulis, 'tahun' => $tahun,
                'stok' => $stok, 'tersedia' => $stok,
            ]);
        }
    }
}
