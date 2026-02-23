# MariaDB Integration Tests

These tests are intentionally separated from the default PHPUnit suite.

## Why

`tests_mariadb/` exercises real transaction/concurrency behavior against MariaDB.  
It is slower and requires a configured database.

## Run

```bash
composer test:mariadb
```

or:

```bash
php vendor/bin/phpunit --configuration phpunit.mariadb.xml
```

## Required Environment Variables

- `YZE_MARIADB_TEST_HOST`
- `YZE_MARIADB_TEST_USER`
- `YZE_MARIADB_TEST_DB`

Optional:

- `YZE_MARIADB_TEST_PASSWORD` (default empty)
- `YZE_MARIADB_TEST_PORT` (default `3306`)
- `YZE_MARIADB_TEST_SITE_URL` (default `http://localhost:8080`)

## Safety

The suite drops and recreates all API tables in the configured test database each test run.  
Use a dedicated test database.

