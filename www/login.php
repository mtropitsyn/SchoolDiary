<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

// Подключение к MS SQL
$serverName = "127.0.0.1"; // или "localhost\SQLEXPRESS"
$connectionOptions = [
    "Database" => "SchoolDatabase",
    "Uid" => "sa",
    "PWD" => "1111",
    "CharacterSet" => "UTF-8"
];

$conn = sqlsrv_connect($serverName, $connectionOptions);
if (!$conn) {
    die(print_r(sqlsrv_errors(), true));
}

// Получение данных из GET
$username = $_GET['login'];
$password = $_GET['password'];

// Подготовка запроса
$sql = "SELECT id, Password_hash FROM Пользователи WHERE Username = ?";
$params = [$username];
$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}

if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $id = $row['id'];
    $hash = $row['Password_hash'];

    if (password_verify($password, $hash)) {
        $_SESSION['user_id'] = $id;
        $_SESSION['username'] = $username;
        header("Location: choose-class.php");
        exit;
    } else {
        echo "❌ Неверный пароль!";
    }
} else {
    echo "❌ Пользователь не найден!";
}

sqlsrv_free_stmt($stmt);
sqlsrv_close($conn);
?>
