# dopaliwa.pl — Sklep Magento 2

## Projekt
Sklep internetowy na Magento 2.4.7-p3 Open Source — sprzęt do dystrybucji paliw, olejów, smarów i płynów.
Domena: dopaliwa.pl | Firma: TECHTOR Jarosław Cechowski

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
- Kategorie: 14 L2 + podkategorie (reorganizacja 2026-06-22)
- Atrybuty filtrów: medium, zasilanie, producent, wydajność, średnica przyłączy, materiał, średnica węża, ciśnienie robocze
- Producenci: PIUSI, RAASM, FMT, Adam Pumps, HIFI FILTER, GAITER (770 produktów przypisanych)
- Płatności: przelew bankowy, za pobraniem, Autopay/Tpay/PayU (do konfiguracji)
- Wysyłka: darmowa >2000zł, InPost 12.99zł, kurier DPD/DHL 19.99zł
- 2FA: wyłączone (włączyć przed go-live!)
- Moduł InPost: smartmage/inpost (wymaga API token ShipX)
- Checkout agreements: 3 obowiązkowe checkboxy (regulamin, RODO, odstąpienie)
- VAT ID: pole NIP widoczne w formularzu (opcjonalne, B2B)

## Znane problemy / workaroundy
- Magento 2.4.7 + ES8 = niekompatybilne → zamienione na OpenSearch 2.19
- `DeployPackage.php` — patch na `Phrase` bug (vendor edit, nadpisywany przez composer update)
- Favicon nie może być jako `<link>` w layout XML (powoduje ICO merge crash) → ustawiać przez admin panel
- MySQL: `query_cache` usunięty w 8.0.46, `innodb_log_file_size` deprecated → `innodb_redo_log_capacity`
- Composer create-project nie kopiuje `magento2-base` do roota → wymaga ręcznego `cp -rn` + utworzenia `app/etc/vendor_path.php`

## Wykonane (2026-06-18 — audyt CTO)
- [x] Magento: production mode (było default!)
- [x] env.php/config.php: chmod 640 (było world-readable)
- [x] Supervisor consumers: naprawione (3x RUNNING)
- [x] SSH: PasswordAuthentication no, PermitRootLogin prohibit-password
- [x] nginx: server_tokens off, max_execution_time 300s, worker_connections 1024
- [x] OpenSearch: replicas=0 → cluster GREEN
- [x] Fail2ban: +magento-admin +nginx-scan jails
- [x] Braintree: wyłączony (spam w logach)
- [x] MySQL backup: codziennie 3:00, /var/backups/mysql/, 14 dni retencji
- [x] Theme: fix @dp-primary, konsolidacja CSS (flex zamiast float), self-hosted Inter font
- [x] A11y: focus indicators, kontrast WCAG, ARIA na bannerze
- [x] EAV: dodane atrybuty ean + delivery_time (AddMissingAttributes DataPatch)
- [x] Cookie consent banner (GDPR/RODO) — vanilla JS, dp_cookie_consent cookie
- [x] Product JSON-LD schema (rich snippets: cena, dostępność, marka, EAN)
- [x] Organization JSON-LD sitewide

## Wykonane (2026-06-20 — tłumaczenia, dane, SEO)
- [x] Paczka językowa Techtor pl_PL: 3776 tłumaczeń (admin + frontend)
- [x] Theme i18n pl_PL.csv: 195 tłumaczeń frontend
- [x] Fix submenu nawigacji — biały tekst na białym tle
- [x] Przycisk "Dodaj do koszyka" — prostokątny, qty+button w jednej linii
- [x] Etykiety "netto" / "brutto" przy cenach (CSS ::after)
- [x] JSON-LD: BreadcrumbList, realna stawka wysyłki, telephone
- [x] CMS: Regulamin sklepu (adaptacja z techtor.pl)
- [x] CMS: Polityka prywatności RODO/GDPR
- [x] NIP (8792283040) + REGON (340511303) na stronach O nas i Kontakt
- [x] Adres poprawiony wszędzie: ul. Szczecińska 28, 87-100 Toruń
- [x] Telefon poprawiony wszędzie: +48 736 133 817
- [x] Sitemap.xml wygenerowany (1111 URL-i)
- [x] robots.txt (blokuje /checkout, /customer, /techtor_admin)
- [x] Tax: algorytm ROW_BASE_CALCULATION (fix zaokrąglania)
- [x] 2FA wyłączone (moduł disabled)
- [x] SKU bez "#", wiersz stock/SKU wyśrodkowany

