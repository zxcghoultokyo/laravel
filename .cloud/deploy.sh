#!/bin/bash

# Laravel Cloud Deploy Script

# Run migrations
php artisan migrate --force

# Seed database (uses updateOrCreate so safe to run multiple times)
php artisan db:seed --force

# Clear and rebuild caches
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "✅ Deploy completed successfully"
