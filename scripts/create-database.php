<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

try {
    if (config('DB_DRIVER', 'sqlite') !== 'sqlsrv') throw new InvalidArgumentException('Для SQLite база создаётся командой init.php.');
    $database = config('DB_DATABASE', 'SchoolDiary');
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $database)) throw new InvalidArgumentException('Некорректное имя базы.');
    $pdo = new PDO('sqlsrv:Server=' . config('DB_SERVER', 'localhost') . ';Database=master;Encrypt=' . config('DB_ENCRYPT', '1')
        . ';TrustServerCertificate=' . config('DB_TRUST_CERTIFICATE', '0'), config('DB_USER') ?: null, config('DB_PASSWORD') ?: null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $statement = $pdo->prepare('SELECT DB_ID(?)');
    $statement->execute([$database]);
    $exists = $statement->fetchColumn();
    $statement->closeCursor();
    if ($exists === null || $exists === false) {
        $pdo->exec("CREATE DATABASE [$database]");
        echo "База $database создана.", PHP_EOL;
    } else echo "База $database уже существует, данные не изменены.", PHP_EOL;
} catch (Throwable $e) {
    if (in_array('--verbose', $argv, true)) fwrite(STDERR, $e->getMessage() . PHP_EOL);
    fwrite(STDERR, $e instanceof InvalidArgumentException ? $e->getMessage() . PHP_EOL : 'Не удалось создать базу. Проверьте соединение и право CREATE DATABASE.' . PHP_EOL);
    exit(1);
}
