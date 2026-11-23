<?php

namespace App\Http\Controllers\Kasus;

use App\Models\Kasus\Buku;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * JSON /api/buku/... (tanpa sesi, tanpa CSRF) untuk k6, uptime monitor,
 * dan uji fungsional. Hanya baca; tidak ada nomor anggota di jawaban.
 */
class BukuApiController extends KasusController
{
    /** GET /api/buku/status : ringkasan stok dan peminjaman. */
    public function status(): JsonResponse
    {
        return response()->json(Buku::ringkasan());
    }

    /** GET /api/buku?q= : 20 judul pertama yang cocok. */
    public function cari(Request $request): JsonResponse
    {
        $q = Str::limit(trim($request->string('q')->toString()), 50, '');

        return response()->json(['data' => Buku::cari($q)->orderBy('judul')->limit(20)
            ->get(['id', 'kode', 'judul', 'penulis', 'tahun', 'stok', 'tersedia'])]);
    }

    /** GET /api/buku/{buku} : satu judul, termasuk eksemplar tersedia. */
    public function tampil(Buku $buku): JsonResponse
    {
        return response()->json([
            'id' => $buku->id,
            'kode' => $buku->kode,
            'judul' => $buku->judul,
            'stok' => $buku->stok,
            'tersedia' => $buku->tersedia,
            'sampul' => $buku->sampul_path !== null,
            'scan' => $buku->scan_path !== null,
        ]);
    }
}
