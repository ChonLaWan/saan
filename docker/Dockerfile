# Saan (สาน) container image: Debian 13 (trixie) + Apache 2.4 + PHP 8.4 (Debian packages) + cron.
# The signed Saan release packages are baked in under /opt/saan-dist; the first start copies the app into the data volume and
# installs it there (see rootfs/usr/local/bin/saan-entrypoint). Build context: this docker/ folder.
#   docker compose build                         packages from the project's release feed (version below, checked against the feed SHA-256)
#   put saan-core-*.zip + saan-mod-*.zip in packages/ before building to use local packages instead
FROM debian:trixie-slim

ARG SAAN_VERSION=0.9.0
ARG SAAN_RELEASE_BASE=https://saan.linuxthai.org/releases

ENV DEBIAN_FRONTEND=noninteractive \
    LANG=C.UTF-8 \
    TZ=Asia/Bangkok \
    SAAN_PHP_CLI=/usr/bin/php8.4

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      apache2 libapache2-mod-php8.4 \
      php8.4-cli php8.4-common php8.4-mysql php8.4-mbstring php8.4-intl php8.4-gd php8.4-zip php8.4-curl php8.4-xml php8.4-opcache php8.4-readline \
      cron ca-certificates curl openssl unzip tini tzdata mariadb-client jq procps \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite ssl headers expires >/dev/null \
 && a2dismod -f autoindex status >/dev/null \
 && a2dissite 000-default >/dev/null \
 && rm -f /etc/apache2/sites-available/default-ssl.conf \
 && ln -sf /usr/share/zoneinfo/Asia/Bangkok /etc/localtime && echo Asia/Bangkok > /etc/timezone

COPY rootfs/ /
COPY packages/ /tmp/saan-packages/

# strip CR in case the files were checked out on Windows, then fetch/verify the release and unpack the core payload
RUN find /usr/local/bin /etc/apache2/saan /etc/php/8.4/mods-available -type f -exec sed -i 's/\r$//' {} + \
 && chmod 0755 /usr/local/bin/saan-* \
 && phpenmod saan \
 && SAAN_VERSION="$SAAN_VERSION" SAAN_RELEASE_BASE="$SAAN_RELEASE_BASE" /usr/local/bin/saan-fetch-release /tmp/saan-packages /opt/saan-dist \
 && rm -rf /tmp/saan-packages \
 && php -r 'foreach(["pdo","pdo_mysql","mbstring","openssl","fileinfo","ctype","curl","dom","filter","hash","session","tokenizer","xml","xmlreader","intl","sodium","zip","gd"] as $e) if(!extension_loaded($e)){fwrite(STDERR,"[ERR] missing PHP extension $e\n");exit(1);} echo "[OK] PHP ".PHP_VERSION." extensions\n";'

EXPOSE 8443 9443
VOLUME ["/srv/saan", "/srv/saan-secrets"]
HEALTHCHECK --interval=30s --timeout=10s --start-period=30m --retries=3 CMD ["/usr/local/bin/saan-healthcheck"]
ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/saan-entrypoint"]
CMD ["serve"]
