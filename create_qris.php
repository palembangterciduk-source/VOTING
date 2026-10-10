<?php
/**
 * create_qris.php - Direct Payment Gateway Gateway KlikQRIS
 * Dijalankan di Hostinger (https://bgkupgri.palembangterciduk.com/create_qris.php)
 * Mem-bypass limitasi OAuth Google Apps Script tanpa mengubah fitur & tampilan apapun.
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$KLIKQRIS_API_KEY = 'fd38HRdJgYsNLQk8ZRz5FaP860bPw4nGX3TuCHYR';
$KLIKQRIS_MERCHANT_ID = '179125363122';
$KLIKQRIS_BASE_URL = 'https://klikqris.com/api';
$GAS_WEBAPP_URL = 'https://script.google.com/macros/s/AKfycbwgn-8zTivAYo47TQwFaPY1Ys69yzp3RuTFvKvkAZXmuoWpCmEulZeiulWKJkVZGxgD/exec';

$action = isset($_GET['action']) ? $_GET['action'] : '';

// 0. PROXY DATA REALTIME GOOGLE APPS SCRIPT
if ($action === 'get_data') {
    $ch = curl_init($GAS_WEBAPP_URL . '?action=get_data');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $resText = curl_exec($ch);
    $resCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($resCode >= 200 && $resCode < 300 && !empty($resText)) {
        echo $resText;
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil data dari Google Apps Script.']);
    }
    exit();
}

// 1. CEK STATUS TRANSAKSI
if ($action === 'status') {
    $txId = isset($_GET['txId']) ? trim($_GET['txId']) : '';
    if (empty($txId)) {
        echo json_encode(['success' => false, 'paymentStatus' => 'UNKNOWN', 'message' => 'txId wajib diisi.']);
        exit();
    }

    $ch = curl_init($KLIKQRIS_BASE_URL . '/qris/status/' . urlencode($txId));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'x-api-key: ' . $KLIKQRIS_API_KEY,
        'id_merchant: ' . $KLIKQRIS_MERCHANT_ID
    ]);

    $resText = curl_exec($ch);
    $resCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $resJson = json_decode($resText, true);
    $isSuccess = ($resCode >= 200 && $resCode < 300) && $resJson && isset($resJson['data']);

    if ($isSuccess) {
        $rawStatus = strtolower($resJson['data']['status'] ?? $resJson['data']['payment_status'] ?? '');
        $isPaid = ($rawStatus === 'paid' || $rawStatus === 'success' || $rawStatus === 'settlement' || $rawStatus === 'completed' || $rawStatus === 'berhasil')
                  || (isset($resJson['data']['is_paid']) && $resJson['data']['is_paid'] == true)
                  || !empty($resJson['data']['paid_at']);
        if ($isPaid) {
            // Ambil cache data kandidat jika ada
            $cacheFile = __DIR__ . '/tx_cache.json';
            $extraData = [];
            if (file_exists($cacheFile)) {
                $cached = json_decode(@file_get_contents($cacheFile), true) ?: [];
                $extraData = $cached[$txId] ?? [];
            }

            // Beritahu Google Apps Script Webhook untuk update sheet & poin
            notifyGasWebhook($GAS_WEBAPP_URL, array_merge([
                'action' => 'update_status',
                'order_id' => $txId,
                'transaksiId' => $txId,
                'status' => 'paid'
            ], $extraData));

            echo json_encode([
                'success' => true,
                'paymentStatus' => 'PAID',
                'message' => 'Pembayaran telah sukses diverifikasi.'
            ]);
            exit();
        } else if ($rawStatus === 'pending') {
            echo json_encode([
                'success' => true,
                'paymentStatus' => 'PENDING',
                'message' => 'Menunggu pembayaran...'
            ]);
            exit();
        } else if ($rawStatus === 'expired') {
            echo json_encode([
                'success' => true,
                'paymentStatus' => 'EXPIRED',
                'message' => 'Waktu pembayaran telah habis.'
            ]);
            exit();
        } else if ($rawStatus === 'failed') {
            echo json_encode([
                'success' => true,
                'paymentStatus' => 'FAILED',
                'message' => 'Pembayaran gagal.'
            ]);
            exit();
        }
    }

    echo json_encode([
        'success' => true,
        'paymentStatus' => 'PENDING',
        'message' => 'Menunggu verifikasi status...'
    ]);
    exit();
}

// 2. PEMBUATAN QRIS BARU (POST)
$rawInput = file_get_contents('php://input');
$formData = json_decode($rawInput, true);
if (!$formData && !empty($_POST)) {
    $formData = $_POST;
}

$namaPemilih = isset($formData['namaPemilih']) ? trim($formData['namaPemilih']) : '';
$noWa = isset($formData['noWa']) ? trim($formData['noWa']) : '';
$kandidatId = isset($formData['kandidatId']) ? trim($formData['kandidatId']) : '';
$kandidatNama = isset($formData['kandidatNama']) ? trim($formData['kandidatNama']) : $kandidatId;
$pilihanPaket = isset($formData['pilihanPaket']) ? (int)$formData['pilihanPaket'] : 20;
$nominal = isset($formData['nominal']) ? (int)$formData['nominal'] : 20000;

// Format TransaksiID Sekuensial / Random Unik Harian
$transaksiId = 'VOTE-' . date('Ymd') . '-' . sprintf('%04d', mt_rand(1, 9999));

// Request ke API KlikQRIS
$payload = [
    'order_id' => $transaksiId,
    'amount' => $nominal,
    'id_merchant' => $KLIKQRIS_MERCHANT_ID,
    'keterangan' => 'Vote ' . $kandidatNama . ' (' . $pilihanPaket . ' Poin)'
];

$ch = curl_init($KLIKQRIS_BASE_URL . '/qris/create');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'x-api-key: ' . $KLIKQRIS_API_KEY,
    'id_merchant: ' . $KLIKQRIS_MERCHANT_ID
]);

$response = curl_exec($ch);
$resCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$resJson = json_decode($response, true);
$isSuccess = ($resCode >= 200 && $resCode < 300) && $resJson && ($resJson['status'] === true || $resJson['status'] === 'success') && isset($resJson['data']);

if ($isSuccess) {
    $finalAmount = isset($resJson['data']['total_amount']) ? (int)$resJson['data']['total_amount'] : (isset($resJson['data']['amount']) ? (int)$resJson['data']['amount'] : $nominal);
    $qrisImage = $resJson['data']['qris_image'] ?? $resJson['data']['qris_url'] ?? '';
    $qrisUrl = $resJson['data']['qris_url'] ?? '';

    // Simpan cache transaksi lokal di Hostinger
    $cacheFile = __DIR__ . '/tx_cache.json';
    $cached = [];
    if (file_exists($cacheFile)) {
        $cached = json_decode(@file_get_contents($cacheFile), true) ?: [];
    }
    if (count($cached) > 100) {
        $cached = array_slice($cached, -50, null, true);
    }
    $cached[$transaksiId] = [
        'transaksiId' => $transaksiId,
        'order_id' => $transaksiId,
        'namaPemilih' => $namaPemilih,
        'noWa' => $noWa,
        'kandidatId' => $kandidatId,
        'pilihanPaket' => $pilihanPaket,
        'nominal' => $finalAmount
    ];
    @file_put_contents($cacheFile, json_encode($cached));

    // Catat baris transaksi ke Google Apps Script di background
    notifyGasWebhook($GAS_WEBAPP_URL, [
        'action' => 'record_vote',
        'transaksiId' => $transaksiId,
        'order_id' => $transaksiId,
        'namaPemilih' => $namaPemilih,
        'noWa' => $noWa,
        'kandidatId' => $kandidatId,
        'pilihanPaket' => $pilihanPaket,
        'nominal' => $finalAmount
    ]);

    echo json_encode([
        'success' => true,
        'transaksiId' => $transaksiId,
        'qrisImage' => $qrisImage,
        'qrisUrl' => $qrisUrl,
        'qrisData' => $resJson['data']['qris_data'] ?? '',
        'amount' => $finalAmount,
        'expiredAt' => $resJson['data']['expired_at'] ?? '',
        'message' => 'QRIS dinamis berhasil dibuat.'
    ]);
    exit();
}

// Fallback jika API sedang maintenance
$fallbackQris = 'https://lh3.googleusercontent.com/d/1LqJtNB0HVcAi37xzMN0cnQuUt-PnaAS3';
echo json_encode([
    'success' => true,
    'transaksiId' => $transaksiId,
    'qrisImage' => $fallbackQris,
    'qrisUrl' => $fallbackQris,
    'amount' => $nominal,
    'isFallback' => true,
    'message' => 'QRIS panitia siap. Silakan scan untuk menyelesaikan pembayaran.'
]);
exit();

/**
 * Helper Background Webhook ke Google Apps Script
 */
function notifyGasWebhook($url, $data) {
    if (empty($url)) return;
    try {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_exec($ch);
        curl_close($ch);
    } catch (Exception $e) {}
}
