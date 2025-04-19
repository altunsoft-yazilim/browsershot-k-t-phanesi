<?php
/**
 * Browsershot Helper Class
 * Browsershot kütüphanesini daha kolay kullanmak için yardımcı sınıf
 */
namespace AltunSoft\PDF;

// Composer autoload
require_once 'pdf-browser/vendor/autoload.php';

use Spatie\Browsershot\Browsershot;
use Exception;

class BrowsershotHelper
{
    /**
     * Browsershot kütüphanesinin gerekli yollarını ayarlar
     * 
     * @param Browsershot $browsershot Browsershot nesnesi
     * @return Browsershot
     */
    public static function configure(Browsershot $browsershot) 
    {
        // NodeJS ve NPM için dizinleri ayarla
        // Eğer systeminizde Node.js kuruluysa bunları yorum satırına alabilirsiniz
        $nodeJsPath = 'pdf-browser/vendor/spatie/browsershot/bin/node';
        $npmPath = 'pdf-browser/vendor/spatie/browsershot/bin/npm';
        
        // Browsershot yapılandırmasını ayarla
        return $browsershot->setNodeBinary($nodeJsPath)
                          ->setNpmBinary($npmPath);
    }
    
    /**
     * HTML içeriğinden PDF oluşturur
     * 
     * @param string $html HTML içeriği
     * @param string $filename İndirilecek dosya adı
     * @param array $options Browsershot seçenekleri
     * @return void
     */
    public static function generatePdfFromHtml($html, $filename, $options = []) 
    {
        // Geçici HTML dosyası oluştur
        $temp_dir = 'temp';
        if (!is_dir($temp_dir)) {
            mkdir($temp_dir, 0777, true);
        }
        
        $temp_file = $temp_dir . '/teklif_' . time();
        file_put_contents($temp_file . '.html', $html);
        
        try {
            // Browsershot nesnesini oluştur
            $browsershot = new Browsershot($temp_file . '.html');
            
            // Browsershot yapılandırmasını ayarla
            self::configure($browsershot);
            
            // Varsayılan seçenekleri ayarla
            $browsershot->margins(0, 0, 0, 0)
                      ->paperSize(210, 297) // A4 boyutu (mm cinsinden)
                      ->scale(1)
                      ->waitUntilNetworkIdle()
                      ->showBackground()
                      ->format('A4');
            
            // Ek seçenekleri ayarla
            foreach ($options as $method => $params) {
                if (is_array($params)) {
                    $browsershot->{$method}(...$params);
                } else {
                    $browsershot->{$method}($params);
                }
            }
            
            try {
                // PDF içeriğini al
                $pdf_content = $browsershot->pdf();
                
                // Tarayıcıya gönder
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . strlen($pdf_content));
                
                echo $pdf_content;
            } catch (Exception $inner_e) {
                // PDF oluşturulamadıysa, HTML'i göster
                error_log("PDF oluşturma hatası: " . $inner_e->getMessage());
                
                // HTML içeriğini göster
                header('Content-Type: text/html; charset=utf-8');
                echo $html;
                
                echo '<script>
                    // HTML çıktısını göster
                    document.addEventListener("DOMContentLoaded", function() {
                        alert("PDF oluşturulamadı: ' . str_replace('"', '\"', $inner_e->getMessage()) . '\\nHTML çıktısı gösteriliyor.");
                    });
                </script>';
            }
            
            // Geçici dosyaları temizle
            @unlink($temp_file);
            @unlink($temp_file . '.html');
            
            exit;
        } catch (Exception $e) {
            // Geçici dosyaları temizle
            @unlink($temp_file);
            @unlink($temp_file . '.html');
            
            throw new Exception("PDF oluşturma hatası: " . $e->getMessage());
        }
    }
    
    /**
     * HTML dosyasını kaydeder (Debug amaçlı)
     * 
     * @param string $html HTML içeriği
     * @param string $path Kayıt yolu
     * @return string Dosya yolu
     */
    public static function saveHtml($html, $path = null) 
    {
        if ($path === null) {
            $path = 'temp/teklif_' . time() . '.html';
            
            // Dizini oluştur
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }
        
        file_put_contents($path, $html);
        return $path;
    }
    
    /**
     * PDF dosyasını kaydeder
     * 
     * @param string $html HTML içeriği
     * @param string $path Kayıt yolu
     * @param array $options Browsershot seçenekleri
     * @return string Dosya yolu
     */
    public static function savePdf($html, $path, $options = []) 
    {
        // Geçici HTML dosyası oluştur
        $temp_dir = 'temp';
        if (!is_dir($temp_dir)) {
            mkdir($temp_dir, 0777, true);
        }
        
        $temp_file = $temp_dir . '/teklif_' . time();
        file_put_contents($temp_file . '.html', $html);
        
        try {
            // Browsershot nesnesini oluştur
            $browsershot = new Browsershot($temp_file . '.html');
            
            // Browsershot yapılandırmasını ayarla
            self::configure($browsershot);
            
            // Varsayılan seçenekleri ayarla
            $browsershot->margins(0, 0, 0, 0)
                      ->paperSize(210, 297) // A4 boyutu (mm cinsinden)
                      ->scale(1)
                      ->waitUntilNetworkIdle()
                      ->showBackground()
                      ->format('A4');
            
            // Ek seçenekleri ayarla
            foreach ($options as $method => $params) {
                if (is_array($params)) {
                    $browsershot->{$method}(...$params);
                } else {
                    $browsershot->{$method}($params);
                }
            }
            
            try {
                // PDF dosyasını kaydet
                $browsershot->save($path);
            } catch (Exception $inner_e) {
                // PDF oluşturulamadıysa, HTML'i kaydet
                error_log("PDF kaydetme hatası: " . $inner_e->getMessage());
                $html_path = str_replace('.pdf', '.html', $path);
                file_put_contents($html_path, $html);
                return $html_path;
            }
            
            // Geçici dosyaları temizle
            @unlink($temp_file);
            @unlink($temp_file . '.html');
            
            return $path;
        } catch (Exception $e) {
            // Geçici dosyaları temizle
            @unlink($temp_file);
            @unlink($temp_file . '.html');
            
            throw new Exception("PDF kaydetme hatası: " . $e->getMessage());
        }
    }
}