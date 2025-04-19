// Eğer ki bilginiz var ise kullanımı ap açık kolaydır.
// Yeni başlayanlar için kısa açıklama eklemek istiyorum.

Browsershot, Laravel ve PHP projelerinde yaygın olarak kullanılan bir kütüphanedir ve HTML içeriğini programatik olarak PDF, screenshot (görsel)
ya da PNG/JPEG formatlarına dönüştürmek için kullanılır. Arka planda Headless Chrome (Puppeteer) kullanır. 

 URULUM ANLATIMI: 


Kurulum (Composer ile): composer require spatie/browsershot

Ayrıca Node.js ve Puppeteer'ın yüklü olması gerekir:

npm install puppeteer --global

NOT!!!:  Laravel Forge veya Plesk gibi ortamlarda çalıştırırken bazı özel ayarlar ve path düzeltmeleri gerekebilir. Sunucu yapınıza göre ayarlarmaları yapın.

HTML'den PDF Oluşturma: 

use Spatie\Browsershot\Browsershot;

Browsershot::html('<h1>Merhaba, Destek olmamı istiyorsan ulaşabilirsin. +90 542 281 9973</h1>')
    ->save('mahsunaltun.pdf');


 Web Sayfasından PDF:

 Browsershot::url('https://mahsunaltun.com.tr')
    ->waitUntilNetworkIdle()
    ->save('mahsunaltun.pdf');

  GÖRÜNTÜ OLARAK KAYIT ETME: 

  Browsershot::url('https://mahsunaltun.com.tr')
    ->windowSize(1920, 1080)
    ->save('resim-mahsun.png');

LARAVEL KULLANIMI İÇİN ÖRNEK: (CONTROLLER DA)

public function exportPdf()
{
    Browsershot::url(route('teklif.show', 1))
        ->format('A4')
        ->waitUntilNetworkIdle()
        ->save(storage_path('app/public/teklif.pdf'));

    return response()->download(storage_path('app/public/teklif.pdf'));
}



Plesk / Sunucu Uyum Notları:
Plesk üzerinde çalıştırırken genelde şu sorunlar çıkabilir:

node komutu bulunamazsa: which node ile doğru yolu bulup Browsershot::setNodeBinary('/usr/bin/node') gibi tanımlama yapmalısın. Sunucunuz var ise, SSH ile root erişim sağlıyarak ilgili kök dizini düzenleyin.
  
puppeteer izin hatası verirse: Puppeteer’in bulunduğu dizine chmod -R 755 verebilirsin.

Headless Chrome çalışmıyorsa, google-chrome-stable kurulu mu kontrol et.


