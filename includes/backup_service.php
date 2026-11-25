<?php
declare(strict_types=1);

function cozycafe_create_database_backup(
    PDO $pdo,
    string $backupType,
    ?int $createdBy = null,
    ?string $backupDirectory = null
): array {
    if (
        !in_array($backupType, ['auto', 'manual'], true)
        || ($createdBy !== null && $createdBy < 1)
    ) {
        throw new InvalidArgumentException('A valid backup type and optional administrator ID are required.');
    }

    $projectRoot = dirname(__DIR__);
    $backupDirectory = $backupDirectory ?? $projectRoot
        . DIRECTORY_SEPARATOR . 'database'
        . DIRECTORY_SEPARATOR . 'backups';
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0750, true) && !is_dir($backupDirectory)) {
        throw new RuntimeException('The database backup directory could not be created.');
    }
    $resolvedDirectory = realpath($backupDirectory);
    if ($resolvedDirectory === false || !is_writable($resolvedDirectory)) {
        throw new RuntimeException('The database backup directory is not writable.');
    }

    $fileName = 'cozycafe-' . date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.sql';
    $temporaryPath = $resolvedDirectory . DIRECTORY_SEPARATOR . '.' . $fileName . '.tmp';
    $finalPath = $resolvedDirectory . DIRECTORY_SEPARATOR . $fileName;
    $relativePath = 'database/backups/' . $fileName;
    $file = fopen($temporaryPath, 'xb');
    if ($file === false) {
        throw new RuntimeException('The temporary database backup file could not be opened.');
    }

    if ($pdo->inTransaction()) {
        if (!fclose($file) || !unlink($temporaryPath)) {
            throw new RuntimeException('The temporary backup file could not be removed.');
        }
        throw new RuntimeException('A database backup cannot run inside another transaction.');
    }

    $transactionStarted = false;
    try {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $pdo->beginTransaction();
        $transactionStarted = true;

        $write = static function (string $contents) use ($file): void {
            $length = strlen($contents);
            $offset = 0;
            while ($offset < $length) {
                $written = fwrite($file, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('The database backup could not be written completely.');
                }
                $offset += $written;
            }
        };

        $write(
            "-- CozyCafe database backup\n"
            . '-- Generated: ' . date('Y-m-d H:i:s') . " WIB\n"
            . "SET NAMES utf8mb4;\n"
            . "SET FOREIGN_KEY_CHECKS=0;\n\n"
        );

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $tableName) {
            if (!is_string($tableName) || preg_match('/\A[A-Za-z0-9_]+\z/', $tableName) !== 1) {
                throw new RuntimeException('The database returned an invalid table identifier.');
            }
            $quotedTable = '`' . str_replace('`', '``', $tableName) . '`';
            $createQuery = $pdo->query('SHOW CREATE TABLE ' . $quotedTable);
            $createRow = $createQuery->fetch(PDO::FETCH_NUM);
            if (!is_array($createRow) || !isset($createRow[1]) || !is_string($createRow[1])) {
                throw new RuntimeException('The CREATE TABLE definition could not be read for ' . $tableName . '.');
            }

            $write('DROP TABLE IF EXISTS ' . $quotedTable . ";\n");
            $write($createRow[1] . ";\n\n");
            $rowsQuery = $pdo->query('SELECT * FROM ' . $quotedTable);
            $columns = [];
            for ($index = 0; $index < $rowsQuery->columnCount(); $index++) {
                $metadata = $rowsQuery->getColumnMeta($index);
                $columnName = $metadata['name'] ?? null;
                if (!is_string($columnName) || preg_match('/\A[A-Za-z0-9_]+\z/', $columnName) !== 1) {
                    throw new RuntimeException('A database column has an invalid identifier in ' . $tableName . '.');
                }
                $columns[] = '`' . str_replace('`', '``', $columnName) . '`';
            }
            if ($columns === []) {
                throw new RuntimeException('The database returned no columns for ' . $tableName . '.');
            }

            while (($row = $rowsQuery->fetch(PDO::FETCH_NUM)) !== false) {
                $values = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } elseif (is_resource($value)) {
                        $binaryValue = stream_get_contents($value);
                        if ($binaryValue === false) {
                            throw new RuntimeException('A binary database value could not be read.');
                        }
                        $values[] = '0x' . bin2hex($binaryValue);
                    } else {
                        $quotedValue = $pdo->quote((string) $value, PDO::PARAM_STR);
                        if (!is_string($quotedValue)) {
                            throw new RuntimeException('A database value could not be safely encoded.');
                        }
                        $values[] = $quotedValue;
                    }
                }
                $write(
                    'INSERT INTO ' . $quotedTable . ' (' . implode(', ', $columns) . ') VALUES ('
                    . implode(', ', $values) . ");\n"
                );
            }
            $write("\n");
        }

        $write("SET FOREIGN_KEY_CHECKS=1;\n");
        if (!fflush($file)) {
            throw new RuntimeException('The database backup could not be flushed to disk.');
        }
        if (function_exists('fsync') && !fsync($file)) {
            throw new RuntimeException('The database backup could not be synchronized to disk.');
        }
        if (!fclose($file)) {
            $file = null;
            throw new RuntimeException('The database backup file could not be closed.');
        }
        $file = null;

        if (!rename($temporaryPath, $finalPath)) {
            throw new RuntimeException('The completed database backup could not be finalized.');
        }
        if ($transactionStarted && $pdo->inTransaction()) {
            $pdo->commit();
            $transactionStarted = false;
        }

        $fileSize = filesize($finalPath);
        if ($fileSize === false || $fileSize < 1) {
            throw new RuntimeException('The completed database backup has an invalid size.');
        }
        try {
            $insert = $pdo->prepare(
                'INSERT INTO backups (file_name, file_path, file_size, backup_type, created_by)
                 VALUES (:file_name, :file_path, :file_size, :backup_type, :created_by)'
            );
            $insert->execute([
                'file_name' => $fileName,
                'file_path' => $relativePath,
                'file_size' => $fileSize,
                'backup_type' => $backupType,
                'created_by' => $createdBy,
            ]);
        } catch (Throwable $exception) {
            if (is_file($finalPath) && !unlink($finalPath)) {
                throw new RuntimeException('Backup metadata could not be saved and the orphaned file could not be removed.', 0, $exception);
            }
            throw $exception;
        }

        return [
            'id' => (int) $pdo->lastInsertId(),
            'file_name' => $fileName,
            'file_path' => $relativePath,
            'file_size' => $fileSize,
            'backup_type' => $backupType,
        ];
    } catch (Throwable $exception) {
        if (is_resource($file)) {
            fclose($file);
        }
        if ($transactionStarted && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ([$temporaryPath, $finalPath] as $path) {
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('A failed database backup artifact could not be removed.', 0, $exception);
            }
        }
        throw $exception;
    }
}

function cozycafe_backup_file_path(string $relativePath, ?string $backupDirectory = null): string
{
    if (preg_match('/\Adatabase\/backups\/cozycafe-[0-9]{8}-[0-9]{6}-[a-f0-9]{12}\.sql\z/', $relativePath) !== 1) {
        throw new RuntimeException('The database backup path is invalid.');
    }
    $backupDirectory = $backupDirectory ?? dirname(__DIR__)
        . DIRECTORY_SEPARATOR . 'database'
        . DIRECTORY_SEPARATOR . 'backups';
    $directory = realpath($backupDirectory);
    if ($directory === false) {
        throw new RuntimeException('The database backup directory is unavailable.');
    }
    $fileName = substr($relativePath, strlen('database/backups/'));
    $path = realpath($directory . DIRECTORY_SEPARATOR . $fileName);
    if (
        $path === false
        || !is_file($path)
        || !str_starts_with($path, $directory . DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('The requested database backup file is unavailable.');
    }
    return $path;
}
