<?php
/**
 * 数据库操作类
 * 支持MySQL 5.6.50+版本
 * 使用PDO进行数据库操作
 */

class Database
{
    private static $instance = null;
    private $pdo;
    private $prefix;
    private $config;

    /**
     * 构造函数 - 私有化实现单例模式
     * @param array $config 数据库配置数组
     */
    private function __construct($config)
    {
        $this->config = $config;
        $this->prefix = $config['prefix'];
        
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['name'],
                $config['charset']
            );
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$config['charset']}"
            ];
            
            $this->pdo = new PDO($dsn, $config['user'], $config['pass'], $options);
        } catch (PDOException $e) {
            throw new Exception('数据库连接失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取数据库实例
     * @param array $config 数据库配置
     * @return Database 数据库实例
     */
    public static function getInstance($config = null)
    {
        if (self::$instance === null) {
            if ($config === null) {
                throw new Exception('数据库配置不能为空');
            }
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * 获取PDO对象
     * @return PDO PDO对象
     */
    public function getPdo()
    {
        return $this->pdo;
    }

    /**
     * 获取表前缀
     * @return string 表前缀
     */
    public function getPrefix()
    {
        return $this->prefix;
    }

    /**
     * 获取完整表名
     * @param string $table 表名
     * @return string 完整表名
     */
    public function tableName($table)
    {
        return $this->prefix . $table;
    }

    /**
     * 执行查询语句
     * @param string $sql SQL语句
     * @param array $params 参数数组
     * @return PDOStatement PDO语句对象
     */
    public function query($sql, $params = [])
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            throw new Exception('查询执行失败: ' . $e->getMessage());
        }
    }

    /**
     * 查询单行数据
     * @param string $sql SQL语句
     * @param array $params 参数数组
     * @return array|null 单行数据
     */
    public function fetchOne($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }

    /**
     * 查询多行数据
     * @param string $sql SQL语句
     * @param array $params 参数数组
     * @return array 多行数据
     */
    public function fetchAll($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * 查询单个值
     * @param string $sql SQL语句
     * @param array $params 参数数组
     * @return mixed 单个值
     */
    public function fetchColumn($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchColumn();
    }

    /**
     * 插入数据
     * @param string $table 表名
     * @param array $data 数据数组
     * @return int 插入的ID
     */
    public function insert($table, $data)
    {
        $table = $this->tableName($table);
        $fields = array_keys($data);
        $placeholders = array_fill(0, count($fields), '?');
        
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`, `', $fields),
            implode(', ', $placeholders)
        );
        
        $this->query($sql, array_values($data));
        return $this->pdo->lastInsertId();
    }

    /**
     * 更新数据
     * @param string $table 表名
     * @param array $data 数据数组
     * @param string $where WHERE条件
     * @param array $whereParams WHERE参数
     * @return int 影响行数
     */
    public function update($table, $data, $where, $whereParams = [])
    {
        $table = $this->tableName($table);
        $sets = [];
        $params = [];
        
        foreach ($data as $key => $value) {
            $sets[] = "`{$key}` = ?";
            $params[] = $value;
        }
        
        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $table,
            implode(', ', $sets),
            $where
        );
        
        $params = array_merge($params, $whereParams);
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * 删除数据
     * @param string $table 表名
     * @param string $where WHERE条件
     * @param array $params 参数数组
     * @return int 影响行数
     */
    public function delete($table, $where, $params = [])
    {
        $table = $this->tableName($table);
        $sql = sprintf('DELETE FROM `%s` WHERE %s', $table, $where);
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * 开始事务
     */
    public function beginTransaction()
    {
        return $this->pdo->beginTransaction();
    }

    /**
     * 提交事务
     */
    public function commit()
    {
        return $this->pdo->commit();
    }

    /**
     * 回滚事务
     */
    public function rollBack()
    {
        return $this->pdo->rollBack();
    }

    /**
     * 获取最后插入ID
     * @return int 最后插入ID
     */
    public function lastInsertId()
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * 转义字符串
     * @param string $string 待转义字符串
     * @return string 转义后字符串
     */
    public function quote($string)
    {
        return $this->pdo->quote($string);
    }

    /**
     * 检查表是否存在
     * @param string $table 表名
     * @return bool 是否存在
     */
    public function tableExists($table)
    {
        $table = $this->tableName($table);
        $sql = "SHOW TABLES LIKE '{$table}'";
        $result = $this->fetchOne($sql);
        return !empty($result);
    }

    /**
     * 关闭数据库连接
     */
    public function close()
    {
        $this->pdo = null;
        self::$instance = null;
    }
}
