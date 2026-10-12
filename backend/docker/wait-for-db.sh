#!/bin/sh
# Wait for database to be ready
echo "Waiting for database..."
while ! php -r "
require 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
try {
    \DB::connection()->getPdo();
    exit(0);
} catch (\Exception \$e) {
    exit(1);
}
"; do
    sleep 2
done
echo "Database ready!"
exec php artisan reverb:start --host=0.0.0.0 --port=8083