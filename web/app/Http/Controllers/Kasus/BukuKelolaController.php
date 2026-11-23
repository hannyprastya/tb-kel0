<?php

namespace App\Http\Controllers\Kasus;

use App\Models\Kasus\Buku;
use App\Models\Kasus\Pinjam;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Halaman petugas: tambah buku ke koleksi dan unggah sampul (gambar) atau
 * scan e-dokumen (PDF) ke object storage (disk s3: Floci di lab, Supabase
 * Storage di produksi).
 */
class BukuKelolaController extends KasusController
{
    /** Aturan berkas: jenis dibaca dari ISI berkas (mimes + mimetypes). */
    private const ATURAN_SAMPUL = ['nullable', 'bail', 'file', 'max:1024',
        'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp'];

    private const ATURAN_SCAN = ['nullable', 'bail', 'file', 'max:5120',
        'mimes:pdf', 'mimetypes:application/pdf'];

    private const NAMA_ISIAN = ['kode' => 'kode buku', 'sampul' => 'sampul', 'scan' => 'scan e-dokumen'];

    /** GET /buku/kelola : koleksi, peminjaman aktif, formulir pinjam/kembali/tambah. */
    public function kelola(Request $request): View
    {
        $this->pastikanPetugas($request);

        return view('kasus.kelola', [
            'buku' => Buku::query()->latest('id')->limit(50)->get(),
            'aktif' => Pinjam::aktif()->with('buku:id,kode,judul')->orderBy('jatuh_tempo')->limit(50)->get(),
            'ringkasan' => Buku::ringkasan(),
        ]);
    }

    /** POST /buku : tambah judul baru (+ sampul dan scan bila ada). */
    public function simpan(Request $request): RedirectResponse
    {
        $this->pastikanPetugas($request);
        $data = $request->validate([
            'kode' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/', 'unique:buku,kode'],
            'judul' => ['required', 'string', 'max:200'],
            'penulis' => ['required', 'string', 'max:120'],
            'tahun' => ['nullable', 'integer', 'between:1900,2100'],
            'stok' => ['required', 'integer', 'between:1,999'],
            'sampul' => self::ATURAN_SAMPUL,
            'scan' => self::ATURAN_SCAN,
        ], [], self::NAMA_ISIAN);

        $berkas = $this->simpanBerkas($request);
        try {
            $buku = Buku::query()->create([
                'kode' => $data['kode'],
                'judul' => $data['judul'],
                'penulis' => $data['penulis'],
                'tahun' => $data['tahun'] ?? null,
                'stok' => (int) $data['stok'],
                'tersedia' => (int) $data['stok'],
            ] + $berkas);
        } catch (UniqueConstraintViolationException) {
            // dua petugas mendaftarkan kode yang sama di detik yang sama
            $this->hapusBerkas($berkas);

            return back()->withErrors(['kode' => 'Kode buku sudah dipakai.'])->withInput();
        }

        return redirect()->route('kasus.buku.kelola')
            ->with('status', "Buku {$buku->kode} ({$buku->judul}) ditambahkan, {$buku->stok} eksemplar.");
    }

    /** POST /buku/{buku}/berkas : ganti sampul dan/atau scan buku yang sudah ada. */
    public function berkas(Request $request, Buku $buku): RedirectResponse
    {
        $this->pastikanPetugas($request);
        $request->validate([
            'sampul' => [...self::ATURAN_SAMPUL, 'required_without:scan'],
            'scan' => self::ATURAN_SCAN,
        ], [], self::NAMA_ISIAN);

        $baru = $this->simpanBerkas($request);
        $lama = array_intersect_key($buku->only(['sampul_path', 'scan_path']), $baru);
        $buku->update($baru);
        $this->hapusBerkas($lama);   // berkas lama dibuang setelah data menunjuk berkas baru

        return redirect()->route('kasus.buku.rincian', $buku)->with('status', 'Berkas tersimpan.');
    }

    /**
     * Simpan sampul/scan ke disk s3 dengan nama acak buatan server (bukan
     * nama kiriman klien: cegah ../, nama dobel, atau .php).
     *
     * @return array<string, string>
     */
    private function simpanBerkas(Request $request): array
    {
        $hasil = [];
        $sampul = $request->file('sampul');
        if ($sampul instanceof UploadedFile) {
            $path = $sampul->store('buku/sampul', 's3');
            abort_if($path === false, 500, 'Gagal menyimpan sampul');
            $hasil['sampul_path'] = $path;
            $hasil['sampul_mime'] = (string) $sampul->getMimeType();
        }
        $scan = $request->file('scan');
        if ($scan instanceof UploadedFile) {
            $path = $scan->store('buku/scan', 's3');
            abort_if($path === false, 500, 'Gagal menyimpan scan');
            $hasil['scan_path'] = $path;
            $hasil['scan_nama'] = $this->namaTampil($scan->getClientOriginalName());
        }

        return $hasil;
    }

    /** @param  array<string, string|null>  $berkas */
    private function hapusBerkas(array $berkas): void
    {
        foreach (['sampul_path', 'scan_path'] as $kolom) {
            if (! empty($berkas[$kolom])) {
                Storage::disk('s3')->delete($berkas[$kolom]);
            }
        }
    }
}
