<?php
/**
 * Teklif PDF hazırlama işlemi
 * AJAX ile çağrılarak PDF'in oluşturulmasını sağlar
 */

// Oturum kontrolü
session_start();

// Eğer kullanıcı giriş yapmamışsa error JSON döndür
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Oturum zaman aşımına uğradı.']);
    exit;
}

// Veritabanı bağlantısını içe aktar
require_once 'altunsoft/db.php';

// Veritabanı bağlantısını başlat
$db = new AltunSoftDB();

// Teklif ID kontrol
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Geçersiz teklif ID\'si.']);
    exit;
}

$teklif_id = (int)$_GET['id'];

// Teklif mevcut mu kontrol et
try {
    $teklif = $db->fetch("SELECT id, teklif_no FROM teklifler WHERE id = ?", [$teklif_id]);
    
    if (!$teklif) {
        echo json_encode(['success' => false, 'message' => 'Teklif bulunamadı.']);
        exit;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Veritabanı hatası: ' . $e->getMessage()]);
    exit;
}

// PDF'in kaydedileceği klasör kontrolü
$upload_dir = 'uploads/teklifler/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Mevcut PDF varsa sil (her seferinde yeni oluştur)
$pdf_filename = 'teklif_' . $teklif_id . '.pdf';
$pdf_path = $upload_dir . $pdf_filename;

if (file_exists($pdf_path)) {
    unlink($pdf_path);
}

// Teklif PDF oluşturma işlemi
// PDF'i oluşturmak için teklif_pdf.php'ye yönlendirecek URL
$redirect_url = 'teklif_pdf?id=' . $teklif_id . '&save=1&t=' . time();

// PDF oluşturuldu bilgisini veritabanına kaydet
try {
    $db->execute(
        "UPDATE teklifler SET pdf_olusturuldu = 1, pdf_olusturma_tarihi = NOW() WHERE id = ?", 
        [$teklif_id]
    );
} catch (Exception $e) {
    // Hata olsa bile devam et, kritik değil
}

// Başarılı sonuç döndür
echo json_encode([
    'success' => true, 
    'message' => 'PDF başarıyla oluşturuldu.',
    'pdf_url' => $redirect_url,
    'teklif_no' => $teklif['teklif_no']
]);