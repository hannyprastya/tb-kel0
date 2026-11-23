<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Daftar pengguna yang menjadi petugas sirkulasi perpustakaan (Kasus 0).
// Anggota cukup punya akun Breeze; menjadi petugas lewat perintah
//   php artisan kasus:petugas EMAIL
// Tabel terpisah (bukan kolom baru di "users") supaya tabel starter tidak
// diubah dan modul bisa dilepas bersih dengan migrate:rollback.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petugas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petugas');
    }
};
