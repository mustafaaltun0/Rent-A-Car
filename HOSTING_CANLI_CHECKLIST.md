# Hosting Canlıya Geçiş Checklist

## Altyapı
- PHP sürümü `8.1+`
- MySQL / MariaDB üretim veritabanı hazır
- Apache `mod_rewrite` aktif
- Sunucu saat dilimi `Europe/Istanbul`
- `public` erişimi sadece gerekli dizinlere açık

## Güvenlik
- SSL sertifikası kuruldu
- HTTP -> HTTPS yönlendirmesi aktif
- `display_errors=Off`
- `log_errors=On`
- `session.cookie_secure=1`
- `session.cookie_httponly=1`
- phpMyAdmin dış erişimi kapalı veya IP kısıtlı
- Veritabanı kullanıcısı sadece gerekli yetkilere sahip

## Uygulama Konfigürasyonu
- `APP_ENV=production`
- `APP_FORCE_HTTPS=true`
- `SESSION_COOKIE_SECURE=true`
- `DB_HOST`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- Gerekirse `DB_CHARSET=utf8mb4`

## Dosya ve İzinler
- `storage/company-logos` yazılabilir
- Kod dosyaları yazmaya kapalı
- Yedekleme klasörleri web root dışında

## Veritabanı
- Tüm son migration / kolon kontrolleri işlendi
- UTF-8 / `utf8mb4` aktif
- Test verileri temizlendi
- Admin kullanıcıları doğrulandı

## Fonksiyon Testleri
- Login / logout
- Kullanıcı ekleme / düzenleme / arşivleme
- Firma ekleme / firma detay güncelleme
- Araç, kiralama, tahsilat, gelir-gider akışları
- Bildirimler ve dashboard özetleri
- Mobil görünüm kontrolü

## Operasyon
- Günlük veritabanı yedeği
- Haftalık dosya yedeği
- Hata log dizini tanımlı
- İlk 7 gün için canlı kullanım takibi planlandı

## Yayın Öncesi Son Kontrol
- Tarayıcı console hataları temiz
- Türkçe karakterler doğru
- Yetki kısıtları doğru
- Yanlış URL'lerde hata sayfası kontrollü
- Canlı domain ve mobil erişim test edildi
