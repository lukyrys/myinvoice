<?php

declare(strict_types=1);

/**
 * MySQL-compatible migration runner.
 *
 * Wrapper okolo originálního migrate.php logic. Migrace MyInvoice používají
 * MariaDB-specific syntaxi `ADD COLUMN IF NOT EXISTS` / `ADD KEY IF NOT EXISTS`,
 * kterou MySQL 8.x nepodporuje. Tento runner:
 *
 *  - Strippuje `IF NOT EXISTS` z `ADD COLUMN/KEY/UNIQUE KEY` (MySQL nezná).
 *  - Tichne MySQL errory 1060 (Duplicate column name) a 1061 (Duplicate key name)
 *    — protože původní idempotentnost přes IF NOT EXISTS je tím zachována.
 *  - Sdílí logiku splitSqlStatements() s originálním migrate.php.
 *
 * Použití (PHP CLI):
 *   php api/bin/migrate-mysql.php          # spustí pending migrace
 *   php api/bin/migrate-mysql.php --status # vypíše stav bez aplikace
 *
 * Tento soubor je lokální patch — neměňte originální migrate.php (kvůli upstream sync).
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$db      = (new Connection($config))->pdo();

$migrationsDir = $rootDir . '/db/migrations';
if (!is_dir($migrationsDir)) {
    fwrite(STDERR, "Migrations directory not found: {$migrationsDir}\n");
    exit(1);
}

$db->exec(
    'CREATE TABLE IF NOT EXISTS migrations ('
    . ' filename VARCHAR(190) PRIMARY KEY,'
    . ' applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
    . ' duration_ms INT UNSIGNED NOT NULL DEFAULT 0'
    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$applied = $db->query('SELECT filename FROM migrations')->fetchAll(\PDO::FETCH_COLUMN);
$applied = array_flip($applied);

$files = glob($migrationsDir . '/*.sql');
sort($files, SORT_STRING);

$statusOnly = in_array('--status', $argv, true);

if ($statusOnly) {
    echo "Migration status:\n";
    foreach ($files as $file) {
        $name   = basename($file);
        $marker = isset($applied[$name]) ? '[x]' : '[ ]';
        echo "  {$marker} {$name}\n";
    }
    exit(0);
}

$pending = array_filter($files, fn (string $f) => !isset($applied[basename($f)]));

if (empty($pending)) {
    echo "Žádné nové migrace k aplikaci.\n";
    exit(0);
}

echo "Pending migrations: " . count($pending) . " (MySQL-compat mode)\n";

foreach ($pending as $file) {
    $name = basename($file);
    echo "  → {$name} ... ";

    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "READ FAILED\n");
        exit(1);
    }

    $start = microtime(true);
    $skipped = 0;
    try {
        foreach (splitSqlStatements($sql) as $stmt) {
            $cleaned = preg_replace('/^(\s*--[^\n]*\n)+/', '', $stmt) ?? $stmt;
            $cleaned = trim($cleaned);
            if ($cleaned === '') {
                continue;
            }
            $patched = stripIfNotExistsForMysql($stmt);
            try {
                $db->exec($patched);
            } catch (\PDOException $e) {
                $code = (int) ($e->errorInfo[1] ?? 0);
                // 1060 = duplicate column, 1061 = duplicate key — idempotentní, ignoruj
                if ($code === 1060 || $code === 1061) {
                    $skipped++;
                    continue;
                }
                throw $e;
            }
        }
    } catch (\Throwable $e) {
        echo "FAILED\n";
        fwrite(STDERR, '  Error: ' . $e->getMessage() . "\n");
        exit(1);
    }

    $durationMs = (int) ((microtime(true) - $start) * 1000);

    $stmt = $db->prepare('INSERT INTO migrations (filename, duration_ms) VALUES (?, ?)');
    $stmt->execute([$name, $durationMs]);

    $skippedNote = $skipped > 0 ? " [skipped {$skipped} duplicate]" : '';
    echo "OK ({$durationMs} ms){$skippedNote}\n";
}

echo "Hotovo.\n";

/**
 * Strip MariaDB-specific `IF NOT EXISTS` z `ADD COLUMN/KEY/UNIQUE KEY/INDEX`
 * a `IF EXISTS` z `DROP COLUMN/KEY/INDEX`.
 *
 * MySQL 8.x to nepodporuje (jen pro CREATE TABLE/INDEX a DROP TABLE/INDEX).
 * Idempotentnost zachová ošetření 1060/1061 v hlavní smyčce.
 */
