<?php

/**
 * Teklif PDF Oluşturma
 * Browsershot (Spatie) ile teklif PDF'i oluşturma
 * HTML/CSS Tabanlı Modern Tasarım
 */

// Oturum kontrolü
session_start();

// Eğer kullanıcı giriş yapmamışsa login sayfasına yönlendir
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    header('Location: login');
    exit;
}

// Veritabanı bağlantısını içe aktar
require_once 'dbklasorunuz/dbaglantiniz.php';

// Browsershot helper sınıfını içe aktar BrowsershotHelper.php dosyasını kendime göre ben yazdım. PDF ayarlarını buradan değiştirerseniz teklif_pdf.php dosyasında da hepler dosyasına göre düzenlemeniz gerekir. 
require_once 'pdf/BrowsershotHelper.php';

// Gerekli Browsershot sınıflarını kullan
use AltunSoft\PDF\BrowsershotHelper;
use Spatie\Browsershot\Browsershot;

// Veritabanı bağlantısını başlat
$db = new AltunSoftDB();

// Teklif ID kontrolü
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Geçersiz teklif ID'si.");
}

$teklif_id = (int)$_GET['id'];

// Teklif bilgilerini getir
try {
    // Önce sadece teklif bilgilerini getir
    $teklif_sql = "SELECT t.*, 
                   c.cari_tipi, c.cari_adi, c.cari_soyadi, c.cari_kisa_adi, c.vergi_dairesi, c.vergi_no, c.telefon1, c.eposta,
                   dk.kur_kodu, dk.kur_adi,
                   u.username
                   FROM teklifler t
                   LEFT JOIN cariler c ON t.cari_id = c.id
                   LEFT JOIN doviz_kurlari dk ON t.doviz_id = dk.id
                   LEFT JOIN users u ON t.olusturan_id = u.id
                   WHERE t.id = ?";

    $teklif = $db->fetch($teklif_sql, [$teklif_id]);

    if (!$teklif) {
        die("Teklif bulunamadı.");
    }

    // Adres bilgilerini ayrı sorguda getir
    $adres_bilgileri = [];
    if (!empty($teklif['cari_adres_id'])) {
        try {
            $adres_sql = "SELECT ca.*, i.il_adi, ilc.ilce_adi
                          FROM cari_adresleri ca
                          LEFT JOIN iller i ON ca.il_id = i.id
                          LEFT JOIN ilceler ilc ON ca.ilce_id = ilc.id
                          WHERE ca.id = ?";
            $adres_bilgileri = $db->fetch($adres_sql, [$teklif['cari_adres_id']]);
        } catch (Exception $e) {
            // Adres bilgisi alınamazsa devam et, kritik değil
            $adres_bilgileri = [];
        }
    }

    // Teklif satırlarını getir
    $satir_sql = "SELECT ts.*, u.urun_adi as urun_adi_orijinal, b.birim_adi, b.kisa_kod as birim_kodu, 
                  dk.kur_kodu, k.oran as kdv_orani
                  FROM teklif_satirlari ts
                  LEFT JOIN urunler u ON ts.urun_id = u.id
                  LEFT JOIN birimler b ON ts.birim_id = b.id
                  LEFT JOIN doviz_kurlari dk ON ts.doviz_id = dk.id
                  LEFT JOIN kdv_oranlari k ON ts.kdv_oran_id = k.id
                  WHERE ts.teklif_id = ?
                  ORDER BY ts.sira_no ASC";

    $teklif_satirlari = $db->query($satir_sql, [$teklif_id]);

    // Teklif ayarlarını getir
    $teklif_ayar_sql = "SELECT * FROM teklif_ayarlari ORDER BY id ASC LIMIT 1";
    $teklif_ayar = $db->fetch($teklif_ayar_sql);

    // PDF rengi (varsayılan olarak mavi ton)
    $pdf_renk = $teklif_ayar['pdf_renk'] ?? '#1a4b8c';

    /**
     * Hexadecimal renk kodunu belirli bir yüzde oranında açar
     * @param string $hex Hex renk kodu (#RRGGBB)
     * @param int $percent Açma yüzdesi (0-100)
     * @return string Açılmış rengin hex kodu
     */
    function lightenColor($hex, $percent)
    {
        // # işaretini kaldır
        $hex = ltrim($hex, '#');

        // Hex değerini RGB'ye dönüştür
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        // Rengi açık hale getir
        $r = min(255, $r + $r * $percent / 100);
        $g = min(255, $g + $g * $percent / 100);
        $b = min(255, $b + $b * $percent / 100);

        // RGB'yi Hex'e geri dönüştür
        return sprintf("#%02x%02x%02x", $r, $g, $b);
    }

    /**
     * Arka plan rengine göre metin rengini belirler
     * @param string $hex Hex renk kodu
     * @return string Metin rengi (#FFFFFF veya #000000)
     */
    function getTextColor($hex)
    {
        // # işaretini kaldır
        $hex = ltrim($hex, '#');

        // Hex değerini RGB'ye dönüştür
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        // Rengin parlaklığını hesapla (YIQ hesaplaması)
        $yiq = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;

        // Parlaklık 128'den büyükse koyu metin, değilse açık metin kullan
        return ($yiq >= 128) ? '#000000' : '#FFFFFF';
    }

    // Renk için metin rengini belirle
    $pdf_text_color = getTextColor($pdf_renk);
    $pdf_light_color = lightenColor($pdf_renk, 30); // Daha açık ton oluştur
    // Hizmetleri getir
    $hizmetler_sql = "SELECT * FROM teklif_hizmetleri ORDER BY id ASC";
    $hizmetler = $db->query($hizmetler_sql);

    // Çözümleri getir
    $cozumler_sql = "SELECT * FROM teklif_cozumleri ORDER BY id ASC";
    $cozumler = $db->query($cozumler_sql);

    // İş ortaklarını getir
    $is_ortaklari_sql = "SELECT * FROM teklif_is_ortaklari ORDER BY id ASC";
    $is_ortaklari = $db->query($is_ortaklari_sql);

    // Banka hesaplarını getir (aktif olanlar)
    $banka_hesaplari_sql = "SELECT * FROM banka_hesaplari WHERE aktif = 1 ORDER BY id ASC";
    $banka_hesaplari = $db->query($banka_hesaplari_sql);

    // Şirket bilgilerini getir
    $company_sql = "SELECT * FROM company_settings WHERE id = 1";
    $company_settings = $db->fetch($company_sql);
} catch (Exception $e) {
    die("Veri çekme hatası: " . $e->getMessage());
}

