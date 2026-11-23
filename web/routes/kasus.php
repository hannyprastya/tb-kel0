<?php

use App\Http\Controllers\Kasus\BukuApiController;
use App\Http\Controllers\Kasus\BukuKelolaController;
use App\Http\Controllers\Kasus\KatalogController;
use App\Http\Controllers\Kasus\SirkulasiController;
use Illuminate\Support\Facades\Route;

// Kasus 0 · Perpustakaan kampus. Daftar rute lengkap: web/KASUS-00.md.

// Halaman (sesi + CSRF)
Route::middleware('web')->group(function () {
    Route::middleware('auth')->group(function () {
        // petugas sirkulasi (cek di controller): kelola koleksi, pinjam, kembali
        Route::get('/buku/kelola', [BukuKelolaController::class, 'kelola'])->name('kasus.buku.kelola');
        Route::post('/buku', [BukuKelolaController::class, 'simpan'])->name('kasus.buku.simpan');
        Route::post('/buku/{buku}/berkas', [BukuKelolaController::class, 'berkas'])
            ->whereNumber('buku')->name('kasus.buku.berkas');
        Route::post('/buku/pinjam', [SirkulasiController::class, 'pinjam'])->name('kasus.buku.pinjam');
        Route::post('/buku/kembali', [SirkulasiController::class, 'kembali'])->name('kasus.buku.kembali');

        // anggota (semua yang login): rincian buku + tautan e-dokumen
        Route::get('/buku/{buku}', [KatalogController::class, 'rincian'])
            ->whereNumber('buku')->name('kasus.buku.rincian');
    });

    // tautan unduh scan bertanda tangan + kedaluwarsa (dibuat di halaman rincian)
    Route::get('/buku/{buku}/scan', [KatalogController::class, 'unduhScan'])
        ->whereNumber('buku')->middleware('signed')->name('kasus.buku.scan');
});

// Halaman publik baca-saja (tanpa sesi), boleh di-cache 60 detik
Route::middleware('publik')->group(function () {
    Route::get('/buku', [KatalogController::class, 'katalog'])->name('kasus.buku.katalog');
    Route::get('/buku/{buku}/sampul', [KatalogController::class, 'sampul'])
        ->whereNumber('buku')->name('kasus.buku.sampul');
});

// JSON untuk k6, uptime monitor, dan uji fungsional: /api/buku/...
Route::middleware('api')->prefix('api')->group(function () {
    Route::get('/buku/status', [BukuApiController::class, 'status']);
    Route::get('/buku', [BukuApiController::class, 'cari']);
    Route::get('/buku/{buku}', [BukuApiController::class, 'tampil'])->whereNumber('buku');
});
