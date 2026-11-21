<?php
// Consumer antrean pengingat (P14 F.3). Satu pesan = satu pengingat jatuh
// tempo. Di lab "mengirim" = menulis berkas ke bucket hasil-kel0; di
// rancangan target baris putObject diganti panggilan API email/WhatsApp.
use Aws\S3\S3Client;

$s3 = new S3Client(['region' => getenv('AWS_REGION')]);   // sekali per proses
$bucket = getenv('BUCKET');

return function (array $event) use ($s3, $bucket) {
    foreach ($event['Records'] as $rec) {
        $data = json_decode($rec['body'], true);
        if (!isset($data['anggota'], $data['id'])) {   // pesan rusak -> DLQ
            throw new RuntimeException(
                "pesan rusak, tidak ada anggota/id: {$rec['body']}");
        }
        // idempotensi (P14 C.3): kunci = id dari aplikasi (no. pinjam +
        // jatuh tempo). Sudah pernah dikirim -> lewati, jangan kirim lagi.
        $kunci = "pengingat/{$data['id']}.txt";
        if ($s3->doesObjectExistV2($bucket, $kunci)) {
            echo "SUDAH $kunci, dilewati\n";
            continue;
        }
        $isi = "PENGINGAT JATUH TEMPO\nAnggota : {$data['anggota']}\n"
             . "Buku    : " . ($data['judul'] ?? '-') . "\n"
             . "Kembali : " . ($data['jatuh_tempo'] ?? '-') . "\n";
        $s3->putObject(['Bucket' => $bucket, 'Key' => $kunci, 'Body' => $isi]);
        echo "OK $kunci\n";
    }
    return ['diproses' => count($event['Records'])];
};
