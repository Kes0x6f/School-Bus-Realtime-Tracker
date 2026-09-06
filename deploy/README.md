# Deployment hardening

The web server document root must be the repository's `public/` directory.
Laravel's front controller is `public/index.php`; compiled Blade views belong
under `storage/framework/views` and must not be served from the web root.

- Apache deployments should keep `public/.htaccess` enabled with
  `AllowOverride All` (or copy its PHP deny rules into the virtual host).
- nginx/PHP-FPM deployments can adapt [`nginx.conf`](nginx.conf); update the
  `root` and `fastcgi_pass` values for the deployment environment.
- Keep `storage/framework/views` writable and run `php artisan view:clear`
  after removing stale generated artifacts.
