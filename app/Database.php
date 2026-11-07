<?php
declare(strict_types=1);

final class Database
{
    public readonly string $driver;
    public readonly PDO $pdo;

    public function __construct(?PDO $connection = null)
    {
        $this->driver = $connection ? $connection->getAttribute(PDO::ATTR_DRIVER_NAME) : config('DB_DRIVER', 'sqlite');
        if ($connection) {
            $this->pdo = $connection;
        } elseif ($this->driver === 'sqlite') {
            $path = str_replace(chr(92), '/', config('DB_PATH', 'var/diary.sqlite'));
            if (!preg_match('~^(?:[A-Za-z]:/|/)~', $path)) $path = APP_ROOT . '/' . $path;
            if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
            $this->pdo = new PDO('sqlite:' . $path);
        } elseif ($this->driver === 'sqlsrv') {
            $database = config('DB_DATABASE', 'SchoolDiary');
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $database))
                throw new InvalidArgumentException('Некорректное имя базы данных.');
            $dsn = 'sqlsrv:Server=' . config('DB_SERVER', 'localhost') . ';Database=' . $database
                . ';Encrypt=' . config('DB_ENCRYPT', '1')
                . ';TrustServerCertificate=' . config('DB_TRUST_CERTIFICATE', '0');
            $this->pdo = new PDO($dsn, config('DB_USER') ?: null, config('DB_PASSWORD') ?: null);
        } else {
            throw new InvalidArgumentException('Поддерживаются SQLite и MS SQL Server.');
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($this->driver === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        }
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    public function all(string $sql, array $params = []): array { return $this->query($sql, $params)->fetchAll(); }
    public function one(string $sql, array $params = []): ?array { return $this->query($sql, $params)->fetch() ?: null; }
    public function scalar(string $sql, array $params = []): mixed { return $this->query($sql, $params)->fetchColumn(); }

    public function insert(string $table, array $values): int
    {
        $identifiers = array_merge([$table], array_keys($values));
        foreach ($identifiers as $identifier)
            if (!preg_match('/^sd_[a-z_]+$|^[a-z_]+$/', $identifier)) throw new LogicException('Invalid SQL identifier.');
        $columns = implode(', ', array_keys($values));
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $output = $this->driver === 'sqlsrv' ? ' OUTPUT INSERTED.id' : '';
        $statement = $this->query("INSERT INTO $table ($columns)$output VALUES ($placeholders)", array_values($values));
        return $this->driver === 'sqlsrv' ? (int)$statement->fetchColumn() : (int)$this->pdo->lastInsertId();
    }

    public function initialize(): void
    {
        $file = APP_ROOT . '/sql/' . ($this->driver === 'sqlsrv' ? 'mssql.sql' : 'sqlite.sql');
        $sql = file_get_contents($file);
        if ($sql === false) throw new RuntimeException('Не найден SQL-скрипт.');
        if ($this->driver === 'sqlsrv') {
            foreach (preg_split('/^GO\s*$/mi', $sql) as $batch)
                if (trim($batch) !== '') $this->pdo->exec($batch);
        } else $this->pdo->exec($sql);
    }
}
