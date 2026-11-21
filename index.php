<?php
// Buku tamu (aplikasi acuan P5 sampai Tugas Besar): PHP + PDO + PostgreSQL.
// Konfigurasi dari environment variable DATABASE_URL (P5 slide 24).
const PEMILIK = "Kelompok 0 - Perpustakaan Kampus";
const VERSI = "1.0";               // dinaikkan saat rolling update (P8)

// DATABASE_URL = postgresql://user:pass@host:5432/db?sslmode=require
function db(int $timeout = 3): PDO {     // P15: 5 -> 3 detik (slide 14)
    $u = parse_url(getenv("DATABASE_URL"));
    parse_str($u["query"] ?? "", $q);
    $dsn = sprintf("pgsql:host=%s;port=%d;dbname=%s",
        $u["host"], $u["port"] ?? 5432, ltrim($u["path"], "/"));
    if (!empty($q["sslmode"])) $dsn .= ";sslmode=" . $q["sslmode"];   // TLS
    $pdo = new PDO($dsn, urldecode($u["user"]), urldecode($u["pass"] ?? ""), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,   // error jadi exception
        PDO::ATTR_TIMEOUT => $timeout,                 // batas waktu koneksi
    ]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS tamu (id serial PRIMARY KEY,
                nama text NOT NULL, waktu timestamptz DEFAULT now())");
    return $pdo;
}

// P15 (slide 14): database gagal -> halaman tetap tampil, status tetap 503
function maaf(PDOException $e): void {
    error_log("DB gagal: " . $e->getMessage());   // tetap tercatat di log
    http_response_code(503);           // 5xx: alert P11 tetap menyala
    header("Retry-After: 60");         // "coba lagi" dalam 60 detik
    echo "<h1>Buku tamu " . htmlspecialchars(PEMILIK) . "</h1>";
    echo "<p>Maaf, buku tamu sedang gangguan. Coba lagi 1 menit lagi.</p>";
}

function json(array $data, int $code = 200): void {
    http_response_code($code);
    header("Content-Type: application/json");
    echo json_encode($data);
}

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$method = $_SERVER["REQUEST_METHOD"];

