<?php

namespace App\Http\Controllers\Kasus;

use App\Models\Kasus\Buku;
use App\Models\Kasus\Pinjam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pinjam dan kembali (petugas sirkulasi). Dengan header
 * Accept: application/json jawabannya JSON (201/409, 200/404) supaya
 * bisa diuji curl/k6; dari formulir jawabannya redirect + pesan.
 */
class SirkulasiController extends KasusController
{
    /** POST /buku/pinjam : buku (no. buku), anggota (A-NNNN), hari (1-14, bawaan 7). */
    public function pinjam(Request $request): JsonResponse|RedirectResponse
    {
        $this->pastikanPetugas($request);
        $data = $request->validate([
            'buku' => ['required', 'integer', Rule::exists('buku', 'id')],
            'anggota' => ['required', 'string', 'regex:/^A-[0-9]{1,6}$/'],
            'hari' => ['nullable', 'integer', 'between:1,14'],
        ], [], ['buku' => 'no. buku', 'anggota' => 'no. anggota']);

        $pinjam = Buku::pinjamkan((int) $data['buku'], (string) $data['anggota'],
            (int) ($data['hari'] ?? 7), $request->user()?->id);

        if ($pinjam === null) {
            return $request->expectsJson()
                ? response()->json(['pesan' => 'eksemplar sedang dipinjam semua'], 409)
                : back()->withErrors(['buku' => 'Eksemplar buku ini sedang dipinjam semua.'])->withInput();
        }

        $tanggal = $pinjam->jatuh_tempo->toDateString();

        return $request->expectsJson()
            ? response()->json(['id' => $pinjam->id, 'buku' => $pinjam->buku_id, 'jatuh_tempo' => $tanggal], 201)
            : redirect()->route('kasus.buku.kelola')
                ->with('status', "Peminjaman no. {$pinjam->id} tercatat, jatuh tempo {$tanggal}.");
    }

    /** POST /buku/kembali : pinjam (no. peminjaman). */
    public function kembali(Request $request): JsonResponse|RedirectResponse
    {
        $this->pastikanPetugas($request);
        $data = $request->validate([
            'pinjam' => ['required', 'integer', 'min:1'],
        ], [], ['pinjam' => 'no. peminjaman']);

        $pinjam = Pinjam::kembalikan((int) $data['pinjam']);
        if ($pinjam === null) {
            return $request->expectsJson()
                ? response()->json(['pesan' => 'peminjaman aktif tidak ada'], 404)
                : back()->withErrors(['pinjam' => 'Peminjaman aktif dengan nomor itu tidak ada.']);
        }

        return $request->expectsJson()
            ? response()->json(['id' => $pinjam->id, 'dikembalikan_pada' => $pinjam->dikembalikan_pada?->toIso8601String()])
            : redirect()->route('kasus.buku.kelola')->with('status', "Peminjaman no. {$pinjam->id} dikembalikan.");
    }
}
