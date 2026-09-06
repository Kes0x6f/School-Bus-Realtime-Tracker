#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repository_root"

bash scripts/check-public-php.sh

mkdir -p \
    bootstrap/cache \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

php artisan view:clear --no-interaction
php artisan view:cache --no-interaction

php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$compiled = realpath((string) config("view.compiled"));
$storage = realpath(storage_path());
$expectedStorage = realpath(base_path("storage"));
$expected = realpath(base_path("storage/framework/views"));
$public = realpath(public_path());

if ($storage === false || $expectedStorage === false || $storage !== $expectedStorage) {
    fwrite(STDERR, "The Laravel storage root must resolve to the repository storage directory.\n");
    exit(1);
}

if ($compiled === false || $expected === false || $compiled !== $expected) {
    fwrite(STDERR, "Compiled views must resolve to storage/framework/views.\n");
    exit(1);
}

if ($public !== false && str_starts_with($compiled, $public.DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "Compiled views resolved inside the public web root.\n");
    exit(1);
}
'

unexpected_public_php=()
while IFS= read -r -d '' file; do
    [[ "$file" == "public/index.php" ]] || unexpected_public_php+=("$file")
done < <(find public -type f -name '*.php' -print0)

if (( ${#unexpected_public_php[@]} > 0 )); then
    printf 'View compilation created unexpected PHP files under public/:\n' >&2
    printf ' - %s\n' "${unexpected_public_php[@]}" >&2
    exit 1
fi

if ! find storage/framework/views -type f -name '*.php' -print -quit | grep -q .; then
    printf 'View compilation did not create PHP files under storage/framework/views.\n' >&2
    exit 1
fi

printf 'Compiled views are confined to storage/framework/views.\n'