// Teklif bulunamadıysa hata göster
if ($teklif === null) {
    die("Teklif bulunamadı.");
}

// Toplam değerleri hesapla
$ara_toplam = 0;
$toplam_kdv = 0;
$toplam_iskonto = 0;

if (!empty($teklif_satirlari)) {
    foreach ($teklif_satirlari as $satir) {
        $ara_toplam += $satir['satir_tutari'];
        $toplam_kdv += $satir['kdv_tutari'];
        $toplam_iskonto += $satir['iskonto_tutar'];
    }
}

// Genel iskonto ekle
$toplam_iskonto += isset($teklif['iskonto_genel_tutar']) ? $teklif['iskonto_genel_tutar'] : 0;
$genel_toplam = $ara_toplam - $toplam_iskonto + $toplam_kdv;

// Müşteri adını formatlı göster
$musteri_adi = '';
if (isset($teklif['cari_tipi']) && $teklif['cari_tipi'] === 'gercek') {
    $musteri_adi = $teklif['cari_adi'] . ' ' . $teklif['cari_soyadi'];
} else {
    $musteri_adi = $teklif['cari_kisa_adi'] ?? '';
}

// Adres bilgisini formatlı göster
$adres = '';
if (!empty($adres_bilgileri) && !empty($adres_bilgileri['adres_satiri'])) {
    $adres = $adres_bilgileri['adres_satiri'];

    $adres_detay = [];
    if (!empty($adres_bilgileri['ilce_adi'])) {
        $adres_detay[] = $adres_bilgileri['ilce_adi'];
    }
    if (!empty($adres_bilgileri['il_adi'])) {
        $adres_detay[] = $adres_bilgileri['il_adi'];
    }
    if (!empty($adres_bilgileri['posta_kodu'])) {
        $adres_detay[] = $adres_bilgileri['posta_kodu'];
    }
    if (!empty($adres_bilgileri['ulke']) && $adres_bilgileri['ulke'] !== 'Türkiye') {
        $adres_detay[] = $adres_bilgileri['ulke'];
    }

    if (!empty($adres_detay)) {
        $adres .= "\n" . implode(', ', $adres_detay);
    }
}

// Şirket bilgilerini formatlı göster
$sirket_adi = $company_settings['company_name'] ?? 'Şirket Adı';
$sirket_adresi = $company_settings['company_address'] ?? '';
$sirket_telefon = $company_settings['company_phone'] ?? '';
$sirket_telefon2 = $company_settings['company_phone2'] ?? '';
$sirket_eposta = $company_settings['company_email'] ?? '';
$sirket_web = $company_settings['company_website'] ?? '';

// Şirket bilgileri için formatlanmış metin
$sirket_iletisim = [];
if (!empty($sirket_adresi)) {
    $sirket_iletisim[] = $sirket_adresi;
}
if (!empty($sirket_telefon)) {
    $sirket_iletisim[] = "Tel: " . $sirket_telefon;
}
if (!empty($sirket_eposta)) {
    $sirket_iletisim[] = "E-posta: " . $sirket_eposta;
}
if (!empty($sirket_web)) {
    $sirket_iletisim[] = "Web: " . $sirket_web;
}

$sirket_iletisim_metni = implode(' | ', $sirket_iletisim);

// Teklif tarihi ve geçerlilik tarihi formatla
$teklif_tarihi = isset($teklif['teklif_tarihi']) ? date('d.m.Y', strtotime($teklif['teklif_tarihi'])) : '-';
$gecerlilik_tarihi = isset($teklif['gecerlilik_tarihi']) && $teklif['gecerlilik_tarihi'] ? date('d.m.Y', strtotime($teklif['gecerlilik_tarihi'])) : '-';

// Teklif satırlarını HTML tablosuna dönüştür
$teklif_satirlari_html = '';
$sira = 1;

