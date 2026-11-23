<?php

namespace App\Http\Controllers\Kasus;

use App\Http\Controllers\Controller;
use App\Models\Kasus\Petugas;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Dasar controller modul perpustakaan: cek petugas dan nama berkas
 * unggahan untuk ditampilkan.
 */
abstract class KasusController extends Controller
{
    /** Tautan unduh scan berlaku 5 menit, seperti presigned URL (P4). */
    protected const MENIT_TAUTAN = 5;

    /** Halaman petugas: pengguna masuk yang bukan petugas mendapat 403. */
    protected function pastikanPetugas(Request $request): void
    {
        abort_unless(Petugas::adalah($request->user()), 403, 'Halaman ini khusus petugas perpustakaan.');
    }

    /** Nama asli berkas hanya untuk ditampilkan: tanpa folder, tanpa karakter kontrol. */
    protected function namaTampil(string $asli): string
    {
        $nama = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $asli))) ?? '';

        return Str::limit($nama !== '' ? $nama : 'berkas', 120, '');
    }
}
