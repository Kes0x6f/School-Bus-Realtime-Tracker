# Deployment hardening

The web server document root must be the repository's `public/` directory.
Laravel's front controller is `public/index.php`; compiled Blade views belong
under `storage/framework/views` and must not be served from the web root. The
application pins the storage root during bootstrap and pins the compiled view
location in `config/view.php` instead of accepting host-specific
`LARAVEL_STORAGE_PATH` or `VIEW_COMPILED_PATH` overrides.

- Apache deployments should keep `public/.htaccess` enabled with
  `AllowOverride All` (or copy its PHP deny rules into the virtual host).
- nginx/PHP-FPM deployments can adapt [`nginx.conf`](nginx.conf); update the
  `root` and `fastcgi_pass` values for the deployment environment.
- Keep `storage/framework/views` writable and run `php artisan view:clear`
  after removing stale generated artifacts.
- Run `bash scripts/verify-view-compilation.sh` after installing dependencies
  to create the runtime directories, compile views, and verify that generated
  PHP remains outside `public/`.
