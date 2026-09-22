set -euo pipefail
cd /home/mateusz/flota/gpt-heic-format-run
export PATH=/opt/kuking-php-8.4-avif/bin:$PATH
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439 DB_DATABASE=kuking_flota_gpt-heic-format DB_USERNAME=kuking DB_PASSWORD=kuking
export APP_ENV=local APP_DEBUG=false APP_URL=http://192.168.0.242:8119
export SESSION_SECURE_COOKIE=false SESSION_DRIVER=file MAIL_MAILER=array QUEUE_CONNECTION=sync
export KUKING_MEDIA_DISK=public KUKING_MEDIA_PUBLIC_DISK=public
npm run build
php .codex/heic-119/phone-setup.php
exec php -d upload_max_filesize=16M -d post_max_size=70M -S 0.0.0.0:8118 -t public .codex/heic-119/phone-router.php