FROM php:8.4-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    bash \
    curl \
    git \
    libpng-dev \
    libxml2-dev \
    libzip-dev \
    postgresql-dev \
    oniguruma-dev \
    linux-headers \
    shadow

# Install PHP extensions
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    mbstring \
    xml \
    bcmath \
    pcntl \
    zip \
    opcache

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Configure non-root user matching standard host UID 1000
RUN groupadd -g 1000 laravel && \
    useradd -u 1000 -ms /bin/bash -g laravel laravel

USER laravel

EXPOSE 9000

CMD ["php-fpm"]

