# dopaliwa.pl — Sklep Magento 2

## Projekt
Sklep internetowy na Magento 2.4.7-p3 Open Source — sprzęt do dystrybucji paliw, olejów, smarów i płynów.
Domena: dopaliwa.pl | Firma: TECHTOR Jakub Cechowski

## Serwer produkcyjny
- **IP**: 72.62.1.240
- **SSH**: `ssh root@72.62.1.240` (klucz ed25519 z tego hosta)
- **VPS**: Hostinger KVM2, Vilnius, Ubuntu 24.04
- **Stack**: nginx + PHP 8.3-FPM + MySQL 8.0 + OpenSearch 2.19 + Redis 7.0
- **Magento root**: `/var/www/dopaliwa`
- **Admin panel**: `http://72.62.1.240/techtor_admin/` (user: admin)
- **PHP-FPM pool**: `/etc/php/8.3/fpm/pool.d/dopaliwa.conf` (socket: `/run/php/php-fpm-dopaliwa.sock`)

## Struktura lokalna
```
app/
  code/Techtor/          # Custom moduły (5 szt.)
    BaseLinker/           # Integracja zamówień z BaseLinker
    Catalog/              # Rozszerzenia katalogu
    Firmao/               # Sync z CRM Firmao
    Shipping/             # Metody wysyłki (InPost/DPD/DHL)
    StockSync/            # Synchronizacja stanów magazynowych
  design/frontend/Techtor/dopaliwa/   # Child theme (parent: Magento/luma)
server/
  provision.sh            # Provisioning VPS (PHP, MySQL, Redis, ES, nginx)
  install-magento.sh      # Instalacja Magento via Composer
  config/nginx-dopaliwa.conf  # Vhost nginx
```

## Komendy deploy na serwerze
```bash
cd /var/www/dopaliwa
# Po zmianie kodu modułów:
sudo -u www-data php bin/magento setup:upgrade
rm -rf generated/code/* generated/metadata/*
chown -R www-data:www-data generated
sudo -u www-data php bin/magento setup:di:compile
sudo -u www-data php bin/magento setup:static-content:deploy pl_PL en_US -f
sudo -u www-data php bin/magento cache:flush
```

## Hostinger API
- Endpoint: `https://developers.hostinger.com`
- Auth: Bearer token
- VPS ID: 1760730

## Konfiguracja sklepu
- Język: pl_PL, Waluta: PLN, Timezone: Europe/Warsaw
- VAT: 23% (PL-VAT-23), ceny brutto
- Kategorie: 9 L1 + 44 L2 (podział wg typu produktu)
- Atrybuty filtrów: medium, zasilanie, producent, wydajność, średnica przyłączy, materiał, średnica węża, ciśnienie robocze
- Płatności: przelew bankowy, za pobraniem (Przelewy24 do dodania)
- Wysyłka: darmowa >2000zł, InPost 12.99zł, kurier DPD/DHL 19.99zł
- 2FA: wyłączone (włączyć przed go-live!)
- Moduł InPost: smartmage/inpost (wymaga API token ShipX)

## Znane problemy / workaroundy
- Magento 2.4.7 + ES8 = niekompatybilne → zamienione na OpenSearch 2.19
- `DeployPackage.php` — patch na `Phrase` bug (vendor edit, nadpisywany przez composer update)
- Favicon nie może być jako `<link>` w layout XML (powoduje ICO merge crash) → ustawiać przez admin panel
- MySQL: `query_cache` usunięty w 8.0.46, `innodb_log_file_size` deprecated → `innodb_redo_log_capacity`
- Composer create-project nie kopiuje `magento2-base` do roota → wymaga ręcznego `cp -rn` + utworzenia `app/etc/vendor_path.php`

## TODO
- [ ] Domena: potwierdzić transfer dopaliwa.pl, DNS A → 72.62.1.240, SSL certbot
- [ ] Przelewy24: pobrać moduł z panel.przelewy24.pl, zainstalować
- [ ] InPost: wpisać API token ShipX w admin
- [ ] Import produktów z Firmao/BaseLinker
- [ ] Regulamin + Polityka prywatności — zlecić prawnikowi
- [ ] 2FA: włączyć Magento_TwoFactorAuth przed go-live
- [ ] Numer konta bankowego w instrukcjach przelewu
- [ ] NIP firmy uzupełnić na stronie O nas
