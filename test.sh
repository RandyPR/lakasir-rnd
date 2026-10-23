#!/bin/bash
# The job fails when Pest does: exit 1 for a failed test, 2 for a test that threw.
php -d error_reporting=8191 -d memory_limit=512M artisan test
