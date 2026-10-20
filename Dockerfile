FROM php:8.4-cli

# Instalar dependencias del sistema necesarias para Laravel y Composer
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Instalar extensiones PHP necesarias
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    dom

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copiar el código del proyecto
COPY . /var/www/html

# Instalar dependencias de producción
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Dar permisos a las carpetas de storage y cache
RUN chmod -R 775 storage bootstrap/cache

# Exponer el puerto que asigna Railway (por defecto 8080)
EXPOSE 8080

# Comando para iniciar la aplicación usando el servidor embebido de PHP dirigido a /public
CMD php artisan config:clear && php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8080
