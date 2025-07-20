<?php

$classesFile = 'classes.json';
$classesFromJson = json_decode(file_get_contents($classesFile), true);

// Подключение к MSSQL
$serverName = "localhost";
$connectionOptions = [
    "Database" => "SchoolDatabase",
    "Uid" => "sa",
    "PWD" => "1111",
    "CharacterSet" => "UTF-8"
];
$conn = sqlsrv_connect($serverName, $connectionOptions);
if (!$conn) die("Ошибка подключения: " . print_r(sqlsrv_errors(), true));

// Получение текущих классов из БД
$sql = "SELECT Название_класса FROM Класс";
$stmt = sqlsrv_query($conn, $sql);
$dbClasses = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $dbClasses[] = $row['Название_класса'];
}

// Добавляем недостающие классы
$toAdd = array_diff($classesFromJson, $dbClasses);
$insertSql = "INSERT INTO Класс (Название_класса) VALUES (?)";
foreach ($toAdd as $class) {
    $stmt = sqlsrv_prepare($conn, $insertSql, [$class]);
    sqlsrv_execute($stmt);
}


sqlsrv_close($conn);
?>
