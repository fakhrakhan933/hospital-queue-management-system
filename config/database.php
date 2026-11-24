<?php
/**
 * Database Connection Configuration
 * Hospital Queue Management System
 * 
 * Uses PDO (PHP Data Objects) with native prepared statements,
 * comprehensive error handling, and transaction support.
 */

// Database credentials
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'hospital_queue_db');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('DB_PORT')) define('DB_PORT', '3306');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

class Database {
    private static ?PDO $instance = null;

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {}

    /**
     * Get the PDO database connection instance (Singleton)
     *
     * @return PDO
     * @throws PDOException
     */
    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf(
                "mysql:host=%s;port=%s;dbname=%s;charset=%s",
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_CHARSET
            );

            $options = [
                // Throw exceptions on errors for robust error handling
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                // Fetch rows as associative arrays by default
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Enforce native prepared statements (disables emulation for security)
                PDO::ATTR_EMULATE_PREPARES   => false,
                // Ensure proper charset communication
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                // Log detailed error for system administrator
                error_log("Database Connection Failure: " . $e->getMessage());
                // Throw clean exception avoiding credential leakage
                throw new PDOException("Database connection error: Could not connect to database '" . DB_NAME . "'. Please ensure MySQL service is running.");
            }
        }

        return self::$instance;
    }

    /**
     * Helper method to execute a prepared SQL query safely
     *
     * @param string $sql
     * @param array $params
     * @return PDOStatement
     */
    public static function query(string $sql, array $params = []): PDOStatement {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}

/**
 * Procedural helper to retrieve active PDO connection
 *
 * @return PDO
 */
function getDBConnection(): PDO {
    return Database::getConnection();
}

// Initialize standard $pdo variable for scripts including this file directly
try {
    $pdo = Database::getConnection();
} catch (PDOException $e) {
    if (php_sapi_name() !== 'cli' && basename($_SERVER['PHP_SELF'] ?? '') === 'database.php') {
        die("<h3>Database Connection Error</h3><p>" . htmlspecialchars($e->getMessage()) . "</p>");
    }
}
