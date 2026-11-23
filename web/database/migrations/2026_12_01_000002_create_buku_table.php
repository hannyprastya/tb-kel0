<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Katalog perpustakaan (Kasus 0). Isi sampul dan scan ada di object storage
// (disk s3); tabel ini hanya menyimpan letaknya (nama acak buatan server).
// CHECK (tersedia BETWEEN 0 AND stok) = pengaman terakhir di database:
// walau kode salah atau dua petugas menekan Pinjam bersamaan, stok tidak
// pernah minus dan tidak pernah lebih dari jumlah eksemplar.
return new class extends Migration
{
    public function up(): void
    {
        // Database lama (Neon atau volume compose P9-P11) sudah berisi tabel
        // buku dan pinjam buatan index.php (tanpa kolom kode). Tidak dihapus:
        // namanya diganti buku_lama / pinjam_lama beserta sequence dan
        // primary key-nya, seperti tamu -> tamu_lama di migrasi starter.
        if (Schema::hasTable('buku') && ! Schema::hasColumn('buku', 'kode')) {
            foreach (['buku', 'pinjam'] as $lama) {
                if (Schema::hasTable($lama)) {
                    Schema::rename($lama, $lama.'_lama');
                    DB::statement("ALTER SEQUENCE IF EXISTS {$lama}_id_seq RENAME TO {$lama}_lama_id_seq");
                    DB::statement("ALTER INDEX IF EXISTS {$lama}_pkey RENAME TO {$lama}_lama_pkey");
                }
            }
        }

        Schema::create('buku', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 20)->unique();          // no. induk, mis. B-0001
            $table->string('judul', 200);
            $table->string('penulis', 120);
            $table->unsignedSmallInteger('tahun')->nullable();
            $table->unsignedInteger('stok');               // eksemplar yang dimiliki
            $table->unsignedInteger('tersedia');           // eksemplar di rak
            $table->string('sampul_path')->nullable()->unique();
            $table->string('sampul_mime', 40)->nullable();
            $table->string('scan_path')->nullable()->unique();
            $table->string('scan_nama', 120)->nullable();  // hanya untuk ditampilkan
            $table->timestamps();
        });
        DB::statement('ALTER TABLE buku ADD CONSTRAINT buku_stok_cek CHECK (stok BETWEEN 1 AND 999)');
        DB::statement('ALTER TABLE buku ADD CONSTRAINT buku_tersedia_cek CHECK (tersedia BETWEEN 0 AND stok)');
    }

    public function down(): void
    {
        Schema::dropIfExists('buku');
    }
};
