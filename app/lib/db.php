<?php
/**
 * Thin PDO wrapper. Every query in the app goes through these helpers so that
 * values are always bound as parameters (never interpolated into SQL).
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
        try {
            $pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $ex) {
            $hint = str_contains($ex->getMessage(), '2002') ? 'MySQL is not running — start it from the XAMPP Control Panel.'
                : (str_contains($ex->getMessage(), '1049') ? "Database '{$c['name']}' does not exist — create it in phpMyAdmin."
                : 'Check the database name, user and password in app/config.php' . (config('debug') ? ' (local: app/config.local.php)' : '') . '.');
            throw new RuntimeException('Cannot connect to the database. ' . $hint, 0, $ex);
        }
        $pdo->exec("SET time_zone = '" . date('P') . "'");
        // Use the database's own utf8mb4 collation for this connection, so bound values, text literals and table
        // columns compare under one rule. Hosts often mix utf8mb4_general_ci and utf8mb4_unicode_ci, which makes
        // MySQL refuse comparisons with error 1267 "Illegal mix of collations".
        try {
            $coll = (string) $pdo->query('SELECT @@collation_database')->fetchColumn();
            if (preg_match('/^utf8mb4_[a-z0-9_]+$/', $coll)) $pdo->exec("SET NAMES utf8mb4 COLLATE $coll");
        } catch (PDOException $e) {
            error_log('Could not align the connection collation: ' . $e->getMessage());
        }
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function val(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** Quote an identifier coming from trusted code (table/column names in module definitions). */
function qi(string $name): string
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
        throw new InvalidArgumentException("Bad identifier: $name");
    }
    return "`$name`";
}

function insert(string $table, array $data): int
{
    $cols = array_map('qi', array_keys($data));
    $sql = 'INSERT INTO ' . qi($table) . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')';
    q($sql, array_values($data));
    return (int) db()->lastInsertId();
}

function update(string $table, array $data, int $id, string $pk = 'id'): void
{
    if (!$data) {
        return;
    }
    $sets = implode(',', array_map(fn($c) => qi($c) . '=?', array_keys($data)));
    q('UPDATE ' . qi($table) . " SET $sets WHERE " . qi($pk) . '=?', [...array_values($data), $id]);
}

function table_exists(string $table): bool
{
    return (bool) val('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
}

function column_exists(string $table, string $column): bool
{
    return (bool) val('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
}

/** Key/value company settings, cached per request. */
function setting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (all('SELECT k, v FROM hrm_settings') as $r) {
            $cache[$r['k']] = $r['v'];
        }
    }
    if ($key === '__flush') {
        $cache = null;
        return null;
    }
    return array_key_exists($key, $cache) && $cache[$key] !== '' ? $cache[$key] : $default;
}

function set_setting(string $key, $value): void
{
    q('INSERT INTO hrm_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$key, (string) $value]);
    setting('__flush');
}
