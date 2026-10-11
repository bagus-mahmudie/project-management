web: php artisan serve --host=0.0.0.0 --port=$PORT
worker: php artisan queue:work --queue=daily-reports,default --sleep=1 --tries=3 --timeout=300
scheduler: php artisan schedule:work
