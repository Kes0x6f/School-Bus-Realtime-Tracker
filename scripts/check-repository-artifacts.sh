#!/usr/bin/env bash

set -euo pipefail

unexpected=()

while IFS= read -r -d '' file; do
    case "$file" in
        .env.example)
            ;;
        .env|.env.*|*.log|*.pem|*.key)
            unexpected+=("$file")
            ;;
        bootstrap/cache/*|storage/framework/cache/*|storage/framework/sessions/*|storage/framework/views/*|storage/logs/*)
            [[ "${file##*/}" == ".gitignore" ]] || unexpected+=("$file")
            ;;
    esac
done < <(git ls-files -z)

if (( ${#unexpected[@]} > 0 )); then
    printf 'Unexpected tracked environment, secret, log, or cache artifacts:\n' >&2
    printf ' - %s\n' "${unexpected[@]}" >&2
    exit 1
fi

printf 'Repository artifact allow-list passed.\n'
