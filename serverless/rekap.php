<?php
// Fungsi terjadwal (P14 F.7): rekap jumlah pengingat yang sudah dikirim.
// Target: tiap hari 07.00 WIB = cron(0 0 * * ? *) di EventBridge (UTC).
use Aws\S3\S3Client;

$s3 = new S3Client(['region' => getenv('AWS_REGION')]);
$bucket = getenv('BUCKET');

return function (array $event) use ($s3, $bucket) {
    $r = $s3->listObjectsV2(['Bucket' => $bucket, 'Prefix' => 'pengingat/']);
    $jumlah = $r['KeyCount'] ?? 0;
    $waktu = gmdate('Y-m-d H:i:s') . ' UTC';
    $s3->putObject(['Bucket' => $bucket, 'Key' => 'rekap/terakhir.txt',
                    'Body' => "$waktu: $jumlah pengingat\n"]);
    echo "REKAP $waktu: $jumlah pengingat terkirim\n";
    return ['jumlah' => $jumlah];
};
