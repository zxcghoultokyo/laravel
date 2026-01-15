#!/bin/bash

# Laravel Cloud Deploy Script

# Run migrations
php artisan migrate --force

# Seed database (uses updateOrCreate so safe to run multiple times)
php artisan db:seed --force

# Assign existing data to super admin's tenant (safe to run multiple times)
php artisan tenant:assign-existing stovburtm@gmail.com || true

# Clear and rebuild caches
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "✅ Deploy completed successfully"
