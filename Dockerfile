FROM php:8.2-apache

# Enable Apache mod_rewrite

RUN a2enmod rewrite

# Copy the app

COPY index.php /var/www/html/

# Listen on Render’s port

RUN sed -i ‘s/Listen 80/Listen ${PORT}/g’ /etc/apache2/ports.conf &&   
sed -i ‘s/:80/:${PORT}/g’ /etc/apache2/sites-available/000-default.conf

CMD [“sh”, “-c”, “apache2-foreground”]