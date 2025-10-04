<?php
declare(strict_types=1);

namespace ReliefHub\Backend;

use PDO;
use PDOException;

final class Database
{
    public static function connect(array $env): PDO
    {
        $host = $env['DB_HOST'] ?? '127.0.0.1';
        $port = (int)($env['DB_PORT'] ?? 3306);
        $db   = $env['DB_NAME'] ?? 'sagiphub';
        $user = $env['DB_USER'] ?? 'root';
        $pass = $env['DB_PASS'] ?? '';
        $charset = 'utf8mb4';

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $db, $charset);
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            throw $e;
        }

        // Ensure strict SQL mode if needed
        $pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        return $pdo;
    }
}