function stripIfNotExistsForMysql(string $sql): string
{
    $patterns = [
        '/\b(ADD\s+(?:COLUMN|UNIQUE\s+KEY|KEY|INDEX|UNIQUE\s+INDEX|FULLTEXT\s+(?:KEY|INDEX)|SPATIAL\s+(?:KEY|INDEX)))\s+IF\s+NOT\s+EXISTS\b/i',
        '/\b(DROP\s+(?:COLUMN|KEY|INDEX|FOREIGN\s+KEY))\s+IF\s+EXISTS\b/i',
    ];
    return preg_replace($patterns, '$1', $sql) ?? $sql;
}

/**
 * Rozdělí SQL na statementy — kopie z migrate.php (delimiter handling, stringy, komentáře).
 */
function splitSqlStatements(string $sql): array
{
    $stmts = [];
    $current = '';
    $delim = ';';
    $len = strlen($sql);
    $inSingle = false;
    $inLineComment = false;
    $inBlockComment = false;
    $atLineStart = true;

    for ($i = 0; $i < $len; $i++) {
        if ($atLineStart && !$inSingle && !$inLineComment && !$inBlockComment) {
            $j = $i;
            while ($j < $len && ($sql[$j] === ' ' || $sql[$j] === "\t")) $j++;
            if ($j + 10 <= $len && strcasecmp(substr($sql, $j, 10), 'DELIMITER ') === 0) {
                $eol = strpos($sql, "\n", $j + 10);
                if ($eol === false) $eol = $len;
                $newDelim = trim(substr($sql, $j + 10, $eol - ($j + 10)));
                if ($newDelim !== '') {
                    if (trim($current) !== '') {
                        $stmts[] = $current;
                        $current = '';
                    }
                    $delim = $newDelim;
                }
                $i = $eol;
                $atLineStart = true;
                continue;
            }
        }
        $atLineStart = false;

        $ch  = $sql[$i];
        $nxt = ($i + 1 < $len) ? $sql[$i + 1] : '';

        if ($inLineComment) {
            $current .= $ch;
            if ($ch === "\n") { $inLineComment = false; $atLineStart = true; }
            continue;
        }
        if ($inBlockComment) {
            $current .= $ch;
            if ($ch === '*' && $nxt === '/') {
                $current .= '/';
                $i++;
                $inBlockComment = false;
            }
            continue;
        }
        if ($inSingle) {
            $current .= $ch;
            if ($ch === '\\' && $nxt !== '') {
                $current .= $nxt;
                $i++;
                continue;
            }
            if ($ch === "'") $inSingle = false;
            continue;
        }

        if ($ch === '-' && $nxt === '-') {
            $current .= '--';
            $i++;
            $inLineComment = true;
            continue;
        }
        if ($ch === '/' && $nxt === '*') {
            $current .= '/*';
            $i++;
            $inBlockComment = true;
            continue;
        }
        if ($ch === "'") {
            $inSingle = true;
            $current .= $ch;
            continue;
        }
        if ($ch === "\n") {
            $current .= $ch;
            $atLineStart = true;
            continue;
        }

        $dlen = strlen($delim);
        if ($dlen > 0 && substr_compare($sql, $delim, $i, $dlen) === 0) {
            if (trim($current) !== '') $stmts[] = $current;
            $current = '';
            $i += $dlen - 1;
            continue;
        }
        $current .= $ch;
    }

    if (trim($current) !== '') $stmts[] = $current;

    return $stmts;
}
