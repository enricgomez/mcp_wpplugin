#!/bin/bash
# Migración test -> enricgomez.com (log en /root/backup-enricgomez-2026-11-09/migra.log)
set -u
B=/root/backup-enricgomez-2026-11-09
D=/var/www/vhosts/egserver.cloud/test-enricgomez.com.egserver.cloud
V=/var/www/vhosts/enricgomez.com
NEW=$V/httpdocs-new; OLD=$V/httpdocs-old-20261109
WP() { sudo -u enricgomez HOME=/tmp /opt/plesk/php/8.5/bin/php -d memory_limit=1024M /usr/local/bin/wp --path=$V/httpdocs "$@" 2>&1 | grep -v -iE "deprecat|^PHP Notice"; }
echo "== $(date) inicio"
[ -e $OLD ] && { echo "YA EXISTE $OLD, abortando"; exit 1; }
# 1) BD de test
TPW=$(php -r '$s=file_get_contents($argv[1]); preg_match("/DB_PASSWORD[\x27\"]\s*,\s*[\x27\"](.*?)[\x27\"]\s*\)/",$s,$m); echo $m[1];' $D/wp-config.php)
MYSQL_PWD="$TPW" mysqldump -u egwu_d36e31 --single-transaction --default-character-set=utf8mb4 egweb_t1 > $B/test-egweb_t1.sql || { echo FALLO dump test; exit 1; }
gzip -k $B/test-egweb_t1.sql; echo "dump test $(du -h $B/test-egweb_t1.sql | cut -f1)"
# 2) ficheros
rsync -a --exclude='/eg-x-*' --exclude='/rx-p54.php' --exclude='/rx*.php' --exclude='/wp-content/eg-p54-tmp/' --exclude='/wp-content/cache/' --exclude='/wp-content/upgrade/' --exclude='/wp-content/debug.log' $D/ $NEW/ || { echo FALLO rsync; exit 1; }
rsync -a --ignore-existing --exclude='*.php' --exclude='*.phtml' --exclude='*.phar' --exclude='.htaccess' --exclude='/cache/' $V/httpdocs/wp-content/uploads/ $NEW/wp-content/uploads/
for f in google5a7e8f40d06f9ebe.html 8c3242fe77098566490961386ae29948.html firma-gmail push-notifications; do cp -a $V/httpdocs/$f $NEW/ 2>/dev/null; done
[ -e $NEW/favicon.ico ] || cp -a $V/httpdocs/favicon.ico $NEW/
cp $NEW/wp-config.php $B/wp-config.test.php
php $B/cfg.php $V/httpdocs/wp-config.php $NEW/wp-config.php || exit 1
chown -R enricgomez:psacln $NEW; chown enricgomez:psaserv $NEW; chmod 755 $NEW
find $NEW -type d ! -perm 755 -exec chmod 755 {} + ; find $NEW -type f ! -perm 644 -exec chmod 644 {} +
chmod 600 $NEW/wp-config.php
echo "ficheros $(du -sh $NEW | cut -f1)"
# 3) importar tablas eg6153_ en dbegstudio (las l99US_ se quedan)
OPW=$(php -r '$s=file_get_contents($argv[1]); preg_match("/DB_PASSWORD[\x27\"]\s*,\s*[\x27\"](.*?)[\x27\"]\s*\)/",$s,$m); echo $m[1];' $V/httpdocs/wp-config.php)
EX=$(MYSQL_PWD="$OPW" mysql -u eg_egomez -N -e "show tables like 'eg6153\_%'" dbegstudio | wc -l); echo "tablas eg6153 previas: $EX"
MYSQL_PWD="$OPW" mysql -u eg_egomez --default-character-set=utf8mb4 dbegstudio < $B/test-egweb_t1.sql || { echo FALLO import; exit 1; }
echo "import ok"
# 4) cambio de carpeta
mv $V/httpdocs $OLD && mv $NEW $V/httpdocs || { echo FALLO mv; exit 1; }
echo "== $(date) carpeta cambiada"
# 5) URLs y rutas
WP search-replace "$D" "$V/httpdocs" --all-tables-with-prefix --report-changed-only --format=count
WP search-replace 'https://test-enricgomez.com.egserver.cloud' 'https://www.enricgomez.com' --all-tables-with-prefix --report-changed-only --format=count
WP search-replace 'test-enricgomez.com.egserver.cloud' 'www.enricgomez.com' --all-tables-with-prefix --report-changed-only --format=count
WP search-replace 'http://www.enricgomez.com' 'https://www.enricgomez.com' --all-tables-with-prefix --report-changed-only --format=count
grep -rlI 'test-enricgomez' $V/httpdocs/wp-content/themes $V/httpdocs/wp-content/mu-plugins $V/httpdocs/wp-config.php 2>/dev/null | while read f; do sed -i 's#https://test-enricgomez.com.egserver.cloud#https://www.enricgomez.com#g; s#test-enricgomez.com.egserver.cloud#www.enricgomez.com#g' "$f"; echo "fich: $f"; done
WP option update blog_public 1
WP rewrite flush
WP elementor flush-css
echo "home=$(WP option get home) blog_public=$(WP option get blog_public)"
echo "== $(date) fin"
