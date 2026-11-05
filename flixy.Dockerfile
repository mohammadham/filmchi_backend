FROM webdevops/php-nginx:8.2

WORKDIR /app

# ffmpeg lives in its own layer: it is a ~400MB apt install and only changes
# when this line does, so code deploys never pay for it again.
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg \
 && rm -rf /var/lib/apt/lists/*

# Dependencies are installed before the source is copied, so editing app code
# reuses this layer instead of rebuilding (and re-caching) the whole vendor
# tree on every deploy. The composer cache is thrown away in the same layer.
COPY composer.json composer.lock ./

RUN COMPOSER_CACHE_DIR=/tmp/composer-cache \
      composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader \
 && rm -rf /tmp/composer-cache

COPY docker/nginx/relay-upstream.conf /opt/docker/etc/nginx/vhost.common.d/11-relay-upstream.conf

COPY . /app

# dump-autoload regenerates the autoloader for the copied source and triggers
# the project's post-autoload-dump scripts (package:discover).
RUN composer dump-autoload --optimize --no-dev --no-interaction \
 && mkdir -p storage/app/public storage/framework/cache storage/framework/sessions \
      storage/framework/views storage/logs \
 && chown -R application:application storage bootstrap/cache \
 && chmod -R ug+rwx storage bootstrap/cache \
 && ln -sfn /app/storage/app/public /app/public/storage

ENV WEB_DOCUMENT_ROOT=/app/public

EXPOSE 80
