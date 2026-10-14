<?php
/**
 * create_qris.php - Sistem Pembayaran Scan QRIS Biasa (Manual Verification)
 * Dijalankan di Hostinger (https://bgkupgri.palembangterciduk.com/create_qris.php)
 * Terhubung langsung ke Google Apps Script tanpa ketergantungan Payment Gateway KlikQRIS.
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

$GAS_WEBAPP_URL = 'https://script.google.com/macros/s/AKfycbwgn-8zTivAYo47TQwFaPY1Ys69yzp3RuTFvKvkAZXmuoWpCmEulZeiulWKJkVZGxgD/exec';
$PANITIA_QRIS_URL = 'https://lh3.googleusercontent.com/d/1LqJtNB0HVcAi37xzMN0cnQuUt-PnaAS3';
$PANITIA_QRIS_RAW = '00020101021126570011ID.DANA.WWW011893600915301065867502090106586750303UMI51440014ID.CO.QRIS.WWW0215ID10254542664420303UMI5204541153033605802ID5914Jeslin bucket 600436116105306546304F666';

/**
 * Hitung Checksum CRC16-CCITT (False) standar EMVCo / ASPI QRIS
 */
function qris_crc16($str) {
    $crc = 0xFFFF;
    $len = strlen($str);
    for ($c = 0; $c < $len; $c++) {
        $crc ^= (ord($str[$c]) << 8);
        for ($i = 0; $i < 8; $i++) {
            if ($crc & 0x8000) {
                $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
            } else {
                $crc = ($crc << 1) & 0xFFFF;
            }
        }
    }
    return sprintf('%04X', $crc);
}

/**
 * Konversi String QRIS Statis Panitia menjadi QRIS Dinamis On-the-Fly
 */
