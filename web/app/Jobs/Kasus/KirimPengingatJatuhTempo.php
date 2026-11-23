<?php

namespace App\Jobs\Kasus;

use App\Models\Kasus\Pinjam;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pengingat jatuh tempo satu peminjaman (Kasus 0, P14), ditulis ke log
 * sebagai pengganti API email/WhatsApp. Dijalankan service worker.
 *
 * Idempotensi: kunci "pinjam-ID-TANGGAL_JATUH_TEMPO" disimpan di tabel
 * notifikasi (UNIQUE). Job yang diulang worker, perintah harian yang
 * dijalankan dua kali, atau pesan SQS yang terkirim dua kali tetap
 * menghasilkan satu pengingat. Pinjaman yang diperpanjang mendapat tanggal
 * baru, jadi kunci baru.
 */
class KirimPengingatJatuhTempo implements ShouldQueue
{
    use Queueable;

    /**
     * 5 percobaan, jeda 1, 3, 10, 20 menit (total ±34 menit < batas tunda
     * 1 jam, kebutuhan.md bagian 4): layanan email/WA yang mati ±15 menit
     * pulih sendiri tanpa operator. Setelah itu job masuk failed_jobs
     * (runbook bagian 6). Nilai di sini mengalahkan --tries=3 di compose.
     */
    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [60, 180, 600, 1200];

    /** Satu panggilan API email/WA ±1–5 s; 10 s = cadangan 2× (visibility SQS 60 s = 6 × 10). */
    public int $timeout = 10;

    public function __construct(public int $pinjamId) {}

    public function handle(): void
    {
        $pinjam = Pinjam::query()->with('buku:id,kode,judul')->find($this->pinjamId);
        if ($pinjam === null || $pinjam->dikembalikan_pada !== null) {
            Log::info("PENGINGAT pinjam-{$this->pinjamId} tidak perlu lagi (sudah kembali/tidak ada), dilewati");

            return;
        }

        $kunci = $pinjam->kunciPengingat();
        $kepada = "anggota {$pinjam->no_anggota}";
        $pesan = "Buku \"{$pinjam->buku->judul}\" ({$pinjam->buku->kode}) jatuh tempo "
            .$pinjam->jatuh_tempo->format('d-m-Y').'. Mohon dikembalikan ke '.config('app.pemilik').'.';

        // Catat dan kirim dalam satu transaksi: gagal kirim = catatan batal,
        // percobaan berikutnya mengirim lagi; sudah tercatat = tidak dikirim lagi.
        DB::transaction(function () use ($kunci, $kepada, $pesan) {
            $baru = DB::table('notifikasi')->insertOrIgnore([
                'kunci' => $kunci,
                'kepada' => $kepada,
                'pesan' => $pesan,
                'terkirim_pada' => now(),
            ]);
            if ($baru === 0) {
                Log::info("PENGINGAT {$kunci} sudah pernah dikirim, dilewati");

                return;
            }
            Log::info("PENGINGAT {$kunci} terkirim ke {$kepada}: {$pesan}");
        });
    }
}
