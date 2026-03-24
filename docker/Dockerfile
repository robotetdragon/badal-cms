# =============================================================================
#  Badal — Development Dockerfile
#
#  PHP 8.2 + Apache + mod_rewrite + ffprobe
#  Pre-loaded with demo podcast "Limites" for contributors.
#
#  Usage:
#    docker compose up -d
#    Open http://localhost:8080
#    Admin: http://localhost:8080/admin/  (admin / admin)
# =============================================================================

FROM php:8.2-apache

# ffmpeg provides ffprobe (auto audio duration detection)
RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Allow .htaccess overrides in /var/www/html
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' \
    /etc/apache2/apache2.conf

# Copy CMS source
COPY . /var/www/html/

# Patch .htaccess RewriteBase for Docker (served at root, not /betapodcast/)
RUN sed -i 's|RewriteBase /[^/]*/|RewriteBase /|' /var/www/html/.htaccess

# Ensure writable directories exist
RUN mkdir -p /var/www/html/config \
             /var/www/html/content/episodes \
             /var/www/html/content/transcripts \
             /var/www/html/audio \
    && chown -R www-data:www-data /var/www/html/config \
                                  /var/www/html/content \
                                  /var/www/html/audio

# Entrypoint seeds demo data on first run
COPY docker/docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