if (!empty($teklif_satirlari)) {
    foreach ($teklif_satirlari as $satir) {
        $birim = htmlspecialchars($satir['birim_kodu'] ? $satir['birim_kodu'] : ($satir['birim_adi'] ? $satir['birim_adi'] : '-'));
        $birim_fiyat = number_format($satir['birim_fiyat'], 2, ',', '.') . ' ' . htmlspecialchars($satir['kur_kodu'] ?? '');
        $kdv_oran = '%' . number_format($satir['kdv_orani'] ?? 0, 0);

        // İskonto metnini oluştur
        $iskonto_text = '-';
        if ((isset($satir['iskonto_yuzde']) && $satir['iskonto_yuzde'] > 0) || (isset($satir['iskonto_tutar']) && $satir['iskonto_tutar'] > 0)) {
            $iskonto_text = number_format($satir['iskonto_tutar'], 2, ',', '.') . ' ' . htmlspecialchars($teklif['kur_kodu'] ?? '');
        }

        $toplam = number_format($satir['toplam_tutar'], 2, ',', '.') . ' ' . htmlspecialchars($teklif['kur_kodu'] ?? '');
        $aciklama = !empty($satir['aciklama']) ? htmlspecialchars($satir['aciklama']) : '';

        $teklif_satirlari_html .= '
            <tr>
                <td>' . $sira . '</td>
                <td>' . htmlspecialchars($satir['urun_adi']) . '</td>
                <td>' . $aciklama . '</td>
                <td>' . number_format($satir['miktar'], 2, ',', '.') . '</td>
                <td>' . $birim . '</td>
                <td>' . $birim_fiyat . '</td>
                <td>' . $kdv_oran . '</td>
                <td>' . $toplam . '</td>
            </tr>
        ';

        $sira++;
    }
} else {
    $teklif_satirlari_html = '<tr><td colspan="8" style="text-align: center; color: #777;">Bu teklife ait kalem bulunamadı!</td></tr>';
}

// Hizmetleri ve çözümleri farklı renklerle göstermek için renk dizileri oluşturalım
$service_colors = [
    'rgba(' . hexdec(substr($pdf_renk, 1, 2)) . ', ' . hexdec(substr($pdf_renk, 3, 2)) . ', ' . hexdec(substr($pdf_renk, 5, 2)) . ', 0.1)',
    'rgba(' . hexdec(substr($pdf_renk, 1, 2)) . ', ' . hexdec(substr($pdf_renk, 3, 2)) . ', ' . hexdec(substr($pdf_renk, 5, 2)) . ', 0.15)',
    'rgba(' . hexdec(substr($pdf_renk, 1, 2)) . ', ' . hexdec(substr($pdf_renk, 3, 2)) . ', ' . hexdec(substr($pdf_renk, 5, 2)) . ', 0.2)',
    'rgba(' . hexdec(substr($pdf_renk, 1, 2)) . ', ' . hexdec(substr($pdf_renk, 3, 2)) . ', ' . hexdec(substr($pdf_renk, 5, 2)) . ', 0.25)',
    'rgba(' . hexdec(substr($pdf_renk, 1, 2)) . ', ' . hexdec(substr($pdf_renk, 3, 2)) . ', ' . hexdec(substr($pdf_renk, 5, 2)) . ', 0.3)',
    'rgba(' . hexdec(substr($pdf_renk, 1, 2)) . ', ' . hexdec(substr($pdf_renk, 3, 2)) . ', ' . hexdec(substr($pdf_renk, 5, 2)) . ', 0.35)',
];

// Hizmetlerimiz HTML içeriğini oluştur
$hizmetler_html = '';
if (!empty($hizmetler)) {
    $i = 0;
    foreach ($hizmetler as $hizmet) {
        $hizmet_adi = htmlspecialchars($hizmet['hizmet_adi'] ?? '');
        $hizmet_aciklamasi = htmlspecialchars($hizmet['hizmet_aciklamasi'] ?? '');

        // Açıklamayı kısalt - daha kısa 80 karakter
        if (strlen($hizmet_aciklamasi) > 80) {
            $hizmet_aciklamasi = substr($hizmet_aciklamasi, 0, 77) . '...';
        }

        $bg_color = $service_colors[$i % count($service_colors)];
        $i++;

        $hizmetler_html .= '
            <div class="service">
                <div class="service-title">' . $hizmet_adi . '</div>
                <p>' . $hizmet_aciklamasi . '</p>
            </div>
        ';
    }
}

// Çözümlerimiz HTML içeriğini oluştur - benzer şekilde
$cozumler_html = '';
if (!empty($cozumler)) {
    $i = 0;
    foreach ($cozumler as $cozum) {
        $cozum_adi = htmlspecialchars($cozum['cozum_adi'] ?? '');
        $cozum_aciklamasi = htmlspecialchars($cozum['cozum_aciklamasi'] ?? '');

        // Açıklamayı kısalt - daha kısa 80 karakter
        if (strlen($cozum_aciklamasi) > 80) {
            $cozum_aciklamasi = substr($cozum_aciklamasi, 0, 77) . '...';
        }

        $cozumler_html .= '
            <div class="solution">
                <div class="solution-title">' . $cozum_adi . '</div>
                <p>' . $cozum_aciklamasi . '</p>
            </div>
        ';
    }
}

// Banka hesapları HTML içeriğini oluştur
$banka_hesaplari_html = '';
if (!empty($banka_hesaplari)) {
    foreach ($banka_hesaplari as $hesap) {
        $banka_adi = htmlspecialchars($hesap['banka_adi'] ?? '');
        $sube_adi = htmlspecialchars($hesap['sube_adi'] ?? '-');
        $hesap_turu = htmlspecialchars($hesap['hesap_turu'] ?? '-');
        $iban = htmlspecialchars($hesap['iban'] ?? '-');

        $banka_hesaplari_html .= '
            <div class="partner" style="height: auto; width: 250px; padding: 20px; text-align: left;">
                <h4 style="margin-bottom: 10px; color: ' . $pdf_renk . ';">' . $banka_adi . '</h4>
                <p><strong>Şube:</strong> ' . $sube_adi . '</p>
                <p><strong>Hesap Türü:</strong> ' . $hesap_turu . '</p>
                <p><strong>IBAN:</strong> ' . $iban . '</p>
            </div>
        ';
    }
}

