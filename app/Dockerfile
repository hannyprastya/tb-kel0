# app/Dockerfile (P7 slide 23): multi-stage. Tahap 1 hanya untuk Composer.
# P12 B.4: base image dikunci digest (tag tinggal label untuk manusia).
FROM composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction \
      --prefer-dist --optimize-autoloader

# tahap 2: image akhir yang dijalankan (slide 19)
FROM php:8.4-apache@sha256:3550aedd1c7bdfe26283c7b1550ef76509e6c2b1284aef814e6f2e325b7c9688
# konfigurasi PHP produksi: error tidak tampil ke pengguna, dicatat ke log
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
# ekstensi PostgreSQL untuk PDO
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev \
 && docker-php-ext-install pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*
# Apache mendengarkan di $PORT (bawaan 8080); semua alamat ke index.php
ENV PORT=8080
RUN sed -i 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
 && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' \
      /etc/apache2/sites-available/000-default.conf \
 && echo 'FallbackResource /index.php' > /etc/apache2/conf-enabled/fallback.conf
# P13 C.4 (temuan T-02): header tanpa nomor versi Apache dan PHP
RUN printf '%s\n' 'ServerTokens Prod' 'ServerSignature Off' \
      > /etc/apache2/conf-enabled/sembunyi-versi.conf \
 && echo 'expose_php = Off' > "$PHP_INI_DIR/conf.d/sembunyi-versi.ini"
# ambil hasil tahap 1 saja (folder vendor/), lalu kode (paling akhir)
COPY --from=vendor /app/vendor /var/www/html/vendor
COPY . /var/www/html/
EXPOSE 8080
# P13 C.1 (slide 8): jumlah worker Apache dari hitungan; bisa diubah lewat env
ENV WORKERS=4
RUN printf '%s\n' 'KeepAlive Off' 'MaxRequestWorkers ${WORKERS}' \
      > /etc/apache2/conf-enabled/worker.conf
# P13 C.4 hardening (slide 27): Apache berjalan sebagai www-data, bukan root
USER www-data
