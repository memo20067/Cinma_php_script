<?php
// Database Connection Wrapper

if (!file_exists(__DIR__ . '/../config.php')) {
    // If config.php doesn't exist, redirect to installer if accessed via browser
    if (php_sapi_name() !== 'cli' && strpos($_SERVER['REQUEST_URI'], '/installer/') === false) {
        header('Location: /installer/index.php');
        exit;
    }
} else {
    require_once __DIR__ . '/../config.php';
}

class DB {
    private static $instance = null;
    private $pdo;
    private $prefix = '';

    private function __construct() {
        if (!defined('DB_HOST')) {
            return;
        }

        $driver = defined('DB_DRIVER') ? DB_DRIVER : 'mysql';
        $host = defined('DB_HOST') ? DB_HOST : 'localhost';
        $dbname = defined('DB_NAME') ? DB_NAME : '';
        $user = defined('DB_USER') ? DB_USER : '';
        $pass = defined('DB_PASS') ? DB_PASS : '';
        $this->prefix = defined('DB_PREFIX') ? DB_PREFIX : '';

        try {
            if ($driver === 'sqlite' || strpos($host, 'sqlite:') === 0) {
                $dbFile = ($driver === 'sqlite' && strpos($host, 'sqlite:') !== 0) ? $host : str_replace('sqlite:', '', $host);
                if (empty($dbFile) || $dbFile === 'localhost') {
                    $dbFile = __DIR__ . '/../data/database.sqlite';
                    $dir = dirname($dbFile);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0777, true);
                    }
                }
                $this->pdo = new PDO("sqlite:" . $dbFile);
            } else {
                $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
                $this->pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]);
            }
            if ($driver === 'sqlite' || strpos($host, 'sqlite:') === 0) {
                $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            if (php_sapi_name() === 'cli') {
                throw $e;
            }
            die("Database Connection Error: " . $e->getMessage());
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new DB();
        }
        return self::$instance;
    }

    public function getPDO() {
        return $this->pdo;
    }

    public function getPrefix() {
        return $this->prefix;
    }

    public function table($name) {
        return $this->prefix . $name;
    }

    public static function query($sql, $params = []) {
        $db = self::getInstance();
        $pdo = $db->getPDO();
        if (!$pdo) return false;

        // Replace {prefix} in SQL queries with actual prefix
        $sql = str_replace('{prefix}', $db->getPrefix(), $sql);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetchAll($sql, $params = []) {
        $stmt = self::query($sql, $params);
        return $stmt ? $stmt->fetchAll() : [];
    }

    public static function fetch($sql, $params = []) {
        $stmt = self::query($sql, $params);
        return $stmt ? $stmt->fetch() : false;
    }

    public static function insert($table, $data) {
        $db = self::getInstance();
        $tableName = $db->table($table);
        $keys = array_keys($data);
        $fields = implode(', ', array_map(function($k) { return "`{$k}`"; }, $keys));
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));

        $sql = "INSERT INTO `{$tableName}` ({$fields}) VALUES ({$placeholders})";
        if ($db->getDriver() === 'sqlite') {
            $fieldsSqlite = implode(', ', array_map(function($k) { return "\"{$k}\""; }, $keys));
            $sql = "INSERT INTO \"{$tableName}\" ({$fieldsSqlite}) VALUES ({$placeholders})";
        }

        self::query($sql, array_values($data));
        return $db->getPDO()->lastInsertId();
    }

    public static function update($table, $data, $where, $whereParams = []) {
        $db = self::getInstance();
        $tableName = $db->table($table);
        $set = implode(', ', array_map(function($k) { return "`{$k}` = ?"; }, array_keys($data)));
        if ($db->getDriver() === 'sqlite') {
            $set = implode(', ', array_map(function($k) { return "\"{$k}\" = ?"; }, array_keys($data)));
        }
        $sql = "UPDATE `{$tableName}` SET {$set} WHERE {$where}";
        if ($db->getDriver() === 'sqlite') {
            $sql = "UPDATE \"{$tableName}\" SET {$set} WHERE {$where}";
        }

        $params = array_merge(array_values($data), $whereParams);
        return self::query($sql, $params);
    }

    public static function delete($table, $where, $params = []) {
        $db = self::getInstance();
        $tableName = $db->table($table);
        $sql = "DELETE FROM `{$tableName}` WHERE {$where}";
        if ($db->getDriver() === 'sqlite') {
            $sql = "DELETE FROM \"{$tableName}\" WHERE {$where}";
        }
        return self::query($sql, $params);
    }

    public function getDriver() {
        if (!$this->pdo) return 'mysql';
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}