if ($path === "/healthz") {            // dangkal: proses hidup
    json(["status" => "ok"]);
} elseif ($path === "/health") {       // dalam: cek database, maks. 3 detik
    try {
        db(3)->query("SELECT 1");
        json(["status" => "ok", "db" => "ok"]);
    } catch (Throwable $e) {
        json(["status" => "error", "db" => "gagal"], 503);
    }
} elseif ($path === "/tamu" && $method === "POST") {
    $nama = $_POST["nama"] ?? "";
    $nama = is_string($nama) ? trim($nama) : "";   // F-04: array ditolak
    if ($nama === "") { http_response_code(400); echo "nama wajib diisi"; exit; }
    try {
        $st = db()->prepare("INSERT INTO tamu (nama) VALUES (?)");   // ? = param
        $st->execute([mb_substr($nama, 0, 80)]);
    } catch (PDOException $e) { maaf($e); exit; }
    header("Location: /", true, 302);
} elseif ($path === "/") {
    $sql = "SELECT nama, waktu FROM tamu ORDER BY id DESC LIMIT 20";
    try {
        $rows = db()->query($sql)->fetchAll();
    } catch (PDOException $e) { maaf($e); exit; }
    echo "<h1>Buku tamu " . htmlspecialchars(PEMILIK) . "</h1>";
    echo "<p>versi " . VERSI . " · server " . gethostname() . "</p>";
    echo '<form method="post" action="/tamu"><input name="nama" required>';
    echo '<button>Simpan</button></form><ul>';
    foreach ($rows as $r) {
        $waktu = date("d-m H:i", strtotime($r["waktu"]));
        echo "<li>" . htmlspecialchars($r["nama"]) . " · $waktu</li>";
    }
    echo "</ul>";
// Kasus 0 · Perpustakaan kampus (contoh dosen, di luar Tabel K). Tempel
// tepat sebelum baris "} else {" terakhir di index.php (cabang 404).
// GET /buku (katalog, cari ?q=), POST /buku/pinjam, POST /buku/kembali,
// GET /buku/status (JSON untuk k6 dan uptime monitor). Eksemplar dikurangi
// dengan satu UPDATE bersyarat, jadi stok tidak pernah minus walau banyak
// anggota meminjam serentak. Sampul dan scan buku (object storage + CDN)
// dan pengingat jatuh tempo (antrean, P14) belum ada di sini.
} elseif (str_starts_with($path, "/buku")) {     // Kasus 0: perpustakaan
    try { $pdo = db(); } catch (PDOException $e) { maaf($e); exit; } // P15
    $pdo->exec("CREATE TABLE IF NOT EXISTS buku (id serial PRIMARY KEY,
                judul text UNIQUE NOT NULL, penulis text NOT NULL,
                stok int NOT NULL CHECK (stok >= 0),
                tersedia int NOT NULL CHECK (tersedia BETWEEN 0 AND stok))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pinjam (id serial PRIMARY KEY,
                buku_id int NOT NULL REFERENCES buku, anggota text NOT NULL,
                waktu timestamptz DEFAULT now(),
                jatuh_tempo date NOT NULL DEFAULT current_date + 7,
                kembali timestamptz)");
    $pdo->exec("INSERT INTO buku (judul, penulis, stok, tersedia)
        SELECT * FROM (VALUES ('Pengantar Komputasi Awan', 'Tim Dosen', 3, 3),
        ('Basis Data Terapan', 'Tim Penyusun', 2, 2),
        ('Jaringan Komputer Dasar', 'Tim Redaksi', 1, 1)) v
        WHERE NOT EXISTS (SELECT 1 FROM buku)        -- contoh, sekali
        ON CONFLICT (judul) DO NOTHING");
    $isi = fn(string $k): string =>             // isian form; array -> ""
        is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : "";
    if ($path === "/buku/pinjam" && $method === "POST") {
        $buku = (int) $isi("buku");
        $anggota = $isi("anggota");             // no. anggota, bukan nama
        if ($buku < 1 || $anggota === "") {
            http_response_code(400); echo "buku dan anggota wajib diisi"; exit;
        }
        $pdo->beginTransaction();
        $st = $pdo->prepare("UPDATE buku SET tersedia = tersedia - 1
              WHERE id = ? AND tersedia > 0 RETURNING id");
        $st->execute([$buku]);
        if ($st->fetchColumn() === false) {     // habis, atau buku tidak ada
            $pdo->rollBack();
            $st = $pdo->prepare("SELECT 1 FROM buku WHERE id = ?");
            $st->execute([$buku]);
            $ada = $st->fetchColumn() !== false;
            http_response_code($ada ? 409 : 404);
            echo $ada ? "eksemplar sedang dipinjam semua" : "buku tidak ada";
            exit;
        }
        $st = $pdo->prepare("INSERT INTO pinjam (buku_id, anggota)
              VALUES (?, ?) RETURNING id");
        $st->execute([$buku, mb_substr($anggota, 0, 40)]);
        $id = (int) $st->fetchColumn();
        $pdo->commit();
        header("Location: /buku?pinjam=$id", true, 302);
    } elseif ($path === "/buku/kembali" && $method === "POST") {
        $pdo->beginTransaction();               // satu pinjaman, satu kembali
        $st = $pdo->prepare("UPDATE pinjam SET kembali = now()
              WHERE id = ? AND kembali IS NULL RETURNING buku_id");
        $st->execute([(int) $isi("pinjam")]);
        $buku = $st->fetchColumn();
        if ($buku === false) {                  // tidak ada / sudah kembali
            $pdo->rollBack();
            http_response_code(404); echo "peminjaman aktif tidak ada"; exit;
        }
        $st = $pdo->prepare("UPDATE buku SET tersedia = tersedia + 1
              WHERE id = ?");
        $st->execute([$buku]);
        $pdo->commit();
        header("Location: /buku?kembali=1", true, 302);
    } elseif ($path === "/buku/status") {
        $r = $pdo->query("SELECT count(*) AS judul,
              COALESCE(sum(stok), 0) AS eksemplar,
              COALESCE(sum(tersedia), 0) AS tersedia FROM buku")->fetch();
        $telat = $pdo->query("SELECT count(*) FROM pinjam
              WHERE kembali IS NULL AND jatuh_tempo < current_date");
        $st = $pdo->query("SELECT id, stok, tersedia FROM buku
              ORDER BY id LIMIT 50");
        json(["judul" => (int) $r["judul"],
              "eksemplar" => (int) $r["eksemplar"],
              "tersedia" => (int) $r["tersedia"],
              "terlambat" => (int) $telat->fetchColumn(),
              "buku" => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } elseif ($path === "/buku") {
        $q = is_string($_GET["q"] ?? null) ? trim($_GET["q"]) : "";
        $pola = "%" . addcslashes(mb_substr($q, 0, 50), "%_\\") . "%";
        $st = $pdo->prepare("SELECT id, judul, penulis, stok, tersedia
              FROM buku WHERE judul ILIKE ? OR penulis ILIKE ?
              ORDER BY judul LIMIT 50");
        $st->execute([$pola, $pola]);
        echo "<h1>Katalog " . htmlspecialchars(PEMILIK) . "</h1>";
        if (isset($_GET["pinjam"])) {
            echo "<p>Nomor peminjaman: <b>" . (int) $_GET["pinjam"]
                . "</b>, jatuh tempo 7 hari lagi.</p>";
        }
        echo '<form method="get" action="/buku"><input name="q" value="'
            . htmlspecialchars($q) . '" placeholder="judul / penulis"> ';
        echo "<button>Cari</button></form><ul>";
        foreach ($st->fetchAll() as $r) {
            echo "<li>" . (int) $r["id"] . " · " . htmlspecialchars($r["judul"])
                . " · " . htmlspecialchars($r["penulis"]) . " · tersedia "
                . (int) $r["tersedia"] . "/" . (int) $r["stok"] . "</li>";
        }
        echo '</ul><form method="post" action="/buku/pinjam">';
        echo '<input name="buku" placeholder="no. buku" required> ';
        echo '<input name="anggota" placeholder="no. anggota" required> ';
        echo "<button>Pinjam</button></form>";
        echo '<form method="post" action="/buku/kembali">';
        echo '<input name="pinjam" placeholder="no. peminjaman" required> ';
        echo "<button>Kembalikan</button></form>";
    } else {
        http_response_code(404);
        echo "Halaman tidak ditemukan";
    }
} elseif ($path === "/lambat") {       // P11: rute latihan, query 0,3 detik
    $pdo = db();
    $pdo->query("SELECT pg_sleep(0.3)");
    $n = $pdo->query("SELECT count(*) FROM tamu")->fetchColumn();
    error_log("laporan lambat dibuat, $n tamu");   // log ikut trace_id
    json(["tamu" => (int) $n]);
} elseif ($path === "/pengingat/kirim" && $method === "POST") {
    // P14 F: titip pengingat jatuh tempo ke antrean, langsung jawab 202
    require __DIR__ . "/vendor/autoload.php";
    try {
        $st = db()->query("SELECT p.id, p.anggota, b.judul, p.jatuh_tempo
              FROM pinjam p JOIN buku b ON b.id = p.buku_id
              WHERE p.kembali IS NULL AND p.jatuh_tempo <= current_date + 1");
        $daftar = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { maaf($e); exit; }
    $opsi = ["region" => getenv("AWS_DEFAULT_REGION") ?: "us-east-1"];
    if (getenv("AWS_ENDPOINT_URL")) {          // Floci; di AWS tidak diisi
        $opsi["endpoint"] = getenv("AWS_ENDPOINT_URL");
    }
    try {
        $sqs = new Aws\Sqs\SqsClient($opsi);
        $antrean = getenv("NAMA_ANTREAN") ?: "antrean-kel0";
        $url = $sqs->getQueueUrl(["QueueName" => $antrean])["QueueUrl"];
        foreach ($daftar as $p) {
            // kunci idempotensi = no. pinjam + jatuh tempo (bukan acak):
            // dikirim dua kali tetap satu pengingat (P14 C.3)
            $p["id"] = "pinjam-" . $p["id"] . "-" . $p["jatuh_tempo"];
            $sqs->sendMessage(["QueueUrl" => $url,
                               "MessageBody" => json_encode($p)]);
        }
    } catch (Aws\Exception\AwsException $e) {
        error_log("antrean gagal: " . ($e->getAwsErrorCode() ?? "koneksi"));
        json(["status" => "antrean tidak tersedia"], 503);
        exit;
    }
    json(["status" => "diterima", "jumlah" => count($daftar)], 202);
} else {
    http_response_code(404);
    echo "Halaman tidak ditemukan";
}
