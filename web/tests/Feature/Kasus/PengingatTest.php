<?php

namespace Tests\Feature\Kasus;

use App\Jobs\Kasus\KirimPengingatJatuhTempo;
use App\Models\Kasus\Buku;
use App\Models\Kasus\Pinjam;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Kasus 0 · pengingat jatuh tempo (P14): job idempoten + tugas terjadwal. */
class PengingatTest extends TestCase
{
    use RefreshDatabase;

    private function pinjam(int $hari, string $anggota = 'A-0001'): Pinjam
    {
        $b = Buku::query()->firstOrCreate(['kode' => 'B-0001'], ['judul' => 'Pengantar Komputasi Awan',
            'penulis' => 'Tim Dosen', 'stok' => 10, 'tersedia' => 10]);
        $p = Buku::pinjamkan($b->id, $anggota, max(1, $hari), null);
        $this->assertInstanceOf(Pinjam::class, $p);
        if ($hari < 1) {   // jatuh tempo hari ini atau sudah lewat
            $p->update(['jatuh_tempo' => now()->addDays($hari)->toDateString()]);
        }

        return $p;
    }

    public function test_perintah_hanya_menitip_pinjaman_aktif_yang_jatuh_tempo_besok_atau_lewat(): void
    {
        Queue::fake();
        $besok = $this->pinjam(1, 'A-0001');
        $telat = $this->pinjam(-3, 'A-0002');
        $this->pinjam(7, 'A-0003');                       // masih lama
        $kembali = $this->pinjam(1, 'A-0004');
        Pinjam::kembalikan($kembali->id);

        $this->artisan('kasus:pengingat-jatuh-tempo')
            ->expectsOutputToContain('calon=2 dititipkan=2 sudah_terkirim=0')->assertSuccessful();

        Queue::assertPushed(KirimPengingatJatuhTempo::class, 2);
        Queue::assertPushed(KirimPengingatJatuhTempo::class, fn ($j) => $j->pinjamId === $besok->id);
        Queue::assertPushed(KirimPengingatJatuhTempo::class, fn ($j) => $j->pinjamId === $telat->id);
    }

    public function test_job_diulang_tetap_satu_pengingat(): void
    {
        Log::spy();
        $p = $this->pinjam(1);
        $job = new KirimPengingatJatuhTempo($p->id);

        $job->handle();
        $job->handle();   // seolah-olah worker mengulang job yang sama

        $this->assertDatabaseCount('notifikasi', 1);
        $this->assertDatabaseHas('notifikasi', ['kunci' => $p->kunciPengingat(), 'kepada' => 'anggota A-0001']);
        Log::shouldHaveReceived('info')->withArgs(fn ($m) => str_contains($m, 'terkirim ke anggota A-0001'))->once();
        Log::shouldHaveReceived('info')->withArgs(fn ($m) => str_contains($m, 'sudah pernah dikirim'))->once();
        $this->getJson("/api/buku/pinjam/{$p->id}")->assertOk()
            ->assertJson(['status' => 'aktif', 'pengingat_terkirim' => true])
            ->assertJsonMissingPath('no_anggota');
    }

    public function test_perintah_dijalankan_dua_kali_tidak_mengirim_dobel(): void
    {
        $this->pinjam(1);                       // QUEUE_CONNECTION=sync di uji: job langsung jalan
        $this->artisan('kasus:pengingat-jatuh-tempo')->expectsOutputToContain('dititipkan=1')->assertSuccessful();
        $this->artisan('kasus:pengingat-jatuh-tempo')
            ->expectsOutputToContain('dititipkan=0 sudah_terkirim=1')->assertSuccessful();
        $this->assertDatabaseCount('notifikasi', 1);
    }

    public function test_diperpanjang_berarti_kunci_baru(): void
    {
        $p = $this->pinjam(1);
        (new KirimPengingatJatuhTempo($p->id))->handle();
        $p->update(['jatuh_tempo' => now()->addDays(8)->toDateString()]);
        $this->travel(7)->days();
        (new KirimPengingatJatuhTempo($p->id))->handle();

        $this->assertDatabaseCount('notifikasi', 2);
    }

    public function test_pinjaman_yang_sudah_kembali_tidak_diingatkan(): void
    {
        $p = $this->pinjam(1);
        Pinjam::kembalikan($p->id);

        (new KirimPengingatJatuhTempo($p->id))->handle();

        $this->assertDatabaseCount('notifikasi', 0);
        $this->getJson("/api/buku/pinjam/{$p->id}")->assertJson(['status' => 'kembali', 'pengingat_terkirim' => false]);
    }

    public function test_percobaan_ulang_cukup_untuk_gangguan_15_menit(): void
    {
        $job = new KirimPengingatJatuhTempo(1);

        $this->assertSame(5, $job->tries);
        $this->assertGreaterThanOrEqual(15 * 60, array_sum($job->backoff));
        $this->assertLessThan(60 * 60, array_sum($job->backoff));
    }

    public function test_terdaftar_di_jadwal_tiap_hari_jam_7(): void
    {
        $jadwal = collect(app(Schedule::class)->events())
            ->first(fn ($e) => $e->description === 'kasus:pengingat-jatuh-tempo');

        $this->assertNotNull($jadwal);
        $this->assertSame('0 7 * * *', $jadwal->expression);
    }
}
