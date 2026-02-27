### `SimplePdo` Interface Summary (`flight\database\SimplePdo`)

**Location:** `vendor/flightphp/core/flight/database/SimplePdo.php`  
**Extends:** `PdoWrapper`

#### Constructor
- `__construct(?string $dsn = null, ?string $username = null, ?string $password = null, ?array $pdoOptions = null, array $options = [])`
  - Defaults `PDO::ATTR_DEFAULT_FETCH_MODE` to `PDO::FETCH_ASSOC` if not set.
  - Options:
    - `trackApmQueries` (bool, default `false`)
    - `maxQueryMetrics` (int, default `1000`)

#### Query helpers
- `fetchRow(string $sql, array $params = []): ?Collection`
  - Returns the first row as `Collection` or `null`.
  - Adds `LIMIT 1` if missing.
- `fetchAll(string $sql, array $params = []): array`
  - Returns array of `Collection` rows.
- `fetchColumn(string $sql, array $params = []): array`
  - Returns first column as array.
- `fetchPairs(string $sql, array $params = []): array`
  - Returns key-value pairs (first column key, second column value).

#### Execution
- `runQuery(string $sql, array $params = []): PDOStatement`
  - Prepares + executes SQL.
  - Expands `IN(?)` placeholders if param is an array.
  - Throws `PDOException` on prepare failure.
  - Tracks metrics if `trackApmQueries` is enabled.

#### Transactions
- `transaction(callable $callback)`
  - Begins transaction, passes `$this` to callback.
  - Commits on success, rolls back on exception.

#### Data modification helpers
- `insert(string $table, array $data): string`
  - Single row or bulk insert.
  - Returns `lastInsertId()` (string).
- `update(string $table, array $data, string $where, array $whereParams = []): int`
  - Builds `SET col = ?` and `WHERE`.
  - Returns affected rows.
- `delete(string $table, string $where, array $whereParams = []): int`
  - Executes `DELETE`.
  - Returns deleted rows.

#### Notable behavior
- `IN(?)` expansion:
  - Array param → `IN(?,?,...)`
  - Empty array → `IN(NULL)`
