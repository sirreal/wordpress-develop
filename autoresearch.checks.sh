#!/bin/bash
set -euo pipefail

# Run HTML API tests — suppress success output, only show errors
./vendor/bin/phpunit -c tests/phpunit/tests/html-api/phpunit.xml --stop-on-error --stop-on-failure --stop-on-warning --stop-on-defect 2>&1 | tail -5
