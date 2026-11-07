<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $db = new Database();
    $db->initialize();
    $diary = new Diary($db);
    $bootstrap = ['id' => 0, 'role' => 'admin'];
    $username = config('ADMIN_USERNAME', 'admin');
    $password = config('ADMIN_PASSWORD');
    if (!$db->scalar('SELECT COUNT(*) FROM sd_users')) {
        if ($password === '') throw new InvalidArgumentException('Задайте ADMIN_PASSWORD перед созданием первого администратора.');
        $adminId = $diary->createUser($bootstrap, $username, 'Администратор', $password, 'admin');
        echo "Администратор создан: $username", PHP_EOL;
    } else {
        $adminId = (int)$db->scalar("SELECT MIN(id) FROM sd_users WHERE role = 'admin'");
    }
    if (in_array('--demo', $argv, true)) {
        if ((int)$db->scalar('SELECT COUNT(*) FROM sd_classes') !== 0)
            throw new InvalidArgumentException('Демо добавляется только в базу без классов.');
        $demoPassword = config('DEMO_PASSWORD');
        if (strlen($demoPassword) < 10 || strlen($demoPassword) > 72)
            throw new InvalidArgumentException('Задайте DEMO_PASSWORD длиной от 10 до 72 байт.');
        $admin = $diary->user($adminId);
        $db->pdo->beginTransaction();
        try {
            $teacher1 = $diary->createUser($admin, 'teacher1', 'Учитель математики', $demoPassword, 'teacher');
            $teacher2 = $diary->createUser($admin, 'teacher2', 'Учитель русского языка', $demoPassword, 'teacher');
            $class1 = $diary->createClass($admin, 7, 'А');
            $class2 = $diary->createClass($admin, 8, 'Б');
            $math = $diary->createSubject($admin, 'Математика');
            $russian = $diary->createSubject($admin, 'Русский язык');
            $db->query('INSERT INTO sd_assignments (class_id, subject_id, teacher_id) VALUES (?, ?, ?)', [$class1, $math, $teacher1]);
            $db->query('INSERT INTO sd_assignments (class_id, subject_id, teacher_id) VALUES (?, ?, ?)', [$class1, $russian, $teacher2]);
            $db->query('INSERT INTO sd_assignments (class_id, subject_id, teacher_id) VALUES (?, ?, ?)', [$class2, $russian, $teacher2]);
            for ($i = 1; $i <= 4; $i++) {
                $student = $diary->saveStudent($admin, $i <= 3 ? $class1 : $class2, "Ученик $i", 'Пример', '');
                $diary->saveGrade($admin, $student, $i <= 3 ? $math : $russian, $i % 3 + 3, date('Y-m-d'), 'Демонстрационная запись');
            }
            $db->pdo->commit();
        } catch (Throwable $e) { $db->pdo->rollBack(); throw $e; }
        echo 'Демо создано. Учителя: teacher1, teacher2. Пароль взят из DEMO_PASSWORD.', PHP_EOL;
    }
    echo 'База готова.', PHP_EOL;
} catch (Throwable $e) {
    if (in_array('--verbose', $argv, true)) fwrite(STDERR, $e->getMessage() . PHP_EOL);
    fwrite(STDERR, $e instanceof InvalidArgumentException ? $e->getMessage() . PHP_EOL : 'Ошибка настройки базы (' . $e::class . '). Проверьте .env и SQL Server.' . PHP_EOL);
    exit(1);
}
