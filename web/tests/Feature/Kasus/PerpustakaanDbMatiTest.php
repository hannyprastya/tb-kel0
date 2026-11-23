<?php

namespace Tests\Feature\Kasus;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** P15: database mati -> halaman kasus 503 ramah, API kasus 503 JSON, /healthz tetap 200. */
class PerpustakaanDbMatiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.pgsql.url' => 'postgresql://app:x@127.0.0.1:1/tidakada']);
        DB::purge('pgsql');
    }

    public function test_katalog_publik_503_ramah(): void
    {
        $this->get('/buku')->assertStatus(503)->assertHeader('Retry-After', '60')->assertSee('Maaf');
        $this->get('/buku/1/sampul')->assertStatus(503)->assertSee('Maaf');
    }

    public function test_api_kasus_503_json(): void
    {
        $this->getJson('/api/buku/status')->assertStatus(503)
            ->assertExactJson(['status' => 'error', 'db' => 'gagal']);
        $this->getJson('/api/buku/1')->assertStatus(503);
        $this->getJson('/api/buku/pinjam/1')->assertStatus(503);
    }

    public function test_pinjam_saat_db_mati_503_bukan_500(): void
    {
        $this->actingAs(new User(['id' => 1, 'name' => 'Petugas', 'email' => 'p@contoh.test']))
            ->postJson('/buku/pinjam', ['buku' => 1, 'anggota' => 'A-1'])->assertStatus(503);
        $this->get('/healthz')->assertOk();
    }
}
