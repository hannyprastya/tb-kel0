<?php
// Fungsi HTTP (P14 F.8): bukti cold start lewat function URL.
$dibuat = microtime(true);   // sekali per proses (bagian cold start)
$panggilan = 0;

return function (array $event) use ($dibuat, &$panggilan) {
    $panggilan++;
    $nama = $event['queryStringParameters']['nama'] ?? 'tamu';
    return [
        'statusCode' => 200,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode([
            'pesan' => "Halo $nama, dari fungsi kel0",
            'panggilan_ke' => $panggilan,
            'umur_instance_detik' => round(microtime(true) - $dibuat, 1),
        ]),
    ];
};