function convert_qris_to_dynamic($staticQris, $amount) {
    if (empty($staticQris)) return '';
    $step1 = substr($staticQris, 0, -4);
    if (substr($step1, -4) === "6304") {
        $step1 = substr($step1, 0, -4);
    }
    // Ubah mode Tag 01 dari 11 (Statis) ke 12 (Dinamis)
    $step2 = str_replace("010211", "010212", $step1);
    
    // Tag 54: Transaction Amount
    $amountStr = (string)(int)$amount;
    $amountLen = str_pad(strlen($amountStr), 2, "0", STR_PAD_LEFT);
    $tag54 = "54" . $amountLen . $amountStr;
    
    // Sisipkan sebelum Tag 58 (Country Code: 5802ID)
    $pos58 = strpos($step2, "5802ID");
    if ($pos58 !== false) {
        $step3 = substr($step2, 0, $pos58) . $tag54 . substr($step2, $pos58);
    } else {
        $step3 = $step2 . $tag54;
    }
    
    // Hitung ulang CRC16
    $payloadForCrc = $step3 . "6304";
    $crc = qris_crc16($payloadForCrc);
    return $payloadForCrc . $crc;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

// 0. ENDPOINT GAMBAR QR DINAMIS LANGSUNG
if ($action === 'qr_image') {
    $amount = isset($_GET['amount']) ? (int)$_GET['amount'] : (isset($_GET['nominal']) ? (int)$_GET['nominal'] : (isset($_GET['poin']) ? (int)$_GET['poin'] * 1000 : 10000));
    $dynPayload = convert_qris_to_dynamic($PANITIA_QRIS_RAW, $amount);
    $targetUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=350x350&margin=8&data=' . rawurlencode($dynPayload) . '&_t=' . time();
    header("Location: " . $targetUrl);
    exit();
}

// 1. PROXY DATA REALTIME DARI GOOGLE APPS SCRIPT
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

// 2. PROXY APPROVAL ADMIN KE GOOGLE APPS SCRIPT
if ($action === 'approve_vote') {
    $txId = isset($_GET['txId']) ? trim($_GET['txId']) : '';
    if (empty($txId)) {
        echo json_encode(['success' => false, 'message' => 'txId wajib diisi.']);
        exit();
    }
    $ch = curl_init($GAS_WEBAPP_URL . '?action=approve_vote&txId=' . urlencode($txId));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $resText = curl_exec($ch);
    curl_close($ch);
    echo !empty($resText) ? $resText : json_encode(['success' => true, 'message' => 'Transaksi berhasil disetujui.']);
    exit();
}

// 3. PROXY REJECT ADMIN KE GOOGLE APPS SCRIPT
if ($action === 'reject_vote') {
    $txId = isset($_GET['txId']) ? trim($_GET['txId']) : '';
    if (empty($txId)) {
        echo json_encode(['success' => false, 'message' => 'txId wajib diisi.']);
        exit();
    }
    $ch = curl_init($GAS_WEBAPP_URL . '?action=reject_vote&txId=' . urlencode($txId));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $resText = curl_exec($ch);
    curl_close($ch);
    echo !empty($resText) ? $resText : json_encode(['success' => true, 'message' => 'Transaksi berhasil ditolak.']);
    exit();
}

// 4. PENDAFTARAN TRANSAKSI VOTE BARU (POST) - STATUS AWAL PENDING
$rawInput = file_get_contents('php://input');
$formData = json_decode($rawInput, true);
if (!$formData && !empty($_POST)) {
    $formData = $_POST;
}

$namaPemilih = isset($formData['namaPemilih']) ? trim($formData['namaPemilih']) : '';
$noWa = isset($formData['noWa']) ? trim($formData['noWa']) : '';
$kandidatId = isset($formData['kandidatId']) ? trim($formData['kandidatId']) : '';
$kandidatNama = isset($formData['kandidatNama']) ? trim($formData['kandidatNama']) : $kandidatId;
$inputNominal = isset($formData['nominal']) ? (int)$formData['nominal'] : 0;
$pilihanPaket = isset($formData['pilihanPaket']) ? (int)$formData['pilihanPaket'] : 20;

// Tabel Pemetaan Resmi Paket Poin ke Harga Riil (Murni Flat tanpa kode unik / tambahan sepeser pun)
$PAKET_HARGA_MAP = [
    10   => 10000,
    20   => 20000,
    50   => 50000,
    105  => 100000,
    550  => 500000,
    1100 => 1000000
];

if ($inputNominal > 0) {
    $nominal = $inputNominal;
} else if (isset($PAKET_HARGA_MAP[$pilihanPaket])) {
    $nominal = $PAKET_HARGA_MAP[$pilihanPaket];
} else {
    $nominal = $pilihanPaket * 1000;
}

if (empty($namaPemilih) || empty($noWa) || empty($kandidatId)) {
    echo json_encode(['success' => false, 'message' => 'Nama pemilih, nomor WhatsApp, dan pilihan kandidat wajib diisi.']);
    exit();
}

// Generate ID Transaksi Sekuensial Berbasis Tanggal (VOTE-YYYYMMDD-XXXX)
$todayStr = date('Ymd');
$counterFile = __DIR__ . '/counter_' . $todayStr . '.txt';
$seq = 1;
if (file_exists($counterFile)) {
    $seq = (int)@file_get_contents($counterFile) + 1;
}
@file_put_contents($counterFile, (string)$seq);
$transaksiId = 'VOTE-' . $todayStr . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

// Catat baris transaksi ke Google Apps Script (Status PENDING murni, poin tidak bertambah)
notifyGasWebhook($GAS_WEBAPP_URL, [
    'action' => 'record_vote',
    'transaksiId' => $transaksiId,
    'order_id' => $transaksiId,
    'namaPemilih' => $namaPemilih,
    'noWa' => $noWa,
    'kandidatId' => $kandidatId,
    'pilihanPaket' => $pilihanPaket,
    'nominal' => $nominal
]);

// Generate QRIS Dinamis On-the-Fly dengan nominal pas sesuai paket dukungan
$dynamicQrisPayload = convert_qris_to_dynamic($PANITIA_QRIS_RAW, $nominal);
$dynamicQrisImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=350x350&margin=8&data=' . rawurlencode($dynamicQrisPayload) . '&_t=' . time();

// Berikan respon QRIS Dinamis Panitia langsung ke frontend
echo json_encode([
    'success' => true,
    'transaksiId' => $transaksiId,
    'qrisImage' => $dynamicQrisImageUrl,
    'qrisUrl' => $dynamicQrisImageUrl,
    'qrisData' => $dynamicQrisPayload,
    'amount' => $nominal,
    'message' => 'Transaksi vote berhasil dicatat (PENDING). Silakan transfer dan kirim bukti pembayaran ke WhatsApp Admin.'
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
