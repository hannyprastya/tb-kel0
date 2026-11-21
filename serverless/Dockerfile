# Pakai: docker build -o . .  -> fungsi.zip (Lambda provided.al2023, x86_64)
# Pembungkus fungsi P14 F, BUKAN image aplikasi.
FROM --platform=linux/amd64 bref/php-84:3 AS bref

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

FROM alpine:3.22 AS paket
RUN apk add --no-cache zip
WORKDIR /pkg
COPY --from=bref /opt/bin/php bin/php
COPY --from=bref /opt/lib lib
COPY --from=bref /opt/bref bref
COPY --from=vendor /app/vendor vendor
COPY bootstrap *.php ./
# bref.ini menunjuk /opt (lokasi layer); di sini runtime ada di /var/task.
# sed kedua membuang CRLF (akhir baris Windows) dari bootstrap.
RUN mkdir -p php/conf.d \
 && sed 's#/opt/bref/extensions#/var/task/bref/extensions#' \
      bref/etc/php/conf.d/bref.ini > php/conf.d/bref.ini \
 && sed -i 's/\r$//' bootstrap \
 && chmod +x bootstrap bin/php && zip -qry /fungsi.zip .

FROM scratch
COPY --from=paket /fungsi.zip /
