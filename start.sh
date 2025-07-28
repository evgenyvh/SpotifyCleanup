#!/bin/bash
sed -i “s/80/${PORT}/g” /etc/apache2/sites-available/000-default.conf
sed -i “s/80/${PORT}/g” /etc/apache2/ports.conf
apache2-foreground