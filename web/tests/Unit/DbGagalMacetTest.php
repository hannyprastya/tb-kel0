<?php

namespace Tests\Unit;

use App\Support\DbGagal;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Kelompok 0, P15: database macet (timeout 3 s) harus dikenali sebagai DB
 * gagal walau pdo_pgsql memberi kode driver 7, bukan SQLSTATE.
 * Ditemukan di latihan gangguan: db pause -> /buku 500 dalam 3,03 s.
 */
class DbGagalMacetTest extends TestCase
{
    public function test_timeout_dengan_kode_driver_dikenali(): void
    {
        $pdo = new class('SQLSTATE[08006] [7] connection to server at "db" (10.0.0.2), port 5432 failed: timeout expired') extends PDOException
        {
            public function __construct(string $pesan)
            {
                parent::__construct($pesan);
                $this->code = 7;
            }
        };
        $e = new QueryException('pgsql', 'select 1', [], $pdo);

        $this->assertSame(7, $e->getCode());
        $this->assertTrue(DbGagal::cek($e));
    }

    public function test_sqlstate_lain_di_pesan_tetap_bukan_db_gagal(): void
    {
        $pdo = new class('SQLSTATE[42703]: Undefined column: kolom "x" tidak ada') extends PDOException
        {
            public function __construct(string $pesan)
            {
                parent::__construct($pesan);
                $this->code = '42703';
            }
        };

        $this->assertFalse(DbGagal::cek(new QueryException('pgsql', 'select x', [], $pdo)));
    }
}