// İş ortakları HTML içeriğini oluştur
$is_ortaklari_html = '';
if (!empty($is_ortaklari)) {
    foreach ($is_ortaklari as $ortak) {
        $ortak_adi = htmlspecialchars($ortak['ortak_adi'] ?? '');
        $logo_path = $ortak['ortak_logo'] ?? '';

        // Logo kontrolü
        $logo_html = '';
        if (file_exists($logo_path)) {
            $logo_html = '<img src="' . $logo_path . '" alt="' . $ortak_adi . '" style="max-width: 120px; max-height: 60px;">';
        } else {
            $logo_html = $ortak_adi;
        }

        $is_ortaklari_html .= '
            <div class="partner">
                ' . $logo_html . '
            </div>
        ';
    }
}

// Şirket logosunu teklif_ayarlari tablosundan çek
$teklif_baslik_logo = $teklif_ayar['teklif_baslik_logo'] ?? '';
$kapak_logo = $teklif_ayar['teklif_baslik_logo'] ?? ''; // Kapak logosu için de teklif_baslik_logo kullanılsın

// Logolar için tam dosya yolları kullanın
$site_root = $_SERVER['DOCUMENT_ROOT']; // Site kök dizini
if (!empty($teklif_baslik_logo) && !file_exists($teklif_baslik_logo)) {
    // Eğer yol tam değilse, site kökünü ekle
    $teklif_baslik_logo = $site_root . '/' . ltrim($teklif_baslik_logo, '/');
}

// Kapak logosu için aynı işlem
if (!empty($kapak_logo) && !file_exists($kapak_logo)) {
    $kapak_logo = $site_root . '/' . ltrim($kapak_logo, '/');
}

// Eğer logo bulunamazsa, yedek olarak varsayılan LOGO yazısını kullan
if (empty($teklif_baslik_logo) || !file_exists($teklif_baslik_logo)) {
    $baslik_logo_html = "LOGO EKLEYİNİZ.";
} else {
    $baslik_logo_html = '<img src="' . $teklif_baslik_logo . '" alt="' . $sirket_adi . '" style="max-width: calc(100% - 8px); max-height: calc(100% - 8px); margin: 4px;">';
}

if (empty($kapak_logo) || !file_exists($kapak_logo)) {
    $kapak_logo_html = "LOGO EKLEYİNİZ.";
} else {
    $kapak_logo_html = '<img src="' . $kapak_logo . '" alt="' . $sirket_adi . '" style="max-width: calc(100% - 8px); max-height: calc(100% - 8px); margin: 4px;">';
}

// Toplam sayfa sayısını hesapla
$toplam_sayfa = 5; // Varsayılan 5 sayfa (kapak, profil, hizmetler, teklif, kalemler)
if (!empty($is_ortaklari)) {
    $toplam_sayfa++; // İş ortakları sayfası da var
}

