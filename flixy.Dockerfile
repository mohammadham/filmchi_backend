FROM webdevops/php-nginx:8.2

WORKDIR /app

COPY . /app

RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader 2>&1 | tail -5 \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
 && chown -R application:application storage bootstrap/cache \
 && chmod -R ug+rwx storage bootstrap/cache \
 && ln -sf /app/storage/app/public /app/public/storage

ENV WEB_DOCUMENT_ROOT=/app/public

EXPOSE 80
