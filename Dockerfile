FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        libicu-dev \
        unzip \
        git \
    && docker-php-ext-install pdo_pgsql pgsql intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /app

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "web", "router.php"]
