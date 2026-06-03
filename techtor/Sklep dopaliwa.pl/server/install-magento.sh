#!/usr/bin/env bash
# =============================================================================
# dopaliwa.pl — Instalacja Magento 2.4.7 Open Source
# =============================================================================
# Wymagania:
#   - provision.sh juz uruchomiony
#   - Klucze repo.magento.com (public + private) — zarejestruj na marketplace.magento.com
#   - nginx vhost skonfigurowany i SSL aktywne
# =============================================================================
set -euo pipefail

MAGENTO_DIR="/var/www/dopaliwa"
DOMAIN="dopaliwa.pl"
DB_NAME="dopaliwa"
DB_USER="dopaliwa_user"
DB_PASS=""           # <-- UZUPELNIC (to samo co w provision.sh)
ADMIN_USER="admin"
ADMIN_PASS=""        # <-- UZUPELNIC (min. 7 znakow, litera + cyfra + znak specjalny)
ADMIN_EMAIL="biuro@techtor.pl"
ADMIN_FIRSTNAME="Jakub"
ADMIN_LASTNAME="Cechowski"

# Klucze Magento Marketplace (repo.magento.com)
COMPOSER_PUBLIC_KEY=""   # <-- UZUPELNIC
COMPOSER_PRIVATE_KEY=""  # <-- UZUPELNIC

PHP_VERSION="8.3"

GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m'
log() { echo -e "${GREEN}[OK]${NC} $1"; }
err() { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

# --- walidacja ---
[[ "$EUID" -eq 0 ]] || err "Uruchom jako root"
[[ -n "$DB_PASS" ]]             || err "Ustaw DB_PASS"
[[ -n "$ADMIN_PASS" ]]          || err "Ustaw ADMIN_PASS"
[[ -n "$COMPOSER_PUBLIC_KEY" ]]  || err "Ustaw COMPOSER_PUBLIC_KEY (marketplace.magento.com)"
[[ -n "$COMPOSER_PRIVATE_KEY" ]] || err "Ustaw COMPOSER_PRIVATE_KEY (marketplace.magento.com)"

# =============================================================================
# 1. KONFIGURACJA COMPOSER AUTH
# =============================================================================
log "Konfiguracja Composer auth dla repo.magento.com..."
mkdir -p /root/.config/composer
cat > /root/.config/composer/auth.json << AUTHEOF
{
    "http-basic": {
        "repo.magento.com": {
            "username": "${COMPOSER_PUBLIC_KEY}",
            "password": "${COMPOSER_PRIVATE_KEY}"
        }
    }
}
AUTHEOF

# =============================================================================
# 2. INSTALACJA MAGENTO VIA COMPOSER
# =============================================================================
log "Pobieranie Magento 2.4.7 via Composer (moze trwac kilka minut)..."
rm -rf ${MAGENTO_DIR}
composer create-project \
  --repository-url=https://repo.magento.com/ \
  magento/project-community-edition=2.4.7-p3 \
  ${MAGENTO_DIR} \
  --no-interaction

cd ${MAGENTO_DIR}

# =============================================================================
# 3. SETUP INSTALL
# =============================================================================
log "Uruchamianie bin/magento setup:install..."
bin/magento setup:install \
  --base-url="https://${DOMAIN}/" \
  --base-url-secure="https://${DOMAIN}/" \
  --use-secure=1 \
  --use-secure-admin=1 \
  --db-host=localhost \
  --db-name="${DB_NAME}" \
  --db-user="${DB_USER}" \
  --db-password="${DB_PASS}" \
  --admin-firstname="${ADMIN_FIRSTNAME}" \
  --admin-lastname="${ADMIN_LASTNAME}" \
  --admin-email="${ADMIN_EMAIL}" \
  --admin-user="${ADMIN_USER}" \
  --admin-password="${ADMIN_PASS}" \
  --language=pl_PL \
  --currency=PLN \
  --timezone=Europe/Warsaw \
  --use-rewrites=1 \
  --backend-frontname=techtor_admin \
  --search-engine=elasticsearch8 \
  --elasticsearch-host=localhost \
  --elasticsearch-port=9200 \
  --elasticsearch-index-prefix=dopaliwa \
  --cache-backend=redis \
  --cache-backend-redis-server=127.0.0.1 \
  --cache-backend-redis-port=6379 \
  --cache-backend-redis-db=0 \
  --page-cache=redis \
  --page-cache-redis-server=127.0.0.1 \
  --page-cache-redis-port=6379 \
  --page-cache-redis-db=2 \
  --session-save=redis \
  --session-save-redis-host=127.0.0.1 \
  --session-save-redis-port=6379 \
  --session-save-redis-db=1 \
  --cleanup-database

log "Magento zainstalowany"

# =============================================================================
# 4. UPRAWNIENIA
# =============================================================================
log "Ustawianie uprawnien..."
find ${MAGENTO_DIR}/var ${MAGENTO_DIR}/generated ${MAGENTO_DIR}/pub/static ${MAGENTO_DIR}/pub/media \
  -type d -exec chmod 775 {} \;
find ${MAGENTO_DIR}/var ${MAGENTO_DIR}/generated ${MAGENTO_DIR}/pub/static ${MAGENTO_DIR}/pub/media \
  -type f -exec chmod 664 {} \;
chown -R www-data:www-data ${MAGENTO_DIR}
chmod u+x ${MAGENTO_DIR}/bin/magento

# =============================================================================
# 5. TRYB PRODUKCYJNY
# =============================================================================
log "Przelaczanie na tryb production..."
cd ${MAGENTO_DIR}
sudo -u www-data bin/magento deploy:mode:set production --skip-compilation
sudo -u www-data bin/magento setup:di:compile
sudo -u www-data bin/magento setup:static-content:deploy pl_PL en_US -f
sudo -u www-data bin/magento cache:flush

# =============================================================================
# 6. CRONTAB
# =============================================================================
log "Konfiguracja crontab Magento..."
sudo -u www-data bin/magento cron:install

# =============================================================================
# 7. SUPERVISOR — QUEUE CONSUMERS
# =============================================================================
log "Konfiguracja Supervisor dla Magento consumers..."
cat > /etc/supervisor/conf.d/magento-consumers.conf << 'SUPCONF'
[program:magento-consumers]
command=/usr/bin/php /var/www/dopaliwa/bin/magento queue:consumers:start
process_name=%(program_name)s_%(process_num)02d
numprocs=2
directory=/var/www/dopaliwa
autostart=true
autorestart=true
user=www-data
stdout_logfile=/var/log/supervisor/magento-consumers.log
stderr_logfile=/var/log/supervisor/magento-consumers-err.log
SUPCONF

supervisorctl reread
supervisorctl update

# =============================================================================
# 8. DISABLE TWO-FACTOR AUTH (dev convenience — wlaczyc na produkcji!)
# =============================================================================
log "Wylaczanie 2FA (wlacz przed go-live!)..."
cd ${MAGENTO_DIR}
sudo -u www-data bin/magento module:disable Magento_AdminAdobeImsTwoFactorAuth Magento_TwoFactorAuth
sudo -u www-data bin/magento setup:upgrade
sudo -u www-data bin/magento cache:flush

# =============================================================================
# 9. POLSKIE TLUMACZENIE
# =============================================================================
log "Instalacja polskiego pakietu jezykowego..."
cd ${MAGENTO_DIR}
composer require snowdog/language-pl_pl --no-interaction
sudo -u www-data bin/magento setup:upgrade
sudo -u www-data bin/magento setup:static-content:deploy pl_PL -f
sudo -u www-data bin/magento cache:flush

# =============================================================================
# PODSUMOWANIE
# =============================================================================
ADMIN_URI=$(cd ${MAGENTO_DIR} && sudo -u www-data bin/magento info:adminuri 2>/dev/null | grep -oP '/\S+')

echo ""
echo "============================================="
echo " MAGENTO 2 ZAINSTALOWANY"
echo "============================================="
echo ""
echo " URL sklepu:     https://${DOMAIN}/"
echo " Panel admina:   https://${DOMAIN}${ADMIN_URI}"
echo " Admin user:     ${ADMIN_USER}"
echo " Admin email:    ${ADMIN_EMAIL}"
echo " Baza danych:    ${DB_NAME}"
echo " Tryb:           production"
echo " Cache:          Redis (db0=cache, db1=session, db2=FPC)"
echo " Search:         Elasticsearch 8 (index: dopaliwa)"
echo " Jezyk:          pl_PL"
echo " Waluta:         PLN"
echo ""
echo " UWAGA: 2FA jest wylaczone! Wlacz przed go-live:"
echo "   bin/magento module:enable Magento_TwoFactorAuth"
echo ""
echo " NASTEPNE KROKI:"
echo " 1. Zaloguj sie do admina i sprawdz dzialanie"
echo " 2. Skopiuj moduly z app/code/Techtor/"
echo " 3. bin/magento setup:upgrade && bin/magento cache:flush"
echo "============================================="
