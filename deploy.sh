#!/bin/bash

# ApexV2 Deployment Script for ksipl.apextime.in
# Run this script on your VPS server

set -e

DOMAIN="ksipl.apextime.in"
EMAIL="admin@apextime.in"  # Change to your email for Let's Encrypt notifications

echo "=========================================="
echo "ApexV2 Deployment to $DOMAIN"
echo "=========================================="

# Step 1: Clone repository (if not already cloned)
if [ ! -d "ApexV2" ]; then
    echo "[1/7] Cloning repository..."
    git clone https://github.com/alpesh15gb/ApexV2.git
    cd ApexV2
else
    echo "[1/7] Repository exists, pulling latest..."
    cd ApexV2
    git pull origin main
fi

# Step 2: Create required directories
echo "[2/7] Creating directories..."
mkdir -p docker/ssl
mkdir -p docker/certbot/conf
mkdir -p docker/certbot/www
mkdir -p storage/logs
chmod -R 775 storage bootstrap/cache

# Step 3: Copy environment file
echo "[3/7] Setting up environment..."
if [ ! -f ".env" ]; then
    cp .env.docker .env
    echo "Created .env from .env.docker"
    echo ">>> IMPORTANT: Edit .env with your actual database passwords!"
fi

# Step 4: Use init nginx config (HTTP only for first run)
echo "[4/7] Setting up nginx for SSL certificate generation..."
cp docker/nginx-init.conf docker/nginx-prod.conf.bak
cp docker/nginx-init.conf docker/nginx-prod.conf

# Step 5: Build and start containers
echo "[5/7] Building and starting containers..."
docker-compose down 2>/dev/null || true
docker-compose build --no-cache
docker-compose up -d app db redis webserver

# Wait for containers to be ready
echo "Waiting for containers to start..."
sleep 10

# Step 6: Generate SSL certificate
echo "[6/7] Generating SSL certificate with Let's Encrypt..."
docker-compose run --rm certbot certonly \
    --webroot \
    --webroot-path=/var/www/certbot \
    --email $EMAIL \
    --agree-tos \
    --no-eff-email \
    -d $DOMAIN

# Step 7: Switch to production nginx config with SSL
echo "[7/7] Enabling HTTPS..."
# Restore the production SSL config
cat > docker/nginx-prod.conf << 'NGINX_CONF'
server {
    listen 80;
    listen [::]:80;
    server_name ksipl.apextime.in;

    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name ksipl.apextime.in;

    ssl_certificate /etc/letsencrypt/live/ksipl.apextime.in/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/ksipl.apextime.in/privkey.pem;
    
    ssl_session_timeout 1d;
    ssl_session_cache shared:SSL:50m;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;

    add_header Strict-Transport-Security "max-age=63072000" always;

    root /var/www/public;
    index index.php index.html;

    charset utf-8;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~* \.(jpg|jpeg|png|gif|ico|css|js|woff|woff2|ttf|svg|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_connect_timeout 60;
        fastcgi_send_timeout 300;
        fastcgi_read_timeout 300;
    }

    location ~ /\. {
        deny all;
    }

    location /health {
        access_log off;
        return 200 "healthy\n";
        add_header Content-Type text/plain;
    }
}
NGINX_CONF

# Restart nginx with SSL config
docker-compose restart webserver

# Run Laravel setup
echo "Running Laravel setup..."
docker-compose exec -T app php artisan key:generate --force 2>/dev/null || true
docker-compose exec -T app php artisan migrate --force
docker-compose exec -T app php artisan config:cache
docker-compose exec -T app php artisan route:cache
docker-compose exec -T app php artisan view:cache

echo ""
echo "=========================================="
echo "✅ Deployment Complete!"
echo "=========================================="
echo ""
echo "Your app is now live at: https://$DOMAIN"
echo ""
echo "Next steps:"
echo "1. Test database sync: docker-compose exec app php artisan sync:punch-logs --test"
echo "2. Run initial sync: docker-compose exec app php artisan sync:punch-logs"
echo ""
echo "Logs: docker-compose logs -f app"
echo ""
