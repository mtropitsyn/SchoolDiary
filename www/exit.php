<?php
session_start();      // стартуем сессию
session_unset();      // удаляем все переменные сессии
session_destroy();    // уничтожаем сессию полностью

// После выхода перенаправляем на страницу входа
header("Location: index.php");
exit;
?>
