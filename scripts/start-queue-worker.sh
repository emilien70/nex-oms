#!/usr/bin/env bash
set -euo pipefail

root="$(cd -- "$(dirname -- "$0")/.." && pwd -P)"
if [[ $# -lt 4 || "$2" != "$root/artisan" || "$3" != queue:work ]]; then
    printf '%s\n' '[queue-start] Oczekiwano polecenia PHP artisan queue:work z polaczeniem kolejki.' >&2
    exit 78
fi

connection="$4"
probe="$root/scripts/check-queue-mysql.php"
trap 'exit 0' TERM INT
attempts=0

while true; do
    if timeout --foreground --kill-after=1s 3s "$1" -d display_errors=0 -d log_errors=0 "$probe" "$connection"; then
        printf '%s\n' '[queue-start] MySQL dostepny. Uruchamiam proces kolejki.'
        exec "$@"
    else
        status=$?
    fi

    case "$status" in
        75|124|137)
            attempts=$((attempts + 1))
            if (( attempts == 1 || attempts % 20 == 0 )); then
                printf '%s\n' '[queue-start] Czekam na MySQL. Kolejka nie pobiera jeszcze zadan.' >&2
            fi
            # Small jitter avoids reconnecting all workers at the same instant.
            if (( attempts <= 4 )); then sleep "$((5 + RANDOM % 3))"
            else sleep "$((15 + RANDOM % 4))"; fi
            ;;
        *)
            printf '[queue-start] Przerwano start kolejki (kod: %s).\n' "$status" >&2
            exit "$status"
            ;;
    esac
done