## Wykonane (2026-06-21 — moduły, blog, CMS)
- [x] Moduły płatności: Autopay (BLIK/karty/przelewy), Tpay, PayU — zainstalowane, do konfiguracji
- [x] InPost (smartmage/inpost) — zainstalowany, wymaga API token ShipX w admin
- [x] Google Tag Manager (magefan/module-google-tag-manager) — wymaga Container ID
- [x] Google Analytics GA4 (Magento_GoogleGtag) — wymaga Measurement ID
- [x] Google reCAPTCHA (wbudowany) — wymaga Site key + Secret key
- [x] Blog Magefan: 3 kategorie + 3 artykuły (/blog)
- [x] FAQ: 10 pytań z akordeonem (/faq)
- [x] Hero carousel: 3 slajdy z auto-rotacją
- [x] Bestsellery: widget 8 produktów na stronie głównej
- [x] Newsletter popup (30s, AJAX, localStorage)
- [x] Footer: badge'e kurierów/płatności + linki FAQ/Blog
- [x] Numer konta bankowego: mBank 26 1140 2004 0000 3702 8407 7924
- [x] NIP/REGON/adres/telefon poprawione na wszystkich stronach CMS
- [x] Jarosław Cechowski (nie Jakub) wszędzie

## Wykonane (2026-06-22 — kategorie, UX, loga producentów)
- [x] Nowe kategorie L2: Smarowanie, Urządzenia warsztatowe (Warsztat), Osprzęt olejowy (Oleje i osprzęt), Płyny eksploatacyjne (Płyny)
- [x] Reorganizacja kategorii: przeniesione wysysarki/ściekarki/oczyszczarki/myjki z Filtry, zestawy smarowe/olejowe z Części zamienne
- [x] Skrócone nazwy kategorii w menu (Armatura, Liczniki, Pistolety, Zbiorniki, Części zamienne)
- [x] Nawigacja: font 12px, padding 12px (kompaktowe menu w 2 rzędach)
- [x] Karty produktów: flexbox wyrównuje ceny i przyciski na jednym poziomie
- [x] Opisy produktów: text-align:left (naprawiono 499 opisów z justify w bazie)
- [x] Product page layout: galeria 50% / info 50% (było ~65/35)
- [x] Logo producenta na stronie produktu (6 marek, oryginalne loga z oficjalnych stron)
- [x] Przypisano manufacturer do 770 produktów na podstawie nazwy

## Wykonane (2026-06-23 — zgodność prawna)
- [x] GPSR: zakładka "Bezpieczeństwo (GPSR)" z danymi 6 producentów (EU 2023/988)
- [x] CMS: Dostawa i płatności (tabele z kosztami, metody, dane do przelewu)
- [x] CMS: Odstąpienie od umowy (wzór formularza, procedura, wyjątki)
- [x] CMS: Reklamacje i gwarancja (rękojmia 2 lata, procedura, ODR)
- [x] CMS: Oświadczenie o dostępności (EAA, EU 2019/882)
- [x] Checkout: 3 obowiązkowe checkboxy (regulamin, polityka prywatności, odstąpienie)
- [x] Pole NIP/VAT ID w formularzu zamówienia (opcjonalne, B2B)
- [x] Link do platformy ODR w stopce
- [x] Footer: 3 kolumny (Informacje/Zakupy/Kontakt), NIP+REGON, nowe linki prawne
- [x] Loga brand-logos: pub/media/brand-logos/ (piusi.svg, raasm.jpg, fmt.png, adam-pumps.jpg, hifi-filter.jpg, gaiter.png)

## TODO
- [ ] Domena: transfer dopaliwa.pl w toku (Hostinger), DNS A → 72.62.1.240, SSL certbot
- [ ] Konfiguracja płatności: wpisać klucze API Autopay/Tpay/PayU w admin
- [ ] InPost: wpisać API token ShipX w admin
- [ ] GTM/GA4: wpisać Container ID i Measurement ID w admin
- [ ] reCAPTCHA: wpisać Site key + Secret key
- [ ] Import produktów z PIM TECHTOR (techtor-platform)
- [ ] 2FA: włączyć Magento_TwoFactorAuth po SSL
- [x] Fix DPD Client — SOAP API
- [ ] Base URL: zmienić z IP na dopaliwa.pl po DNS
- [ ] Sitemap → Google Search Console (po domenie)
- [ ] 515 produktów bez zdjęć (PIM)
- [ ] 504 produkty bez opisów (PIM)
- [ ] Moduł Omnibus (najniższa cena 30 dni) — potrzebny przy pierwszej promocji
- [ ] Przycisk "Odstąp od umowy" w panelu klienta (obowiązkowe od 19.06.2026)
- [ ] Double opt-in newsletter
