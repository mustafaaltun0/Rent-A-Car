# rentecarWeb

Bu klasor Spring Boot projesinin PHP + MySQL (XAMPP) cevrimidir.

## Kurulum
1. `schema.sql` dosyasini phpMyAdmin veya MySQL terminalinde calistir.
2. `config/database.php` icindeki sifreyi XAMPP MySQL sifrene gore duzenle.
3. Bu klasoru `C:\xampp\htdocs\rentecarWeb` altina kopyala.
4. Tarayicidan `http://localhost/rentecarWeb/index.php` ac.
5. Production icin `.env` dosyasi olusturup en az `APP_ENV`, `APP_BASE_URL`, `APP_FORCE_HTTPS`, `SESSION_COOKIE_SECURE` ayarlarini tanimla.

## Migrasyon Merkezi
- Platform yoneticisi olarak `migrations.php` ekranina gir.
- `Bekleyenleri Uygula` ile runtime'da dagilan sema degisikliklerini tek merkezden veritabanina isle.
- Bu ekran, yeni hosting kurulumu ve canliya gecis oncesi sema drift kontrolu icin kullanilir.
- Legacy veri tasima/mudahale adimlari artik bootstrap sirasinda otomatik calismaz; gerekiyorsa kontrollu bakim penceresinde acikca planlanmalidir.

## Yardimci Katman
- Ortak helper yapisi kademeli olarak `includes/modules/` altina ayrilmaktadir.
- Ilk ayrilan moduller: `ledger_helpers.php`, `notification_helpers.php`, `pagination_helpers.php`.
