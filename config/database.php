<?php
class Database {
    private $host = "localhost";
    private $db_name = "jadwal_kuliah";
    private $username = "root";
    private $password = "";
    public $conn;

    public function getConnection() {
        $this->conn = null;
        
        try {
            // Set PDO options
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_STRINGIFY_FETCHES => false
            ];
            
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username, 
                $this->password,
                $options
            );
            
            // Set timezone
            $this->conn->exec("SET time_zone = '+07:00'");
            
        } catch(PDOException $exception) {
            // Log error but don't expose to user
            error_log("Database Connection Error: " . $exception->getMessage());
            
            // For development, you might want to show error
            if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
                die("Connection error: " . $exception->getMessage());
            } else {
                // Return false instead of dying in production
                return false;
            }
        }
        
        return $this->conn;
    }

    /**
     * Test database connection
     */
    public function testConnection() {
        try {
            $conn = $this->getConnection();
            if ($conn) {
                $stmt = $conn->query("SELECT 1");
                return $stmt->fetchColumn() == 1;
            }
            return false;
        } catch (Exception $e) {
            error_log("Database Test Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Execute a query and return results
     */
    public function executeQuery($sql, $params = []) {
        try {
            $conn = $this->getConnection();
            if (!$conn) return false;
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            
            return $stmt;
        } catch (Exception $e) {
            error_log("Query Error: " . $e->getMessage() . " | SQL: " . $sql);
            return false;
        }
    }

    /**
     * Get single row
     */
    public function getRow($sql, $params = []) {
        $stmt = $this->executeQuery($sql, $params);
        return $stmt ? $stmt->fetch() : false;
    }

    /**
     * Get all rows
     */
    public function getAll($sql, $params = []) {
        $stmt = $this->executeQuery($sql, $params);
        return $stmt ? $stmt->fetchAll() : [];
    }

    /**
     * Insert data and return last insert ID
     */
    public function insert($table, $data) {
        try {
            $conn = $this->getConnection();
            if (!$conn) return false;
            
            $columns = implode(', ', array_keys($data));
            $placeholders = implode(', ', array_fill(0, count($data), '?'));
            $values = array_values($data);
            
            $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
            $stmt = $conn->prepare($sql);
            $stmt->execute($values);
            
            return $conn->lastInsertId();
        } catch (Exception $e) {
            error_log("Insert Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update data
     */
    public function update($table, $data, $where, $whereParams = []) {
        try {
            $conn = $this->getConnection();
            if (!$conn) return false;
            
            $set = [];
            $values = [];
            
            foreach ($data as $key => $value) {
                $set[] = "{$key} = ?";
                $values[] = $value;
            }
            
            $values = array_merge($values, $whereParams);
            $setClause = implode(', ', $set);
            
            $sql = "UPDATE {$table} SET {$setClause} WHERE {$where}";
            $stmt = $conn->prepare($sql);
            
            return $stmt->execute($values);
        } catch (Exception $e) {
            error_log("Update Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete data
     */
    public function delete($table, $where, $params = []) {
        try {
            $conn = $this->getConnection();
            if (!$conn) return false;
            
            $sql = "DELETE FROM {$table} WHERE {$where}";
            $stmt = $conn->prepare($sql);
            
            return $stmt->execute($params);
        } catch (Exception $e) {
            error_log("Delete Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Begin transaction
     */
    public function beginTransaction() {
        try {
            $conn = $this->getConnection();
            return $conn ? $conn->beginTransaction() : false;
        } catch (Exception $e) {
            error_log("Begin Transaction Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Commit transaction
     */
    public function commit() {
        try {
            $conn = $this->getConnection();
            return $conn ? $conn->commit() : false;
        } catch (Exception $e) {
            error_log("Commit Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Rollback transaction
     */
    public function rollback() {
        try {
            $conn = $this->getConnection();
            return $conn ? $conn->rollBack() : false;
        } catch (Exception $e) {
            error_log("Rollback Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if table exists
     */
    public function tableExists($tableName) {
        try {
            $conn = $this->getConnection();
            if (!$conn) return false;
            
            $sql = "SHOW TABLES LIKE ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$tableName]);
            
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log("Table Exists Check Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get table structure
     */
    public function getTableStructure($tableName) {
        try {
            $conn = $this->getConnection();
            if (!$conn) return false;
            
            $sql = "DESCRIBE {$tableName}";
            $stmt = $conn->query($sql);
            
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Get Table Structure Error: " . $e->getMessage());
            return false;
        }
    }
}
?>