<?php

namespace App\Http\Controllers\Kasus;

use App\Models\Kasus\Buku;
use App\Models\Kasus\Petugas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Katalog untuk semua orang (grup publik, boleh di-cache), sampul buku,
 * rincian untuk anggota yang login, dan unduh scan e-dokumen lewat tautan
 * bertanda tangan.
 */
class KatalogController extends KasusController
{
    /** GET /buku?q= (grup publik): katalog + cari, sama untuk semua pengunjung. */
    public function katalog(Request $request): View
    {
        $q = Str::limit(trim($request->string('q')->toString()), 50, '');

        return view('kasus.katalog', [
            'q' => $q,
            'buku' => Buku::cari($q)->orderBy('judul')->limit(50)
                ->get(['id', 'kode', 'judul', 'penulis', 'tahun', 'stok', 'tersedia', 'sampul_path']),
        ]);
    }

    /**
     * GET /buku/{buku}/sampul (grup publik): gambar sampul dibaca dari
     * object storage. Boleh di-cache Nginx/CDN, sehingga sampul yang sering
     * dilihat tidak diambil ulang dari storage setiap kali.
     */
    public function sampul(Buku $buku): StreamedResponse
    {
        abort_if($buku->sampul_path === null, 404);

        return Storage::disk('s3')->response($buku->sampul_path, $buku->kode.'.'.$this->ekstensi($buku), [
            'Content-Type' => $buku->sampul_mime ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** GET /buku/{buku} (login): rincian + tautan scan bertanda tangan 5 menit. */
    public function rincian(Request $request, Buku $buku): View
    {
        $petugas = Petugas::adalah($request->user());

        return view('kasus.buku', [
            'b' => $buku,
            'petugas' => $petugas,
            'tautanScan' => $buku->scan_path === null ? null : URL::temporarySignedRoute(
                'kasus.buku.scan', now()->addMinutes(self::MENIT_TAUTAN), ['buku' => $buku->id]),
            'menit' => self::MENIT_TAUTAN,
            'pinjamAktif' => $petugas
                ? $buku->pinjam()->whereNull('dikembalikan_pada')->orderBy('jatuh_tempo')->limit(50)->get()
                : collect(),
        ]);
    }

    /**
     * GET /buku/{buku}/scan?expires=...&signature=... (middleware 'signed').
     * Siapa pun yang memegang tautan bisa mengunduh sampai kedaluwarsa;
     * tautannya hanya dibuat di halaman rincian (anggota yang login).
     */
    public function unduhScan(Buku $buku): StreamedResponse
    {
        abort_if($buku->scan_path === null, 404);

        return Storage::disk('s3')->download($buku->scan_path, $buku->scan_nama ?? $buku->kode.'.pdf', [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function ekstensi(Buku $buku): string
    {
        return match ($buku->sampul_mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }
}
