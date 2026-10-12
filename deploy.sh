#!/bin/bash
# Production Deployment Script for Laravel Letter Receipt System
# Usage: ./deploy.sh [environment]
# Example: ./deploy.sh production

set -e

ENVIRONMENT=${1:-production}
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$PROJECT_DIR/letter-receipt-system-php/backend"
COMPOSE_FILE="$PROJECT_DIR/letter-receipt-system-php/docker-compose.production.yml"

echo "=========================================="
echo "Deploying Laravel Letter Receipt System"
echo "Environment: $ENVIRONMENT"
echo "=========================================="

# Check for required files
if [ ! -f "$BACKEND_DIR/.env" ]; then
    echo "ERROR: $BACKEND_DIR/.env not found!"
    echo "Please copy $BACKEND_DIR/.env.production to $BACKEND_DIR/.env and fill in all values."
    exit 1
fi

# Verify required environment variables
source "$BACKEND_DIR/.env"
REQUIRED_VARS=("APP_KEY" "DB_PASSWORD" "DB_ROOT_PASSWORD" "REVERB_APP_KEY" "REVERB_APP_SECRET" "APP_BOOTSTRAP_ADMIN_PASSWORD")
for var in "${REQUIRED_VARS[@]}"; do
    if [ -z "${!var}" ] || [ "${!var}" = "your-"*"here" ]; then
        echo "ERROR: Required variable $var is not set in .env"
        exit 1
    fi
done

echo "✓ Environment variables validated"

# Build and start containers
echo "Building Docker images..."
docker compose -f "$COMPOSE_FILE" build --no-cache

echo "Starting services..."
docker compose -f "$COMPOSE_FILE" up -d

# Wait for database to be ready
echo "Waiting for database..."
sleep 10

# Run migrations
echo "Running database migrations..."
docker compose -f "$COMPOSE_FILE" exec -T app php artisan migrate --force

# Seed admin user if needed
echo "Seeding admin user..."
docker compose -f "$COMPOSE_FILE" exec -T app php artisan db:seed --class=DatabaseSeeder --force

# Clear and cache config
echo "Optimizing application..."
docker compose -f "$COMPOSE_FILE" exec -T app php artisan config:cache
docker compose -f "$COMPOSE_FILE" exec -T app php artisan route:cache
docker compose -f "$COMPOSE_FILE" exec -T app php artisan view:cache
docker compose -f "$COMPOSE_FILE" exec -T app php artisan event:cache

# Health checks
echo "Running health checks..."
sleep 5
if curl -f http://localhost:8082/up > /dev/null 2>&1; then
    echo "✓ API health check passed"
else
    echo "✗ API health check failed"
    docker compose -f "$COMPOSE_FILE" logs app
    exit 1
fi

echo "=========================================="
echo "Deployment completed successfully!"
echo "API: http://localhost:8082"
echo "WebSocket: ws://localhost:8083"
echo "=========================================="