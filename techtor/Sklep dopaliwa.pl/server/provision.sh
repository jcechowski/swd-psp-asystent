#!/usr/bin/env bash
# =============================================================================
# dopaliwa.pl — Provisioning serwera Hostinger VPS KVM2
# Ubuntu 24.04 LTS | Magento 2.4.7
# =============================================================================
# Uzycie:  scp provision.sh root@<IP>:~ && ssh root@<IP> 'bash provision.sh'
# =============================================================================
set -euo pipefail

DOMAIN="dopaliwa.pl"
MAGENTO_DIR="/var/www/dopaliwa"
DB_NAME="dopaliwa"
DB_USER="dopaliwa_user"
DB_PASS=""  # <-- UZUPELNIC PRZED URUCHOMIENIEM
PHP_VERSION="8.3"
ES_VERSION="8"
ADMIN_EMAIL="biuro@techtor.pl"

# --- kolory ---
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log()  { echo -e "${GREEN}[OK]${NC} $1"; }
warn() { echo -e "${YELLOW}[!]${NC} $1"; }
err()  { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

# --- walidacja ---
[[ "$EUID" -eq 0 ]] || err "Uruchom jako root"
[[ -n "$DB_PASS" ]]  || err "Ustaw DB_PASS w skrypcie przed uruchomieniem"

# =============================================================================
# 1. AKTUALIZACJA SYSTEMU
# =============================================================================
log "Aktualizacja systemu..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get upgrade -y -qq

# =============================================================================
# 2. NARZEDZIA PODSTAWOWE
# =============================================================================
log "Instalacja narzedzi podstawowych..."
apt-get install -y -qq \
  curl wget git unzip gnupg2 software-properties-common \
  apt-transport-https ca-certificates lsb-release ufw fail2ban

# =============================================================================
# 3. FIREWALL (UFW)
# =============================================================================
log "Konfiguracja firewall..."
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp   # SSH
ufw allow 80/tcp   # HTTP
ufw allow 443/tcp  # HTTPS
ufw --force enable

# =============================================================================
# 4. PHP 8.3
# =============================================================================
log "Instalacja PHP ${PHP_VERSION}..."
add-apt-repository -y ppa:ondrej/php
apt-get update -qq
apt-get install -y -qq \
  php${PHP_VERSION}-fpm \
  php${PHP_VERSION}-cli \
  php${PHP_VERSION}-mysql \
  php${PHP_VERSION}-xml \
  php${PHP_VERSION}-gd \
  php${PHP_VERSION}-curl \
  php${PHP_VERSION}-intl \
  php${PHP_VERSION}-mbstring \
  php${PHP_VERSION}-bcmath \
  php${PHP_VERSION}-soap \
  php${PHP_VERSION}-zip \
  php${PHP_VERSION}-opcache \
  php${PHP_VERSION}-redis \
  php${PHP_VERSION}-xsl

# PHP-FPM tuning
cat > /etc/php/${PHP_VERSION}/fpm/pool.d/dopaliwa.conf << 'PHPPOOL'
[dopaliwa]
user = www-data
group = www-data
listen = /run/php/php-fpm-dopaliwa.sock
listen.owner = www-data
listen.group = www-data

pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 8
pm.max_requests = 500

; Magento wymaga duzo pamieci
php_admin_value[memory_limit] = 756M
php_admin_value[max_execution_time] = 300
php_admin_value[max_input_vars] = 10000
php_admin_value[upload_max_filesize] = 64M
php_admin_value[post_max_size] = 64M

; OPcache
php_admin_value[opcache.enable] = 1
php_admin_value[opcache.memory_consumption] = 512
php_admin_value[opcache.max_accelerated_files] = 60000
php_admin_value[opcache.validate_timestamps] = 0
php_admin_value[opcache.consistency_checks] = 0
php_admin_value[opcache.save_comments] = 1

; Realpath cache
php_admin_value[realpath_cache_size] = 10M
php_admin_value[realpath_cache_ttl] = 7200

; Sesje przez Redis
php_admin_value[session.save_handler] = redis
php_admin_value[session.save_path] = "tcp://127.0.0.1:6379/1"
PHPPOOL

# Wylacz domyslny pool
mv /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf.disabled 2>/dev/null || true

systemctl restart php${PHP_VERSION}-fpm
log "PHP ${PHP_VERSION} zainstalowany"

# =============================================================================
# 5. NGINX
# =============================================================================
log "Instalacja nginx..."
apt-get install -y -qq nginx

# Konfiguracja nginx — kopiowana osobno z config/nginx-dopaliwa.conf
# Na razie placeholder
systemctl enable nginx
log "nginx zainstalowany"

# =============================================================================
# 6. MySQL 8.0
# =============================================================================
log "Instalacja MySQL 8.0..."
apt-get install -y -qq mysql-server

# Konfiguracja MySQL
cat > /etc/mysql/mysql.conf.d/dopaliwa.cnf << 'MYSQLCNF'
[mysqld]
# InnoDB
innodb_buffer_pool_size = 2G
innodb_log_file_size = 256M
innodb_flush_log_at_trx_commit = 2
innodb_flush_method = O_DIRECT

# Query cache (wylaczony — Magento uzywa Redis)
query_cache_type = 0
query_cache_size = 0

# Connections
max_connections = 150
wait_timeout = 300

# Temp tables
tmp_table_size = 64M
max_heap_table_size = 64M

# Charset
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
MYSQLCNF

systemctl restart mysql

# Tworzenie bazy i uzytkownika
mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"
log "MySQL 8.0 — baza '${DB_NAME}' gotowa"

# =============================================================================
# 7. REDIS
# =============================================================================
log "Instalacja Redis..."
apt-get install -y -qq redis-server

# Redis config
sed -i 's/^# maxmemory .*/maxmemory 512mb/' /etc/redis/redis.conf
sed -i 's/^# maxmemory-policy .*/maxmemory-policy allkeys-lru/' /etc/redis/redis.conf

systemctl enable redis-server
systemctl restart redis-server
log "Redis zainstalowany (512MB, allkeys-lru)"

# =============================================================================
# 8. ELASTICSEARCH 8.x
# =============================================================================
log "Instalacja Elasticsearch ${ES_VERSION}..."
wget -qO - https://artifacts.elastic.co/GPG-KEY-elasticsearch | gpg --dearmor -o /usr/share/keyrings/elasticsearch-keyring.gpg
echo "deb [signed-by=/usr/share/keyrings/elasticsearch-keyring.gpg] https://artifacts.elastic.co/packages/${ES_VERSION}.x/apt stable main" \
  > /etc/apt/sources.list.d/elastic-${ES_VERSION}.x.list
apt-get update -qq
apt-get install -y -qq elasticsearch

# ES config — single node, minimalne zuzycie RAM
cat > /etc/elasticsearch/elasticsearch.yml << 'ESYML'
cluster.name: dopaliwa
node.name: node-1
path.data: /var/lib/elasticsearch
path.logs: /var/log/elasticsearch
network.host: 127.0.0.1
http.port: 9200
discovery.type: single-node
xpack.security.enabled: false
xpack.security.enrollment.enabled: false
ESYML

# JVM heap — 1GB (dla CX32 z 8GB RAM)
sed -i 's/^-Xms.*/-Xms1g/' /etc/elasticsearch/jvm.options
sed -i 's/^-Xmx.*/-Xmx1g/' /etc/elasticsearch/jvm.options

systemctl enable elasticsearch
systemctl start elasticsearch
log "Elasticsearch ${ES_VERSION} zainstalowany (1GB heap)"

# =============================================================================
# 9. COMPOSER
# =============================================================================
log "Instalacja Composer..."
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
log "Composer $(composer --version 2>/dev/null | head -1) zainstalowany"

# =============================================================================
# 10. CERTBOT (Let's Encrypt)
# =============================================================================
log "Instalacja Certbot..."
apt-get install -y -qq certbot python3-certbot-nginx
log "Certbot zainstalowany"

# =============================================================================
# 11. SUPERVISOR (dla Magento queue consumers)
# =============================================================================
log "Instalacja Supervisor..."
apt-get install -y -qq supervisor
systemctl enable supervisor
log "Supervisor zainstalowany"

# =============================================================================
# 12. UZYTKOWNIK MAGENTO
# =============================================================================
log "Tworzenie katalogu Magento..."
mkdir -p ${MAGENTO_DIR}
chown -R www-data:www-data ${MAGENTO_DIR}
log "Katalog ${MAGENTO_DIR} gotowy"

# =============================================================================
# 13. SWAP (2GB — zabezpieczenie dla Composer/deploy)
# =============================================================================
if [[ ! -f /swapfile ]]; then
  log "Tworzenie swap 2GB..."
  fallocate -l 2G /swapfile
  chmod 600 /swapfile
  mkswap /swapfile
  swapon /swapfile
  echo '/swapfile none swap sw 0 0' >> /etc/fstab
  log "Swap 2GB aktywny"
fi

# =============================================================================
# PODSUMOWANIE
# =============================================================================
echo ""
echo "============================================="
echo " PROVISIONING ZAKONCZONY"
echo "============================================="
echo ""
echo " PHP:            ${PHP_VERSION}-FPM"
echo " MySQL:          8.0 (baza: ${DB_NAME})"
echo " Redis:          aktywny (512MB)"
echo " Elasticsearch:  ${ES_VERSION}.x (1GB heap)"
echo " Nginx:          zainstalowany"
echo " Composer:       $(composer --version 2>/dev/null | awk '{print $3}')"
echo " Certbot:        zainstalowany"
echo " Supervisor:     zainstalowany"
echo " Firewall:       SSH + HTTP + HTTPS"
echo ""
echo " NASTEPNE KROKI:"
echo " 1. Skopiuj config/nginx-dopaliwa.conf -> /etc/nginx/sites-available/${DOMAIN}"
echo " 2. ln -s /etc/nginx/sites-available/${DOMAIN} /etc/nginx/sites-enabled/"
echo " 3. certbot --nginx -d ${DOMAIN} -d www.${DOMAIN} -m ${ADMIN_EMAIL} --agree-tos"
echo " 4. Uruchom install-magento.sh"
echo "============================================="
