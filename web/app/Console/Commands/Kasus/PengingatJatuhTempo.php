<?php

namespace App\Console\Commands\Kasus;

use App\Jobs\Kasus\KirimPengingatJatuhTempo;
use App\Models\Kasus\Pinjam;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tugas terjadwal Kasus 0 (routes/kasus-jadwal.php, tiap hari 07.00 WIB):
 * titip satu job pengingat untuk setiap peminjaman aktif yang jatuh tempo
 * paling lambat besok dan belum pernah diingatkan untuk tanggal itu.
 * Perintah ini cepat (hanya menitip ke antrean); pengiriman dikerjakan
 * worker. Aman dijalankan berulang: yang sudah terkirim dilewati, dan yang
 * terlewat (mis. Render sedang tidur jam 07.00) ikut terkirim saat
 * perintah berikutnya jalan.
 */
class PengingatJatuhTempo extends Command
{
    protected $signature = 'kasus:pengingat-jatuh-tempo';

    protected $description = 'Titip pengingat untuk peminjaman yang jatuh tempo paling lambat besok';

    public function handle(): int
    {
        $batas = now()->addDay()->toDateString();
        $calon = Pinjam::aktif()->whereDate('jatuh_tempo', '<=', $batas)
            ->orderBy('jatuh_tempo')->limit(1000)->get(['id', 'jatuh_tempo']);

        $kunci = $calon->mapWithKeys(fn (Pinjam $p) => [$p->kunciPengingat() => $p->id]);
        $sudah = DB::table('notifikasi')->whereIn('kunci', $kunci->keys())->pluck('kunci')->all();

        $dititip = 0;
        foreach ($kunci->except($sudah) as $id) {
            KirimPengingatJatuhTempo::dispatch((int) $id);
            $dititip++;
        }

        $teks = sprintf('PENGINGAT JATUH TEMPO %s: calon=%d dititipkan=%d sudah_terkirim=%d (batas %s)',
            now()->toDateString(), $calon->count(), $dititip, count($sudah), $batas);
        Log::info($teks);
        $this->info($teks);

        return self::SUCCESS;
    }
}
