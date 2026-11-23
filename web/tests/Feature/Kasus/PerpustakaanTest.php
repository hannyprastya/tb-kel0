<?php

namespace Tests\Feature\Kasus;

use App\Models\Kasus\Buku;
use App\Models\Kasus\Petugas;
use App\Models\Kasus\Pinjam;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** Kasus 0 · Perpustakaan kampus: katalog, sirkulasi, unggah sampul/scan. */
class PerpustakaanTest extends TestCase
{
    use RefreshDatabase;

    private function petugas(): User
    {
        $user = User::factory()->create();
        Petugas::query()->create(['user_id' => $user->id]);

        return $user;
    }

    private function buku(string $kode = 'B-0001', int $stok = 2, string $judul = 'Pengantar Komputasi Awan'): Buku
    {
        return Buku::query()->create(['kode' => $kode, 'judul' => $judul, 'penulis' => 'Tim Dosen',
            'stok' => $stok, 'tersedia' => $stok]);
    }

    /** Berkas unggahan dengan ISI tertentu (jenis dibaca dari isi, bukan nama). */
    private function berkas(string $nama, string $isi): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'uji');
        file_put_contents($path, $isi);

        return new UploadedFile($path, $nama, null, null, true);
    }

    private function png(): UploadedFile
    {
        return $this->berkas('sampul.png', (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    }

    private function pdf(string $nama = 'scan.pdf'): UploadedFile
    {
        return $this->berkas($nama, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");
    }

    public function test_katalog_publik_bisa_dicari_dan_boleh_di_cache(): void
    {
        $this->buku('B-0001', 3, 'Pengantar Komputasi Awan');
        $this->buku('B-0002', 1, 'Basis Data Terapan');

        $this->get('/buku')->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, public')
            ->assertHeaderMissing('Set-Cookie')
            ->assertSee('Pengantar Komputasi Awan')->assertSee('3/3');
        $this->get('/buku?q=basis')->assertOk()
            ->assertSee('Basis Data Terapan')->assertDontSee('Pengantar Komputasi Awan');
        $this->get('/buku?q=%25')->assertOk()->assertSee('Tidak ada buku yang cocok');   // % bukan wildcard
    }

    public function test_sirkulasi_hanya_untuk_petugas(): void
    {
        $b = $this->buku();

        $this->get('/buku/kelola')->assertRedirect('/login');
        $this->post('/buku/pinjam', ['buku' => $b->id, 'anggota' => 'A-0001'])->assertRedirect('/login');

        $anggota = User::factory()->create();
        $this->actingAs($anggota)->get('/buku/kelola')->assertForbidden();
        $this->actingAs($anggota)->postJson('/buku/pinjam', ['buku' => $b->id, 'anggota' => 'A-0001'])->assertForbidden();
        $this->actingAs($anggota)->get("/buku/{$b->id}")->assertOk()->assertDontSee('Ganti sampul');
        $this->assertSame(2, $b->fresh()?->tersedia);

        $this->actingAs($this->petugas())->get('/buku/kelola')->assertOk()->assertSee('Catat pinjam');
    }

    public function test_pinjam_sampai_habis_lalu_409_dan_kembali_sekali(): void
    {
        $b = $this->buku('B-0002', 2);
        $petugas = $this->petugas();

        $p1 = $this->actingAs($petugas)->postJson('/buku/pinjam', ['buku' => $b->id, 'anggota' => 'A-0001'])
            ->assertCreated()->assertJsonPath('jatuh_tempo', now()->addDays(7)->toDateString())->json('id');
        $this->actingAs($petugas)->postJson('/buku/pinjam', ['buku' => $b->id, 'anggota' => 'A-0002', 'hari' => 1])
            ->assertCreated()->assertJsonPath('jatuh_tempo', now()->addDay()->toDateString());
        $this->actingAs($petugas)->postJson('/buku/pinjam', ['buku' => $b->id, 'anggota' => 'A-0003'])
            ->assertStatus(409)->assertJson(['pesan' => 'eksemplar sedang dipinjam semua']);
        $this->assertSame(0, $b->fresh()?->tersedia);

        $this->actingAs($petugas)->postJson('/buku/kembali', ['pinjam' => $p1])->assertOk();
        $this->actingAs($petugas)->postJson('/buku/kembali', ['pinjam' => $p1])->assertNotFound();
        $this->assertSame(1, $b->fresh()?->tersedia);

        // dari formulir: redirect + pesan, bukan JSON
        $this->actingAs($petugas)->post('/buku/pinjam', ['buku' => $b->id, 'anggota' => 'A-0004'])
            ->assertRedirect('/buku/kelola')->assertSessionHas('status');
        $this->actingAs($petugas)->from('/buku/kelola')->post('/buku/pinjam', ['buku' => $b->id, 'anggota' => 'A-0005'])
            ->assertRedirect('/buku/kelola')->assertSessionHasErrors('buku');
    }

    public function test_isian_pinjam_salah_ditolak(): void
    {
        $b = $this->buku();
        $petugas = $this->petugas();

        $this->actingAs($petugas)->postJson('/buku/pinjam', ['buku' => $b->id])->assertStatus(422)
            ->assertJsonValidationErrors('anggota');
        $this->actingAs($petugas)->postJson('/buku/pinjam', ['buku' => 999, 'anggota' => 'A-1'])
            ->assertJsonValidationErrors('buku');
        $this->actingAs($petugas)->postJson('/buku/pinjam', ['buku' => $b->id, 'anggota' => ['A-1']])
            ->assertJsonValidationErrors('anggota');
        $this->actingAs($petugas)->postJson('/buku/pinjam', ['buku' => $b->id, 'anggota' => 'A-1', 'hari' => 30])
            ->assertJsonValidationErrors('hari');
        $this->assertDatabaseCount('pinjam', 0);
    }

    public function test_pinjam_memakai_update_bersyarat(): void
    {
        $b = $this->buku();
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = strtolower($q->sql);
        });
        Buku::pinjamkan($b->id, 'A-1', 7, null);

        $this->assertTrue(collect($sql)->contains(fn ($s) => str_contains($s, 'update "buku" set "tersedia" = "tersedia" - 1')
            && str_contains($s, '"tersedia" > ?')));
    }

    public function test_database_menolak_stok_minus_atau_lebih_dari_stok(): void
    {
        $b = $this->buku('B-0009', 1);
        Buku::pinjamkan($b->id, 'A-1', 7, null);
        $this->assertNull(Buku::pinjamkan($b->id, 'A-2', 7, null));

        // langsung ke database, melewati kode aplikasi: CHECK tetap menolak
        foreach ([-1, 2] as $nilai) {
            try {
                DB::transaction(fn () => DB::table('buku')->where('id', $b->id)->update(['tersedia' => $nilai]));
                $this->fail("tersedia = $nilai diterima database");
            } catch (QueryException $e) {
                $this->assertStringContainsString('buku_tersedia_cek', $e->getMessage());
            }
        }
        $this->assertSame(0, $b->fresh()?->tersedia);
    }

    public function test_tambah_buku_dengan_sampul_dan_scan_ke_object_storage(): void
    {
        Storage::fake('s3');
        $this->actingAs($this->petugas())->post('/buku', [
            'kode' => 'B-0100', 'judul' => 'Arsitektur Cloud', 'penulis' => 'Tim Redaksi', 'tahun' => 2025,
            'stok' => 2, 'sampul' => $this->png(), 'scan' => $this->pdf('../scan bab 1.pdf'),
        ])->assertRedirect('/buku/kelola')->assertSessionHas('status');

        $b = Buku::query()->where('kode', 'B-0100')->firstOrFail();
        $this->assertSame(2, $b->tersedia);
        $this->assertStringStartsWith('buku/sampul/', (string) $b->sampul_path);
        $this->assertStringStartsWith('buku/scan/', (string) $b->scan_path);
        $this->assertStringNotContainsString('bab', (string) $b->scan_path);   // nama acak buatan server
        $this->assertSame('scan bab 1.pdf', $b->scan_nama);                    // tanpa ../
        Storage::disk('s3')->assertExists([(string) $b->sampul_path, (string) $b->scan_path]);

        // sampul: halaman publik, boleh di-cache
        $this->get("/buku/{$b->id}/sampul")->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'max-age=60, public');

        // scan: hanya lewat tautan bertanda tangan yang dibuat di halaman rincian
        $this->actingAs(User::factory()->create())->get("/buku/{$b->id}")->assertOk()->assertSee('scan bab 1.pdf');
        $url = URL::temporarySignedRoute('kasus.buku.scan', now()->addMinutes(5), ['buku' => $b->id]);
        $this->get($url)->assertOk()->assertDownload('scan bab 1.pdf');
        $this->get($url.'x')->assertForbidden();
        $this->get("/buku/{$b->id}/scan")->assertForbidden();
        $this->travel(6)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_ganti_sampul_menghapus_berkas_lama(): void
    {
        Storage::fake('s3');
        $b = $this->buku();
        $petugas = $this->petugas();

        $this->actingAs($petugas)->post("/buku/{$b->id}/berkas", ['sampul' => $this->png()])
            ->assertRedirect("/buku/{$b->id}");
        $lama = (string) $b->fresh()?->sampul_path;
        $this->actingAs($petugas)->post("/buku/{$b->id}/berkas", ['sampul' => $this->png()]);
        $baru = (string) $b->fresh()?->sampul_path;

        $this->assertNotSame($lama, $baru);
        Storage::disk('s3')->assertMissing($lama);
        Storage::disk('s3')->assertExists($baru);
        $this->actingAs($petugas)->post("/buku/{$b->id}/berkas", [])->assertSessionHasErrors('sampul');
    }

    public function test_berkas_palsu_atau_terlalu_besar_ditolak(): void
    {
        Storage::fake('s3');
        $petugas = $this->petugas();
        $dasar = ['kode' => 'B-0200', 'judul' => 'X', 'penulis' => 'Y', 'stok' => 1];

        $this->actingAs($petugas)->post('/buku', $dasar + ['sampul' => $this->berkas('jahat.png', '<?php system($_GET["c"]);')])
            ->assertSessionHasErrors('sampul');
        $this->actingAs($petugas)->post('/buku', $dasar + ['scan' => $this->berkas('jahat.pdf', '<?php echo 1;')])
            ->assertSessionHasErrors('scan');
        $this->actingAs($petugas)->post('/buku', $dasar + ['scan' => $this->berkas('besar.pdf', "%PDF-1.4\n".str_repeat('0', 6 * 1024 * 1024))])
            ->assertSessionHasErrors('scan');

        $this->assertDatabaseCount('buku', 0);
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_kode_buku_ganda_ditolak(): void
    {
        $this->buku('B-0001');
        $this->actingAs($this->petugas())->post('/buku', ['kode' => 'B-0001', 'judul' => 'Lain', 'penulis' => 'Y', 'stok' => 1])
            ->assertSessionHasErrors('kode');
        $this->assertDatabaseCount('buku', 1);
    }

    public function test_api_status_cari_dan_satu_buku(): void
    {
        $a = $this->buku('B-0001', 3);
        $this->buku('B-0002', 1, 'Basis Data Terapan');
        Buku::pinjamkan($a->id, 'A-1', 1, null);
        $telat = Buku::pinjamkan($a->id, 'A-2', 7, null);
        $telat?->update(['jatuh_tempo' => now()->subDays(2)->toDateString()]);

        $this->getJson('/api/buku/status')->assertOk()->assertExactJson([
            'judul' => 2, 'eksemplar' => 4, 'tersedia' => 2,
            'dipinjam' => 2, 'terlambat' => 1, 'jatuh_tempo_besok' => 1,
        ]);
        $this->getJson('/api/buku?q=basis')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kode', 'B-0002');
        $this->getJson("/api/buku/{$a->id}")->assertOk()->assertJson(['tersedia' => 1, 'stok' => 3, 'sampul' => false]);
        $this->getJson('/api/buku/999')->assertNotFound();
        $this->assertStringNotContainsString('A-1', (string) $this->get('/api/buku/status')->getContent());
    }

    public function test_perintah_kasus_petugas(): void
    {
        $user = User::factory()->create(['email' => 'sirkulasi@contoh.test']);

        $this->artisan('kasus:petugas', ['email' => 'sirkulasi@contoh.test'])->assertSuccessful();
        $this->assertTrue(Petugas::adalah($user));
        $this->artisan('kasus:petugas', ['email' => 'sirkulasi@contoh.test', '--cabut' => true])->assertSuccessful();
        $this->assertFalse(Petugas::adalah($user));
        $this->artisan('kasus:petugas', ['email' => 'tidak@ada.test'])->assertFailed();
    }

    public function test_menu_dan_kartu_dasbor_menurut_peran(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()
            ->assertSee('Katalog perpustakaan')->assertDontSee('Sirkulasi perpustakaan');
        $this->actingAs($this->petugas())->get('/dashboard')->assertOk()->assertSee('Sirkulasi perpustakaan');
    }

    public function test_pinjaman_terlambat_ditandai(): void
    {
        $b = $this->buku();
        $p = Buku::pinjamkan($b->id, 'A-1', 1, null);
        $this->assertInstanceOf(Pinjam::class, $p);
        $this->assertFalse($p->terlambat());
        $this->travel(3)->days();
        $this->assertTrue($p->fresh()?->terlambat());
    }
}
