# HTTPS Geçiş Rehberi

## 1) Alan adı ve SSL
- Alan adını hosting sunucusuna bağla.
- Geçerli bir SSL sertifikası kur.
- `https://alanadiniz.com` ve `https://www.alanadiniz.com` açılıyor mu kontrol et.

## 2) HTTP -> HTTPS yönlendirmesi
- Apache `VirtualHost` veya `.htaccess` seviyesinde tüm HTTP isteklerini HTTPS'e yönlendir.
- Sertifika tamamen çalışmadan HSTS açma.

Örnek `.htaccess`:

```apache
RewriteEngine On
RewriteCond %{HTTPS} !=on
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

## 3) PHP oturum güvenliği
- Production ortamında `session.cookie_secure=1` aktif olmalı.
- `session.cookie_httponly=1` ve `session.use_strict_mode=1` açık kalmalı.

## 4) Uygulama kontrolleri
- Giriş / çıkış akışı HTTPS altında test edilmeli.
- Logo ve dosya yükleme işlemleri HTTPS altında test edilmeli.
- Tarayıcı konsolunda mixed content hatası olmamalı.

## 5) HSTS
- SSL ve yönlendirme tamamen stabil olduktan sonra aktif et.
- Mevcut projede HTTPS algılanınca `Strict-Transport-Security` header'ı gönderiliyor.

## 6) Son test listesi
- `http://` açıldığında otomatik `https://` olmalı.
- Login ekranı, panel ekranları, form post işlemleri ve çıkış çalışmalı.
- Mobil cihazdan da sertifika hatası olmadan açılmalı.
