#!/usr/bin/env bash
set -euo pipefail

# Avvia il mock S3, esegue lo smoke test, poi ferma il mock.
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

php -S 127.0.0.1:8899 "$DIR/mock_s3.php" >/dev/null 2>&1 &
MOCK_PID=$!
trap 'kill $MOCK_PID 2>/dev/null || true' EXIT

# attendi che il mock sia pronto
for _ in $(seq 1 20); do
    if curl -s -o /dev/null "http://127.0.0.1:8899/" 2>/dev/null; then break; fi
    sleep 0.2
done

php "$DIR/smoke_test.php"
