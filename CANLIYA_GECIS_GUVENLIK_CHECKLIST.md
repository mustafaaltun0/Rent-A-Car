# Canlıya Geçiş Güvenlik Checklist'i

## 1. Ortam ve sunucu
- `APP_ENV=production` olacak.
- `display_errors=Off`, `log_errors=On` olacak.
- Apache dizin listeleme kapalı olacak.
- `C:\xampp\htdocs\rentecarWeb\.htaccess` aktif ve test edilmiş olacak.
- XAMPP demo sayfaları, gereksiz modüller ve dışa açık araçlar kapatılacak.

## 2. Veritabanı
- MySQL `root` ile canlı kullanım yapılmayacak.
- Sadece bu proje için ayrı kullanıcı açılacak.
- Veritabanı kullanıcısına minimum gerekli yetkiler verilecek.
- Veritabanı ve tablolar `utf8mb4` olacak.
- Otomatik günlük yedek planı kurulacak.

## 3. Kimlik doğrulama ve yetki
- Tüm yönetici hesaplarında güçlü şifre zorunlu olacak.
- Varsayılan/deneme kullanıcıları silinecek.
- Yetkisiz kullanıcının firma verisine erişemediği test edilecek.
- `company_id` izolasyonu tüm ana ekranlarda doğrulanacak.
- Platform sahibine özel işlemler ayrı test edilecek.

## 4. Oturum ve çerezler
- HTTPS zorunlu olacak.
- `session.cookie_httponly=1` açık olacak.
- `session.cookie_secure=1` sadece SSL altında aktif olacak.
- Oturum sabitleme ve çıkış akışı test edilecek.

## 5. Dosya ve yükleme güvenliği
- Logo/yükleme klasörlerinde script çalıştırma engellenecek.
- Sadece izin verilen dosya tipleri kabul edilecek.
- Maksimum dosya boyutu sunucu tarafında da zorlanacak.
- Hassas klasörlere doğrudan erişim engeli kontrol edilecek.

## 6. Uygulama testleri
- Giriş, çıkış, şifre değişimi test edilecek.
- Kullanıcı, firma, kiralama, tahsilat, araç satışı akışları test edilecek.
- CSRF koruması form bazında doğrulanacak.
- Hatalı URL ve yetkisiz erişim denemeleri yapılacak.
- Türkçe karakterler tüm sayfalarda mobil ve masaüstünde kontrol edilecek.

## 7. İzleme ve operasyon
- PHP ve Apache hata loglarının yolu netleştirilecek.
- Veritabanı hata logu izlenecek.
- Kritik işlemler için audit log kayıtları kontrol edilecek.
- Yedekten geri dönüş senaryosu en az bir kez denenecek.

## 8. Canlıya çıkmadan hemen önce
- `php -l` ile temel dosyalar taranacak.
- Son veritabanı yedeği alınacak.
- Test verileri ve boş kayıtlar temizlenecek.
- Mobil görünüm ana ekran, kiralamalar, tahsilat merkezi ve gelir gider üzerinde yeniden kontrol edilecek.