// Output buffering kullanarak HTML oluştur
ob_start();
?>
<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teklif: <?php echo htmlspecialchars($teklif['teklif_no']); ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&family=Open+Sans:wght@400;600&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Open Sans', sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 0;
        }

        .page {
            width: 21cm;
            min-height: 29.7cm;
            height: 29.7cm;
            /* Sabit yükseklik */
            padding: 2cm;
            margin: 0;
            background: white;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            position: relative;
            page-break-after: always;
            page-break-inside: avoid;
            overflow: hidden;
        }

        /* Kapak Sayfası */
        .cover-page {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: center;
            text-align: center;
            background-color: <?php echo $pdf_renk; ?>;
            /* Teklif ayarlarından çekilen renk */
            color: <?php echo $pdf_text_color; ?>;
            /* Arka plan rengine göre otomatik ayarlanan metin rengi */
        }

        .cover-logo {
            width: 300px;
            height: 150px;
            background-color: white;
            /* Logo kutusu beyaz olsun */
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: <?php echo $pdf_renk; ?>;
            /* Logo kutusu metni ana renk */
            font-weight: bold;
            font-family: 'Montserrat', sans-serif;
            font-size: 24px;
            margin-top: 5cm;
        }

        /* Kapak sayfasındaki şirket adı rengi */
        .company-name {
            font-family: 'Montserrat', sans-serif;
            font-size: 36px;
            font-weight: 700;
            color: white;
            /* Arka plan renkli olduğu için beyaz renk */
            margin-top: 5cm;
            letter-spacing: 1px;
        }

        .teklif-no {
            font-family: 'Montserrat', sans-serif;
            font-size: 22px;
            font-weight: 600;
            color: white;
            /* Arka plan renkli olduğu için beyaz renk */
            margin-top: 1cm;
            padding: 10px 30px;
            background-color: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            letter-spacing: 1px;
        }

        .cover-footer {
            margin-top: 10cm;
            font-size: 14px;
            color: #777;
        }

        /* Kapak sayfasındaki footer */
        .cover-page .cover-footer {
            margin-top: 5cm;
            font-size: 14px;
            color: rgba(255, 255, 255, 0.9);
            /* Arka plan renkli olduğu için açık renk */
        }

        /* Renkli Arka Plan Sayfalar (Sayfa 2 ve 3) */
        .page-color {
            background-color: white;
            /* Arka planı beyaz yap */
            color: #333;
            /* Metin rengini koyu yap */
        }

        .page-color .section-title {
            border-bottom: 2px solid rgba(255, 255, 255, 0.3);
        }

        .header {
            display: flex;
            align-items: center;
            margin-bottom: 2em;
        }

        .logo {
            width: 150px;
            height: 80px;
            background-color: white;
            border-radius: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 20px;
            color: <?php echo $pdf_renk; ?>;
            font-weight: bold;
            font-family: 'Montserrat', sans-serif;
        }

        .title {
            font-family: 'Montserrat', sans-serif;
            font-size: 28px;
            font-weight: 700;
        }

        .subtitle {
            font-family: 'Montserrat', sans-serif;
            font-size: 16px;
            margin-top: 5px;
            opacity: 0.8;
        }

        .section-title {
            font-size: 22px;
            color: <?php echo $pdf_renk; ?>;
            text-align: center;
            margin: 25px 0 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid <?php echo $pdf_renk; ?>;
        }

        .company-profile {
            margin-bottom: 2em;
            line-height: 1.7;
            max-height: 16cm;
            overflow: hidden;
        }

        .services {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 2em;
            max-height: 7cm;
            /* Daha kısa */
            overflow: hidden;
        }

        .service {
            flex: 0 0 30%;
            /* Sabit genişlik - 3 kutu yan yana */
            height: 120px;
            /* Daha kısa yükseklik */
            margin-bottom: 15px;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            background-color: #f0f2f7;
            /* Açık mavi/gri arka plan */
            border: 1px solid #dee2e6;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }



        .service-title {
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
            /* Başlık rengi siyah */
            text-align: center;
        }


        .service p {
            color: #000;
            /* Metin rengi siyah */
            text-align: center;
            margin: 0 auto;
            max-width: 95%;
        }

        .solutions {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 2em;
            max-height: 7cm;
            /* Daha kısa */
            overflow: hidden;
        }

        .solution {
            flex: 0 0 30%;
            /* Sabit genişlik - 3 kutu yan yana */
            height: 120px;
            /* Daha kısa yükseklik */
            margin-bottom: 15px;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            background-color: #f0f2f7;
            /* Açık mavi/gri arka plan */
            border: 1px solid #dee2e6;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }


        /* Başlıklar için */
        .service-title,
        .solution-title {
            font-weight: bold;
            color: #333;
            /* Koyu metin rengi */
            margin-bottom: 6px;
            font-size: 14px;
            /* Daha küçük başlık */
            text-align: center;
            width: 100%;
        }

        .service p,
        .solution p {
            color: #333;
            /* Koyu metin rengi */
            font-size: 12px;
            /* Daha küçük yazı */
            line-height: 1.3;
            text-align: center;
            margin: 0;
            max-width: 100%;
        }


        .solution:hover {
            transform: translateY(-3px);
        }

        .service:hover {
            transform: translateY(-3px);
        }

        .solution-title {
            color: #333;
            /* Başlık rengi siyah */
            font-weight: bold;
            margin-bottom: 8px;
            text-align: center;
            width: 100%;
        }

        .solution p {
            color: #000;
            /* Metin rengi siyah */
            text-align: center;
            margin: 0 auto;
            max-width: 95%;
        }

        /* Teklif Formu - Sayfa 4 */
        .page-white {
            background-color: white;
            color: #333;
        }

        .page-white .header {
            border-bottom: 2px solid <?php echo $pdf_renk; ?>;
            padding-bottom: 1em;
        }

        .page-white .logo {
            background-color: <?php echo $pdf_renk; ?>;
            color: white;
        }

        .page-white .title {
            color: <?php echo $pdf_renk; ?>;
        }

        .page-white .section-title {
            border-bottom: 2px solid <?php echo $pdf_renk; ?>;
            color: <?php echo $pdf_renk; ?>;
        }

        .offer-info-container {
            display: flex;
            flex-wrap: wrap;
            margin-top: 1em;
        }

        .offer-info-column {
            flex: 0 0 50%;
            padding-right: 15px;
        }

        .form-group {
            margin-bottom: 0.5em;
            display: flex;
            align-items: center;
        }

        .form-group label {
            color: <?php echo $pdf_renk; ?>;
            font-weight: bold;
            flex: 0 0 40%;
        }

        .form-group p {
            flex: 0 0 60%;
            padding: 3px 0;
            border-bottom: 1px solid #ddd;
            margin-bottom: 3px;
        }

        .offer-text {
            border-left: 4px solid <?php echo $pdf_renk; ?>;
            padding-left: 1em;
            margin: 1.5em 0;
        }

        .offer-text h3 {
            margin-bottom: 0.5em;
            color: <?php echo $pdf_renk; ?>;
        }

        /* Teklif Kalemleri - Sayfa 5 */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 2em;
            margin-bottom: 2em;
        }

        thead {
            background-color: <?php echo $pdf_renk; ?>;
            color: white;
        }

        th,
        td {
            padding: 8px 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
            font-size: 13px;
        }

        tbody tr:hover {
            background-color: #f5f5f5;
        }

        .bank-info {
            background-color: #f9f9f9;
            padding: 20px;
            border-radius: 5px;
            margin-top: 2em;
        }

        .bank-info h3 {
            color: <?php echo $pdf_renk; ?>;
            margin-bottom: 1em;
        }

        /* İş Ortakları - Son Sayfa */
        .partners {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 30px;
            margin-top: 3em;
        }

        .partner {
            width: 150px;
            height: 80px;
            background-color: #f5f5f5;
            border-radius: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #666;
            border: 1px solid #ddd;
        }

        footer {
            position: absolute;
            bottom: 1cm;
            left: 2cm;
            right: 4cm;
            /* Sağdan daha fazla boşluk bırakıyoruz sayfa numarası için */
            text-align: left;
            font-size: 12px;
            color: rgba(255, 255, 255, 0.7);
        }

        .page-white footer {
            color: #777;
        }

        .page-number {
            position: absolute;
            bottom: 1cm;
            right: 2cm;
            font-size: 12px;
            text-align: right;
            width: 3cm;
            /* Sayfa numarası için sabit genişlik */
        }

        .page-color .page-number {
            color: rgba(255, 255, 255, 0.7);
        }

        .page-white .page-number {
            color: #777;
        }

        /* Teklif özet tablosu için stil */
        .summary-table {
            width: 350px;
            margin-left: auto;
            border-collapse: collapse;
        }

        .summary-table tr td:first-child {
            text-align: right;
            padding-right: 15px;
            font-weight: bold;
        }

        .summary-table tr td:last-child {
            text-align: right;
        }

        .summary-table tr.total {
            background-color: <?php echo $pdf_renk; ?>;
            color: white;
        }

        .center-title {
            text-align: center;
        }

        /* Görsellerin düzgün görüntülenmesi için */
        img {
            max-width: 100%;
            height: auto;
            display: block;
        }

        /* PDF yazdırma için özel ayarlar */
        @media print {
            @page {
                size: A4;
                margin: 0;
            }

            html,
            body {
                width: 210mm;
                height: 297mm;
                margin: 0;
                padding: 0;
            }

            .page {
                margin: 0;
                padding: 2cm;
                box-shadow: none;
                page-break-after: always;
                page-break-inside: avoid;
                overflow: hidden;
            }

            .services,
            .solutions {
                display: flex;
                flex-wrap: wrap;
                justify-content: space-around;
                /* Eşit aralıklarla yerleştirme */
                gap: 15px;
                margin-bottom: 30px;
                padding: 0 10px;
                max-height: none;
                /* Yükseklik sınırını kaldırıyoruz */
                overflow: visible;
                /* Taşmanın görünmesi için */
            }

            /* Hover efektlerini kaldır */
            .service:hover {
                background-color: rgba(255, 255, 255, 0.1);
                transform: none;
            }
        }
    </style>
</head>

<body>
    <!-- KAPAK SAYFASI (Sayfa 1) -->
    <div class="page cover-page">
        <!-- Logo alanı - Eğer logo varsa göster, yoksa LOGO yaz -->
        <div class="cover-logo">
            <?php echo $kapak_logo_html; ?>
        </div>

        <div class="company-name"><?php echo htmlspecialchars($sirket_adi); ?></div>

        <!-- Teklif numarasını ekleyelim -->
        <div class="teklif-no">Teklif No: <?php echo htmlspecialchars($teklif['teklif_no']); ?></div>

        <div class="cover-footer">
            <?php echo $sirket_iletisim_metni; ?>
        </div>
    </div>

    <!-- SAYFA 2: Şirket Profili (Beyaz Arka Plan) -->
    <div class="page page-color">
        <div class="header">
            <div class="logo" style="background-color: white; color: <?php echo $pdf_renk; ?>;">
                <?php echo $baslik_logo_html; ?>
            </div>
            <div>
                <div class="title">ŞİRKET PROFİLİ</div>
                <div class="subtitle">Yenilikçi çözümler, güvenilir hizmet</div>
            </div>
        </div>

        <div class="company-profile">
            <p>
                <?php echo $teklif_ayar['sirket_profili'] ?? 'Firmamız, uzun yıllardan beri teknoloji ve yazılım alanında müşterilerine üstün kalitede hizmet vermektedir. Müşteri memnuniyetini ön planda tutarak, yenilikçi çözümler sunmak için sürekli kendimizi geliştirmekteyiz. Uzman kadromuz ile sektörün önde gelen firmalarından biri olmanın gururunu yaşıyoruz.'; ?>
            </p>
        </div>

        <footer>
            © <?php echo date('Y'); ?> <?php echo htmlspecialchars($sirket_adi); ?> | Tüm hakları saklıdır | <?php echo $sirket_iletisim_metni; ?>
        </footer>

        <div class="page-number">Sayfa 2 / <?php echo $toplam_sayfa; ?></div>
    </div>


    <!-- SAYFA 3: Hizmetlerimiz ve Çözümlerimiz -->
    <div class="page page-color">
        <div class="header">
            <div class="logo" style="background-color: white; color: <?php echo $pdf_renk; ?>;">
                <?php echo $baslik_logo_html; ?>
            </div>
            <div>
                <div class="title" style="color: <?php echo $pdf_renk; ?>;">HİZMETLERİMİZ VE ÇÖZÜMLERİMİZ</div>
                <div class="subtitle">Kaliteli hizmet, güvenilir çözümler</div>
            </div>
        </div>

        <h2 class="section-title">HİZMETLERİMİZ</h2>
        <div class="services">
            <?php echo $hizmetler_html; ?>
        </div>

        <h2 class="section-title">ÇÖZÜMLERİMİZ</h2>
        <div class="solutions">
            <?php echo $cozumler_html; ?>
        </div>

        <footer>
            © <?php echo date('Y'); ?> <?php echo htmlspecialchars($sirket_adi); ?> | Tüm hakları saklıdır | <?php echo $sirket_iletisim_metni; ?>
        </footer>

        <div class="page-number">Sayfa 3 / <?php echo $toplam_sayfa; ?></div>
    </div>

    <!-- SAYFA 4: Teklif Formu -->
    <div class="page page-white">
        <div class="header">
            <div class="logo" style="background-color: white; color: <?php echo $pdf_renk; ?>;">
                <?php echo $baslik_logo_html; ?>
            </div>
            <div>
                <div class="title">TEKLİF FORMU</div>
                <div class="subtitle">Özel çözüm teklifimiz</div>
            </div>
        </div>

        <h2 class="section-title">TEKLİF BİLGİLERİ</h2>
        <div class="offer-info-container">
            <div class="offer-info-column">
                <div class="form-group">
                    <label>Firma Adı:</label>
                    <p><strong><?php echo htmlspecialchars($musteri_adi); ?></strong></p>
                </div>

                <div class="form-group">
                    <label>Teklif No:</label>
                    <p><strong><?php echo htmlspecialchars($teklif['teklif_no']); ?></strong></p>
                </div>

                <div class="form-group">
                    <label>Teklif Tarihi:</label>
                    <p><strong><?php echo htmlspecialchars($teklif_tarihi); ?></strong></p>
                </div>
            </div>

            <div class="offer-info-column">
                <div class="form-group">
                    <label>Geçerlilik Süresi:</label>
                    <p><strong><?php echo htmlspecialchars($gecerlilik_tarihi); ?></strong></p>
                </div>

                <div class="form-group">
                    <label>Teslim Süresi:</label>
                    <p><strong><?php echo htmlspecialchars($teklif['teslim_suresi'] ?? '-'); ?></strong></p>
                </div>

                <div class="form-group">
                    <label>Ödeme Koşulu:</label>
                    <p><strong><?php echo htmlspecialchars($teklif['odeme_kosullari'] ?? '-'); ?></strong></p>
                </div>
            </div>
        </div>

        <div class="offer-text">
            <h3>Teklif Detayları</h3>
            <p>
                Sayın yetkili,
            </p>
            <p>
                <?php echo $teklif_ayar['teklif_ust_yazisi'] ?? 'Firmamız tarafından hazırlanan bu teklif, ihtiyaçlarınız doğrultusunda özel olarak tasarlanmıştır. Sunduğumuz çözümler, işletmenizin verimliliğini artırmak ve rekabet avantajı sağlamak amacıyla uzman ekibimiz tarafından belirlenmiştir.'; ?>
            </p>
            <p>
                <?php echo $teklif_ayar['teklif_ust_yazisi_aciklama'] ?? 'Bu teklifte belirtilen ürün ve hizmetler, en yüksek kalite standartlarımız doğrultusunda sunulmaktadır. Teklifimizin geçerlilik süresi içerisinde onayınızı bekliyoruz. Herhangi bir sorunuz olması durumunda bizimle iletişime geçmekten çekinmeyiniz.'; ?>
            </p>
            <p>
                Saygılarımızla,<br>
                <?php echo htmlspecialchars($sirket_adi); ?>
            </p>
        </div>

        <footer>
            © <?php echo date('Y'); ?> <?php echo htmlspecialchars($sirket_adi); ?> | Tüm hakları saklıdır | <?php echo $sirket_iletisim_metni; ?>
        </footer>

        <div class="page-number">Sayfa 4 / <?php echo $toplam_sayfa; ?></div>
    </div>

    <!-- SAYFA 5: Teklif Kalemleri -->
    <div class="page page-white">
        <div class="header">
            <div class="logo" style="background-color: white; color: <?php echo $pdf_renk; ?>;">
                <?php echo $baslik_logo_html; ?>
            </div>
            <div>
                <div class="title">TEKLİF KALEMLERİ</div>
                <div class="subtitle">Ürün ve hizmet detayları</div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th style="width: 20%;">Ürün/Hizmet</th>
                    <th style="width: 25%;">Açıklama</th>
                    <th style="width: 8%;">Miktar</th>
                    <th style="width: 7%;">Birim</th>
                    <th style="width: 12%;">Birim Fiyat</th>
                    <th style="width: 8%;">KDV</th>
                    <th style="width: 15%;">Tutar</th>
                </tr>
            </thead>
            <tbody>
                <?php echo $teklif_satirlari_html; ?>
            </tbody>
        </table>

        <!-- Teklif Özeti Tablosu -->
        <table class="summary-table">
            <tr>
                <td>Ara Toplam:</td>
                <td><?php echo number_format($ara_toplam, 2, ',', '.'); ?> <?php echo htmlspecialchars($teklif['kur_kodu'] ?? 'TL'); ?></td>
            </tr>
            <?php if (isset($teklif['iskonto_genel_tutar']) && $teklif['iskonto_genel_tutar'] > 0): ?>
                <tr>
                    <td>Genel İskonto:</td>
                    <td>-<?php echo number_format($teklif['iskonto_genel_tutar'], 2, ',', '.'); ?> <?php echo htmlspecialchars($teklif['kur_kodu'] ?? 'TL'); ?></td>
                </tr>
            <?php endif; ?>
            <tr>
                <td>KDV Toplamı:</td>
                <td><?php echo number_format($toplam_kdv, 2, ',', '.'); ?> <?php echo htmlspecialchars($teklif['kur_kodu'] ?? 'TL'); ?></td>
            </tr>
            <tr class="total">
                <td>Genel Toplam:</td>
                <td><?php echo number_format($genel_toplam, 2, ',', '.'); ?> <?php echo htmlspecialchars($teklif['kur_kodu'] ?? 'TL'); ?></td>
            </tr>
        </table>

        <?php if (!empty($teklif['notlar'])): ?>
            <div class="bank-info">
                <h3>Notlar</h3>
                <p><?php echo nl2br(htmlspecialchars($teklif['notlar'])); ?></p>
            </div>
        <?php endif; ?>

        <!-- Banka bilgileri -->
        <div class="bank-info">
            <h3>Banka Bilgileri</h3>
            <?php if (!empty($banka_hesaplari_html)): ?>
                <?php echo $banka_hesaplari_html; ?>
            <?php else: ?>
                <p>Ödeme bilgileri için lütfen bizimle iletişime geçiniz.</p>
            <?php endif; ?>
        </div>

        <footer>
            © <?php echo date('Y'); ?> <?php echo htmlspecialchars($sirket_adi); ?> | Tüm hakları saklıdır | <?php echo $sirket_iletisim_metni; ?>
        </footer>

        <div class="page-number">Sayfa 5 / <?php echo $toplam_sayfa; ?></div>
    </div>

    <?php if (!empty($is_ortaklari)): ?>
        <!-- SAYFA 6: İş Ortakları (Son Sayfa) -->
        <div class="page page-white">
            <div class="header">
                <div class="logo" style="background-color: white; color: <?php echo $pdf_renk; ?>;">
                    <?php echo $baslik_logo_html; ?>
                </div>
                <div>
                    <div class="title">İŞ ORTAKLARIMIZ</div>
                    <div class="subtitle">Güvenilir çözüm ortaklarımız</div>
                </div>
            </div>

            <p style="text-align: center; margin-top: 2em;">
                Şirketimiz, alanında uzman ve global çapta tanınmış kurumlarla iş birliği yaparak
                müşterilerimize en yüksek kalitede hizmet sunmaktadır. Stratejik ortaklarımız sayesinde
                en güncel teknolojileri ve çözümleri size sunabiliyoruz.
            </p>

            <div class="partners">
                <?php echo $is_ortaklari_html; ?>
            </div>

            <div style="text-align: center; margin-top: 4em;">
                <h3>Bize Ulaşın</h3>
                <p>
                    <strong>Adres:</strong> <?php echo htmlspecialchars($sirket_adresi); ?><br>
                    <strong>Telefon:</strong> <?php echo htmlspecialchars($sirket_telefon); ?><br>
                    <strong>E-posta:</strong> <?php echo htmlspecialchars($sirket_eposta); ?><br>
                    <strong>Web:</strong> <?php echo htmlspecialchars($sirket_web); ?>
                </p>
            </div>

            <footer>
                © <?php echo date('Y'); ?> <?php echo htmlspecialchars($sirket_adi); ?> | Tüm hakları saklıdır | <?php echo $sirket_iletisim_metni; ?>
            </footer>

            <div class="page-number">Sayfa 6 / 6</div>
        </div>
    <?php endif; ?>
</body>

</html>

<?php
// HTML içeriğini al
$html = ob_get_clean();

try {
    // PDF dosya adını oluştur
    $pdf_filename = 'Teklif_' . $teklif['teklif_no'] . '.pdf';
    $temp_path = sys_get_temp_dir() . '/' . $pdf_filename;

    // Geçici HTML dosyası oluştur (file:// protokolü ile erişilebilir olacak)
    $temp_html_path = sys_get_temp_dir() . '/temp_' . time() . '.html';

    // Görsellerin doğru şekilde çekilmesi için göreceli URL'leri mutlak URL'lere dönüştür
    $base_url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']);
    $html = preg_replace('/(src|href)=(["\'])(?!http|data|\/\/)(.*?)\\2/i', '$1=$2' . $base_url . '/$3$2', $html);

    // HTML'i geçici dosyaya yaz
    file_put_contents($temp_html_path, $html);

    // Browsershot'u yapılandır
    $browsershot = new \Spatie\Browsershot\Browsershot('file://' . str_replace('\\', '/', $temp_html_path));

    // Node.js'in tam yolunu belirt
    $browsershot->setNodeBinary('C:\Program Files\nodejs\node.exe');

    // PDF ayarlarını yapılandır - sayfa arası taşma sorunlarını çözmek için
    $browsershot->showBackground(true)
        ->format('A4')
        ->paperSize(210, 297) // mm cinsinden A4 boyutu
        ->margins(0, 0, 0, 0)
        ->scale(1) // Biraz küçült
        ->waitUntilNetworkIdle()
        // Her sayfanın kendi içinde olması için sayfa ayarlarını yapılandır
        ->addChromiumArguments([
            '--disable-web-security',
            '--allow-file-access-from-files'
        ])
        // Sayfa kesme ve taşma kontrolü için CSS ekleme
        ->setOption('preferCSSPageSize', true)
        ->setOption('printBackground', true) // Arka plan renklerini yazdır
        ->emulateMedia('screen') // Yazdırma değil ekran medyasını kullan
        ->setDelay(1500); // Görsellerin yüklenmesi için daha uzun süre bekle

    // PDF'i geçici dosyaya kaydet
    $browsershot->save($temp_path);

    // Geçici HTML dosyasını sil
    @unlink($temp_html_path);

    // Dosya var mı kontrol et
    if (!file_exists($temp_path)) {
        throw new Exception("PDF dosyası oluşturulamadı");
    }

    // PDF'i doğrudan tarayıcıya gönder
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $pdf_filename . '"');
    header('Content-Length: ' . filesize($temp_path));

    // Çıktı arabelleğini temizle
    ob_clean();
    flush();

    // Dosyayı oku ve gönder
    readfile($temp_path);

    // Geçici dosyayı sil
    @unlink($temp_path);
    exit;
} catch (Exception $e) {
    // Hata mesajını göster
    die("PDF oluşturma hatası: " . $e->getMessage());
}
?>