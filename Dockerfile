FROM php:8.2-apache

# Enable Apache mod_rewrite

RUN a2enmod rewrite

# Copy files

COPY index.php /var/www/html/
COPY start.sh /start.sh

# Make start script executable

RUN chmod +x /start.sh

# Run the start script

CMD /start.sh