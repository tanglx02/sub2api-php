<?php
declare(strict_types=1);
namespace App\Core;

use PDO;

/** 版本化迁移执行器（migrations/{driver}/NNN_*.sql 按序执行） */
final class Migrator
{
    public static function migrate(PDO $pdo, string $driver, string $baseDir): array
    {
        $driver = $driver === 'mysql' ? 'mysql' : 'sqlite';
        $dir = $baseDir . '/migrations/' . $driver;
        if ($driver === 'mysql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS migrations (version VARCHAR(64) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL DEFAULT (datetime('now')))");
        }
        $applied = $pdo->query('SELECT version FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        $applied = array_flip($applied);

        $files = glob($dir . '/*.sql') ?: [];
        sort($files);
        $log = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (isset($applied[$version])) { continue; }
            $sql = (string)file_get_contents($file);
            $pdo->exec($sql);
            $stmt = $pdo->prepare('INSERT INTO migrations (version) VALUES (?)');
            $stmt->execute([$version]);
            $log[] = $version;
        }
        return $log;
    }
}
