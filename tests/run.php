<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$driver = config('DB_DRIVER', 'sqlite');
if ($driver === 'sqlsrv' && !preg_match('/^SchoolDiaryTest_[a-zA-Z0-9_]+$/', config('DB_DATABASE')))
    throw new RuntimeException('SQL Server tests require a dedicated SchoolDiaryTest_* database.');
$database = $driver === 'sqlsrv' ? new Database() : new Database(new PDO('sqlite::memory:'));
$database->initialize();
$database->initialize();
foreach (['sd_grades', 'sd_assignments', 'sd_students', 'sd_subjects', 'sd_classes', 'sd_login_attempts', 'sd_users'] as $table)
    $database->query("DELETE FROM $table");
$diary = new Diary($database);
$passed = 0;

function check(bool $condition, string $name): void
{
    global $passed;
    if (!$condition) throw new RuntimeException("FAIL: $name");
    $passed++;
    echo "PASS: $name", PHP_EOL;
}

function throws(string $type, callable $action, string $name): void
{
    try { $action(); } catch (Throwable $error) {
        if ($error instanceof $type) { check(true, $name); return; }
        throw $error;
    }
    throw new RuntimeException("FAIL: $name (no exception)");
}

$rootActor = ['id' => 0, 'role' => 'admin'];
$adminId = $diary->createUser($rootActor, 'admin', 'Администратор', 'Local-test-password-123', 'admin');
$admin = $diary->user($adminId);
$teacher1Id = $diary->createUser($admin, 'teacher1', 'Учитель 1', 'Local-test-password-123', 'teacher');
$teacher2Id = $diary->createUser($admin, 'teacher2', 'Учитель 2', 'Local-test-password-123', 'teacher');
$teacher1 = $diary->user($teacher1Id);
$teacher2 = $diary->user($teacher2Id);
check($database->scalar('SELECT password_hash FROM sd_users WHERE id = ?', [$adminId]) !== 'Local-test-password-123', 'passwords are hashed');
check((int)$diary->authenticate('ADMIN', 'Local-test-password-123', 'offline')['id'] === $adminId, 'login is case insensitive');
throws(InvalidArgumentException::class, fn() => $diary->authenticate('admin', 'wrong', 'offline'), 'wrong password is rejected');
throws(InvalidArgumentException::class, fn() => $diary->createUser($admin, 'ADMIN', 'A', 'Local-test-password-123', 'teacher'), 'duplicate username is rejected');
throws(AccessDenied::class, fn() => $diary->createUser($teacher1, 'other', 'A', 'Local-test-password-123', 'admin'), 'teacher cannot create accounts');
throws(InvalidArgumentException::class, fn() => $diary->createUser($admin, 'short', 'A', 'short', 'teacher'), 'short password is rejected');
throws(InvalidArgumentException::class, fn() => $diary->createUser($admin, 'role', 'A', 'Local-test-password-123', 'owner'), 'unknown role is rejected');
$class1 = $diary->createClass($admin, 7, 'А');
$class2 = $diary->createClass($admin, 8, 'Б');
$math = $diary->createSubject($admin, 'Математика');
$russian = $diary->createSubject($admin, 'Русский язык');
throws(InvalidArgumentException::class, fn() => $diary->createClass($admin, 7, 'а'), 'class letter is normalized');
throws(InvalidArgumentException::class, fn() => $diary->createClass($admin, 12, 'А'), 'class range is checked');
throws(AccessDenied::class, fn() => $diary->createClass($teacher1, 9, 'А'), 'teacher cannot add classes');
$diary->assign($admin, $class1, $math, $teacher1Id);
$diary->assign($admin, $class1, $russian, $teacher2Id);
$diary->assign($admin, $class2, $russian, $teacher2Id);
check(count($diary->classes($teacher1)) === 1 && count($diary->classes($admin)) === 2, 'class list follows assignments');
throws(AccessDenied::class, fn() => $diary->classFor($teacher1, $class2), 'foreign class cannot be opened');
throws(InvalidArgumentException::class, fn() => $diary->assign($admin, $class1, $math, $adminId), 'only teachers can be assigned');
$student1 = $diary->saveStudent($admin, $class1, 'Ученик 1', 'Пример', '');
$student2 = $diary->saveStudent($admin, $class1, 'Ученик 2', 'Пример', '');
$student3 = $diary->saveStudent($admin, $class2, 'Ученик 3', 'Пример', '');
throws(AccessDenied::class, fn() => $diary->studentFor($teacher1, $student3), 'foreign student cannot be opened');
throws(AccessDenied::class, fn() => $diary->saveStudent($teacher1, $class1, 'X', 'Y', ''), 'teacher cannot change roster');
$diary->saveStudent($admin, $class1, 'Ученик 1', 'Новое имя', '', $student1);
check($diary->studentFor($admin, $student1)['first_name'] === 'Новое имя', 'student profile can be edited');
$date = date('Y-m-d');
$grade1 = $diary->saveGrade($teacher1, $student1, $math, 5, $date, '<script>test</script>');
$grade2 = $diary->saveGrade($teacher1, $student1, $math, 3, $date, '');
$grade3 = $diary->saveGrade($teacher2, $student1, $russian, 2, $date, '');
throws(AccessDenied::class, fn() => $diary->saveGrade($teacher1, $student1, $russian, 5, $date, ''), 'teacher cannot grade another subject');
throws(AccessDenied::class, fn() => $diary->saveGrade($teacher1, $student3, $math, 5, $date, ''), 'teacher cannot grade another class');
throws(InvalidArgumentException::class, fn() => $diary->saveGrade($admin, $student1, $math, 6, $date, ''), 'invalid mark is rejected');
throws(InvalidArgumentException::class, fn() => $diary->saveGrade($admin, $student1, $math, 5, '2026-02-30', ''), 'invalid date is rejected');
throws(InvalidArgumentException::class, fn() => $diary->saveGrade($admin, $student1, $math, 5, date('Y-m-d', time() + 172800), ''), 'future date is rejected');
$roster = $diary->roster($teacher1, $class1, $date, $date);
$withGrades = array_values(array_filter($roster, fn($row) => (int)$row['id'] === $student1))[0];
$withoutGrades = array_values(array_filter($roster, fn($row) => (int)$row['id'] === $student2))[0];
check((float)$withGrades['average_mark'] === 4.0 && (int)$withGrades['marks_count'] === 2, 'average uses only assigned subject marks');
check($withoutGrades['average_mark'] === null && (int)$withoutGrades['marks_count'] === 0, 'students without marks remain in report');
check(count($diary->grades($teacher1, $student1, $date, $date)) === 2, 'foreign subject marks are not exposed');
throws(AccessDenied::class, fn() => $diary->deleteGrade($teacher1, $grade3), 'foreign subject mark cannot be deleted');
$diary->saveGrade($admin, $student1, $math, 4, $date, 'Исправлено', $grade1);
$updated = $diary->gradeFor($admin, $grade1);
check((int)$updated['mark'] === 4 && (int)$updated['created_by'] === $teacher1Id && (int)$updated['updated_by'] === $adminId, 'grade edit preserves author');
$diary->deleteGrade($teacher1, $grade2);
throws(NotFound::class, fn() => $diary->gradeFor($admin, $grade2), 'grade can be deleted');
check(h('<script>') === '&lt;script&gt;', 'HTML values are escaped');
check(str_starts_with(csvValue('=1+1'), "'"), 'CSV formula is escaped');
throws(InvalidArgumentException::class, fn() => reportDates(['from' => '2026-11-01', 'to' => '2026-10-01']), 'reversed report period is rejected');
$injection = "Предмет '; DROP TABLE sd_users;--";
$diary->createSubject($admin, $injection);
check((int)$database->scalar('SELECT COUNT(*) FROM sd_users') === 3, 'SQL values are parameterized');
for ($i = 0; $i < 5; $i++) {
    try { $diary->authenticate('teacher2', 'wrong', 'limit-test'); } catch (InvalidArgumentException) {}
}
throws(OverflowException::class, fn() => $diary->authenticate('teacher2', 'Local-test-password-123', 'limit-test'), 'login rate limit applies across requests');
echo "Tests passed: $passed ($driver)", PHP_EOL;
