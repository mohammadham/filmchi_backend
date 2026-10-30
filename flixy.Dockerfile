FROM webdevops/php-nginx:8.2

WORKDIR /app

COPY . /app

COPY docker/nginx/relay-upstream.conf /opt/docker/etc/nginx/vhost.common.d/11-relay-upstream.conf

RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg \
 && rm -rf /var/lib/apt/lists/* \
 && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader 2>&1 | tail -5 \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
 && chown -R application:application storage bootstrap/cache \
 && chmod -R ug+rwx storage bootstrap/cache \
 && ln -sf /app/storage/app/public /app/public/storage

ENV WEB_DOCUMENT_ROOT=/app/public

EXPOSE 80
