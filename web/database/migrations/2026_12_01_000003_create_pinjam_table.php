<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Peminjaman (Kasus 0). Anggota dicatat dengan nomor anggota saja, tanpa
// nama atau email (minimasi data, UU PDP). Indeks parsial: tugas pengingat
// harian hanya membaca peminjaman yang belum dikembalikan.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pinjam', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buku_id')->constrained('buku')->restrictOnDelete();
            $table->string('no_anggota', 12);                 // mis. A-0001 (fiktif)
            $table->date('jatuh_tempo');
            $table->timestamp('dikembalikan_pada')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();   // petugas
            $table->timestamps();
        });
        DB::statement('CREATE INDEX pinjam_aktif_jatuh_tempo ON pinjam (jatuh_tempo) WHERE dikembalikan_pada IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('pinjam');
    }
};
