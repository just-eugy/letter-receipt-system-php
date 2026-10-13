@echo off
REM Production Deployment Script for Laravel Letter Receipt System (Windows)
REM Usage: deploy.bat [environment]
REM Example: deploy.bat production

setlocal enabledelayedexpansion

set ENVIRONMENT=%1
if "%ENVIRONMENT%"=="" set ENVIRONMENT=production

set PROJECT_DIR=%~dp0
set BACKEND_DIR=%PROJECT_DIR%letter-receipt-system-php\backend
set COMPOSE_FILE=%PROJECT_DIR%letter-receipt-system-php\docker-compose.production.yml

echo ==========================================
echo Deploying Laravel Letter Receipt System
echo Environment: %ENVIRONMENT%
echo ==========================================

REM Check for required .env file
if not exist "%BACKEND_DIR%\.env" (
    echo ERROR: %BACKEND_DIR%\.env not found!
    echo Please copy %BACKEND_DIR%\.env.production to %BACKEND_DIR%\.env and fill in all values.
    exit /b 1
)

echo ✓ Environment file found

REM Build and start containers
echo Building Docker images...
docker compose -f "%COMPOSE_FILE%" build --no-cache

echo Starting services...
docker compose -f "%COMPOSE_FILE%" up -d

REM Wait for database to be ready
echo Waiting for database...
timeout /t 15 /nobreak >nul

REM Run migrations
echo Running database migrations...
docker compose -f "%COMPOSE_FILE%" exec -T app php artisan migrate --force

REM Seed admin user if needed
echo Seeding admin user...
docker compose -f "%COMPOSE_FILE%" exec -T app php artisan db:seed --class=DatabaseSeeder --force

REM Clear and cache config
echo Optimizing application...
docker compose -f "%COMPOSE_FILE%" exec -T app php artisan config:cache
docker compose -f "%COMPOSE_FILE%" exec -T app php artisan route:cache
docker compose -f "%COMPOSE_FILE%" exec -T app php artisan view:cache
docker compose -f "%COMPOSE_FILE%" exec -T app php artisan event:cache

REM Health checks
echo Running health checks...
timeout /t 5 /nobreak >nul
curl -f http://localhost:8082/up >nul 2>&1
if %errorlevel% equ 0 (
    echo ✓ API health check passed
) else (
    echo ✗ API health check failed
    docker compose -f "%COMPOSE_FILE%" logs app
    exit /b 1
)

echo ==========================================
echo Deployment completed successfully!
echo API: http://localhost:8082
echo WebSocket: ws://localhost:8083
echo ==========================================