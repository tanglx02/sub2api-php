<?php
declare(strict_types=1);
namespace App\Core;

use PDO;
use PDOException;

/** PDO 数据库访问层（MySQL / SQLite） */
final class Database
{
    private static ?PDO $pdo = null;
    public static string $driver = 'sqlite';

    public static function connect(array $cfg): PDO
    {
        $driver = $cfg['driver'] ?? 'sqlite';
        if ($driver === 'mysql') {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $cfg['host'], (int)($cfg['port'] ?? 3306), $cfg['database']);
            $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } else {
            $path = $cfg['sqlite_path'] ?? (dirname(__DIR__, 2) . '/data/sub2api.db');
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA journal_mode=WAL');
            $pdo->exec('PRAGMA busy_timeout=5000');
            $pdo->exec('PRAGMA foreign_keys=ON');
        }
        self::$pdo = $pdo;
        self::$driver = $driver;
        return $pdo;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            if (!Config::load()) { throw new \RuntimeException('应用未安装'); }
            self::connect((array)Config::get('database'));
        }
        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $name = is_int($k) ? $k + 1 : (str_starts_with($k, ':') ? $k : ':' . $k);
            $type = is_int($v) ? PDO::PARAM_INT : (is_null($v) ? PDO::PARAM_NULL : PDO::PARAM_STR);
            if (is_int($k)) { $stmt->bindValue($name, $v, $type); }
            else { $stmt->bindValue($name, $v, $type); }
        }
        $stmt->execute();
        return $stmt;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function lastId(): int
    {
        return (int)self::pdo()->lastInsertId();
    }

    public static function begin(): void { if (!self::pdo()->inTransaction()) { self::pdo()->beginTransaction(); } }
    public static function commit(): void { if (self::pdo()->inTransaction()) { self::pdo()->commit(); } }
    public static function rollback(): void { if (self::pdo()->inTransaction()) { self::pdo()->rollBack(); } }

    /** 数据库当前时间表达式 */
    public static function nowSql(): string
    {
        return self::$driver === 'mysql' ? 'NOW()' : "datetime('now')";
    }
}
