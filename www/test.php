<?php
$classesFile = 'classes.json';
$classes = json_decode(file_get_contents($classesFile), true) ?: [];

// Удаление класса
if (isset($_GET['delete'])) {
    $delete = $_GET['delete'];
    $classes = array_filter($classes, fn($c) => $c !== $delete);
    file_put_contents($classesFile, json_encode(array_values($classes), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    header("Location: test.php");
    exit;
}

// Добавление класса
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['new_class'])) {
    $newClass = mb_strtoupper(trim($_POST['new_class']));
    if ($newClass && !in_array($newClass, $classes)) {
        $classes[] = $newClass;
        sort($classes);
        file_put_contents($classesFile, json_encode($classes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    include 'sync.php';
    header("Location: test.php");
    exit;
}

// Группировка по параллелям
$grouped = [];
foreach ($classes as $class) {
    if (preg_match('/^(\d{1,2})[А-Я]$/u', $class, $m)) {
        $grouped[$m[1]][] = $class;
    } else {
        $grouped['Другое'][] = $class;
    }
}

// Сортировка: 1, 2, 3... затем "Другое"
uksort($grouped, function($a, $b) {
    if ($a === 'Другое') return 1;
    if ($b === 'Другое') return -1;
    return (int)$a - (int)$b;
});
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8" />
    <title>Выбор класса</title>
    <link rel="stylesheet" href="styles.css" />
</head>
<body>
    <div class="container">
        <h1>Выбор класса</h1>

        <?php foreach ($grouped as $grade => $classList): ?>
            <h2><?= is_numeric($grade) ? "{$grade} класс" : $grade ?></h2>
            <ul class="choose-class-container">
                <?php foreach ($classList as $class): ?>
                    <li class="choose-class-list">
                        <a class="choose-class-button"><?= htmlspecialchars($class) ?></a>
                        <a href="?delete=<?= urlencode($class) ?>" class="delete-btn" onclick="return confirm('Удалить класс <?= htmlspecialchars($class) ?>?')">&#65794</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>

        <form method="POST" class="add-form">
            <input type="text" name="new_class" placeholder="Новый класс" required>
            <button type="submit">Добавить</button>
        </form>

        <div class="tools-container">
            <a href="#" onclick="openPopup()">Добавить пользователя</a>
            <a href="exit.php">Выйти</a>
        </div>

        <div id="popup" class="popup-overlay">
        <div class="popup-content">
            <span class="close-btn" onclick="closePopup()">&times;</span>
            <form action="registration.php" class="reg-form">
                <div class="login-form__input">
            <input type="text" name="login" id="login" placeholder="Логин" required />
          </div>
          <div class="login-form__input">
            <input type="password" name="password" id="password" placeholder="Пароль" required />
          </div>
          <div class="login-form__submit-button">
            <input type="submit" value="Добавить пользователя" />
            </form>
        </div>
    </div>
    </div>
</body>
<script src="scripts.js"></script>
</html>
