#!/usr/bin/env bash

set -euo pipefail

unexpected=()

while IFS= read -r -d '' file; do
    # A local working tree may have unstaged deletions that are already part
    # of the change. CI checks a clean checkout, so tracked files there exist.
    [[ -e "$file" ]] || continue

    if [[ "$file" == public/* && "$file" == *.php && "$file" != "public/index.php" ]]; then
        unexpected+=("$file")
    fi
done < <(git ls-files -z -- public)

if (( ${#unexpected[@]} > 0 )); then
    printf 'Unexpected tracked PHP files under public/ (only public/index.php is allowed):\n' >&2
    printf ' - %s\n' "${unexpected[@]}" >&2
    exit 1
fi

git ls-files --error-unmatch -- public/index.php >/dev/null
printf 'Public PHP allow-list passed: public/index.php\n'
