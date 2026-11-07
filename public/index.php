<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
startWebSession();

$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'classes';
$error = null;
$user = null;
try {
    if (!in_array(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), ['/', '/index.php'], true))
        throw new NotFound('Страница не найдена.');
    $diary = new Diary(new Database());
    $db = $diary->db;
    $user = isset($_SESSION['user_id']) ? $diary->user((int)$_SESSION['user_id']) : null;
    if (!$user && $page !== 'login') redirect('login');
    if ($user && $page === 'login') redirect('classes');
    if (!in_array($page, ['login', 'logout', 'classes', 'class', 'student', 'subjects', 'users', 'report'], true))
        throw new NotFound('Страница не найдена.');
    if (in_array($page, ['subjects', 'users'], true)) $diary->requireAdmin($user);
    $id = in_array($page, ['class', 'student', 'report'], true) ? positiveId($_GET['id'] ?? '') : null;
    [$from, $to] = reportDates($_GET);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        try {
            if ($page === 'login') {
                $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
                $loggedIn = $diary->authenticate(input('username'), $password, $_SERVER['REMOTE_ADDR'] ?? '');
                session_regenerate_id(true);
                $_SESSION = ['user_id' => $loggedIn['id'], 'csrf' => bin2hex(random_bytes(32))];
                redirect('classes');
            }
            if ($page === 'logout') {
                $_SESSION = [];
                session_destroy();
                setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => config('COOKIE_SECURE', '0') === '1']);
                redirect('login');
            }
            $action = input('action');
            if ($page === 'classes' && $action === 'create_class') {
                $diary->createClass($user, positiveId(input('year_number')), input('letter'));
                $_SESSION['flash'] = 'Класс добавлен.';
                redirect('classes');
            } elseif ($page === 'subjects' && $action === 'create_subject') {
                $diary->createSubject($user, input('name'));
                $_SESSION['flash'] = 'Предмет добавлен.';
                redirect('subjects');
            } elseif ($page === 'users' && $action === 'create_user') {
                $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
                $diary->createUser($user, input('username'), input('display_name'), $password, input('role'));
                $_SESSION['flash'] = 'Пользователь добавлен.';
                redirect('users');
            } elseif ($page === 'class' && $action === 'create_student') {
                $diary->saveStudent($user, $id, input('last_name'), input('first_name'), input('middle_name'));
                $_SESSION['flash'] = 'Ученик добавлен.';
                redirect('class', ['id' => $id]);
            } elseif ($page === 'class' && $action === 'assign') {
                $diary->assign($user, $id, positiveId(input('subject_id')), positiveId(input('teacher_id')));
                $_SESSION['flash'] = 'Учитель назначен.';
                redirect('class', ['id' => $id]);
            } elseif ($page === 'student' && $action === 'save_student') {
                $student = $diary->studentFor($user, $id);
                $diary->saveStudent($user, (int)$student['class_id'], input('last_name'), input('first_name'), input('middle_name'), $id);
                $_SESSION['flash'] = 'Данные ученика сохранены.';
                redirect('student', ['id' => $id]);
            } elseif ($page === 'student' && $action === 'save_grade') {
                $gradeId = input('grade_id') !== '' ? positiveId(input('grade_id')) : null;
                $diary->saveGrade($user, $id, positiveId(input('subject_id')), positiveId(input('mark')), input('grade_date'), input('comment'), $gradeId);
                $_SESSION['flash'] = 'Оценка сохранена.';
                redirect('student', ['id' => $id, 'from' => $from, 'to' => $to]);
            } elseif ($page === 'student' && $action === 'delete_grade') {
                $grade = $diary->gradeFor($user, positiveId(input('grade_id')));
                if ((int)$grade['student_id'] !== $id) throw new AccessDenied('Оценка принадлежит другому ученику.');
                $diary->deleteGrade($user, (int)$grade['id']);
                $_SESSION['flash'] = 'Оценка удалена.';
                redirect('student', ['id' => $id, 'from' => $from, 'to' => $to]);
            } else throw new InvalidArgumentException('Неизвестное действие.');
        } catch (InvalidArgumentException $e) {
            http_response_code($page === 'login' ? 401 : 422);
            $error = $e->getMessage();
        } catch (OverflowException $e) {
            http_response_code(429);
            $error = $e->getMessage();
        }
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET, POST');
        throw new AccessDenied('Метод не поддерживается.');
    }

    if ($page === 'logout') { http_response_code(405); header('Allow: POST'); throw new AccessDenied('Для выхода используйте кнопку в меню.'); }

    if ($page === 'report') {
        $class = $diary->classFor($user, $id);
        $rows = $diary->roster($user, $id, $from, $to);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="class-report-' . $id . '.csv"');
        $stream = fopen('php://output', 'wb');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Класс', 'Ученик', 'С', 'По', 'Количество оценок', 'Средний балл'], ';', '"', '');
        foreach ($rows as $row) fputcsv($stream, array_map('csvValue', [$class['year_number'] . $class['letter'],
            trim($row['last_name'] . ' ' . $row['first_name'] . ' ' . $row['middle_name']), $from, $to,
            $row['marks_count'], $row['average_mark'] === null ? '' : number_format((float)$row['average_mark'], 2, ',', '')]), ';', '"', '');
        fclose($stream);
        exit;
    }

    if ($page === 'login') {
        renderHeader('Вход', null, $error);
        echo '<form class="panel login-form" method="post">' . csrfField();
        field('Логин', 'username');
        echo '<label>Пароль<input type="password" name="password" autocomplete="current-password" required maxlength="72"></label><button>Войти</button></form>';
        renderFooter();
        exit;
    }
    if ($page === 'classes') {
        $classes = $diary->classes($user);
        renderHeader('Классы', $user, $error);
        echo '<div class="class-grid">';
        foreach ($classes as $class) echo '<a class="class-card" href="' . h(url('class', ['id' => $class['id']])) . '"><strong>' . h($class['year_number'] . $class['letter']) . '</strong><span>Учеников: ' . (int)$class['students_count'] . '</span></a>';
        echo '</div>';
        if (!$classes) echo '<p class="muted">Нет доступных классов. Администратор может добавить класс и назначить учителя.</p>';
        if ($user['role'] === 'admin') {
            echo '<section class="panel"><h2>Добавить класс</h2><form class="form-grid" method="post">' . csrfField() . '<input type="hidden" name="action" value="create_class">';
            field('Параллель (1–11)', 'year_number', 'number');
            field('Буква', 'letter');
            echo '<button>Добавить</button></form></section>';
        }
    } elseif ($page === 'class') {
        $class = $diary->classFor($user, $id);
        $students = $diary->roster($user, $id, $from, $to);
        renderHeader('Класс ' . $class['year_number'] . $class['letter'], $user, $error);
        dateFilter('class', $id, $from, $to);
        echo '<a class="secondary" href="' . h(url('report', ['id' => $id, 'from' => $from, 'to' => $to])) . '">Скачать отчёт CSV</a>';
        echo '<div class="table-wrap"><table><thead><tr><th>Ученик</th><th>Оценок за период</th><th>Средний балл</th></tr></thead><tbody>';
        foreach ($students as $student) echo '<tr><td><a href="' . h(url('student', ['id' => $student['id'], 'from' => $from, 'to' => $to])) . '">' . h(trim($student['last_name'] . ' ' . $student['first_name'] . ' ' . $student['middle_name'])) . '</a></td><td>' . (int)$student['marks_count'] . '</td><td>' . ($student['average_mark'] === null ? '—' : h(number_format((float)$student['average_mark'], 2, ',', ''))) . '</td></tr>';
        echo '</tbody></table></div>';
        if (!$students) echo '<p class="muted">В классе пока нет учеников.</p>';
        if ($user['role'] === 'teacher') echo '<p class="muted">Показаны оценки только по назначенным вам предметам.</p>';
        if ($user['role'] === 'admin') {
            echo '<section class="panel"><h2>Добавить ученика</h2><form class="form-grid" method="post">' . csrfField() . '<input type="hidden" name="action" value="create_student">';
            field('Фамилия', 'last_name'); field('Имя', 'first_name'); field('Отчество', 'middle_name', 'text', '', false);
            echo '<button>Добавить</button></form></section><section class="panel"><h2>Учителя и предметы</h2><ul class="assignment-list">';
            $assignments = $db->all('SELECT s.name, u.display_name FROM sd_assignments a JOIN sd_subjects s ON s.id = a.subject_id JOIN sd_users u ON u.id = a.teacher_id WHERE a.class_id = ? ORDER BY s.name', [$id]);
            foreach ($assignments as $assignment) echo '<li>' . h($assignment['name'] . ' — ' . $assignment['display_name']) . '</li>';
            echo '</ul><form class="form-grid" method="post">' . csrfField() . '<input type="hidden" name="action" value="assign"><label>Предмет<select name="subject_id" required><option value="">Выберите</option>';
            foreach ($diary->subjects($user) as $subject) echo '<option value="' . (int)$subject['id'] . '">' . h($subject['name']) . '</option>';
            echo '</select></label><label>Учитель<select name="teacher_id" required><option value="">Выберите</option>';
            foreach ($db->all("SELECT id, display_name FROM sd_users WHERE role = 'teacher' AND active = 1 ORDER BY display_name") as $teacher) echo '<option value="' . (int)$teacher['id'] . '">' . h($teacher['display_name']) . '</option>';
            echo '</select></label><button>Назначить</button></form><p class="muted">Повторное назначение заменяет учителя по выбранному предмету.</p></section>';
        }
    } elseif ($page === 'student') {
        $student = $diary->studentFor($user, $id);
        $subjects = $diary->subjects($user, (int)$student['class_id']);
        $grades = $diary->grades($user, $id, $from, $to);
        $editing = isset($_GET['edit']) ? $diary->gradeFor($user, positiveId($_GET['edit'])) : null;
        if ($editing && (int)$editing['student_id'] !== $id) throw new AccessDenied('Оценка принадлежит другому ученику.');
        renderHeader(trim($student['last_name'] . ' ' . $student['first_name'] . ' ' . $student['middle_name']), $user, $error);
        echo '<p><a href="' . h(url('class', ['id' => $student['class_id'], 'from' => $from, 'to' => $to])) . '">← Класс ' . h($student['year_number'] . $student['letter']) . '</a></p>';
        dateFilter('student', $id, $from, $to);
        echo '<div class="table-wrap"><table><thead><tr><th>Дата</th><th>Предмет</th><th>Оценка</th><th>Комментарий</th><th>Поставил</th><th></th></tr></thead><tbody>';
        foreach ($grades as $grade) {
            echo '<tr><td>' . h($grade['grade_date']) . '</td><td>' . h($grade['subject_name']) . '</td><td><strong class="mark">' . (int)$grade['mark'] . '</strong></td><td>' . h($grade['comment']) . '</td><td>' . h($grade['author_name']) . '</td><td class="actions"><a href="' . h(url('student', ['id' => $id, 'edit' => $grade['id'], 'from' => $from, 'to' => $to])) . '">Изменить</a><form method="post" data-confirm="Удалить оценку?">' . csrfField() . '<input type="hidden" name="action" value="delete_grade"><input type="hidden" name="grade_id" value="' . (int)$grade['id'] . '"><button class="danger link-button">Удалить</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
        if (!$grades) echo '<p class="muted">За этот период оценок нет.</p>';
        if ($subjects) {
            echo '<section class="panel"><h2>' . ($editing ? 'Изменить оценку' : 'Добавить оценку') . '</h2><form class="form-grid" method="post">' . csrfField() . '<input type="hidden" name="action" value="save_grade"><input type="hidden" name="grade_id" value="' . h($editing['id'] ?? '') . '"><label>Предмет<select name="subject_id" required>';
            foreach ($subjects as $subject) if (!$editing || (int)$editing['subject_id'] === (int)$subject['id']) echo '<option value="' . (int)$subject['id'] . '"' . ((string)$subject['id'] === input('subject_id', (string)($editing['subject_id'] ?? '')) ? ' selected' : '') . '>' . h($subject['name']) . '</option>';
            echo '</select></label><label>Оценка<select name="mark" required>';
            foreach ([5, 4, 3, 2] as $mark) echo '<option' . ((string)$mark === input('mark', (string)($editing['mark'] ?? '5')) ? ' selected' : '') . '>' . $mark . '</option>';
            echo '</select></label>';
            field('Дата', 'grade_date', 'date', (string)($editing['grade_date'] ?? date('Y-m-d')));
            echo '<label class="wide">Комментарий<textarea name="comment" maxlength="500">' . h(input('comment', (string)($editing['comment'] ?? ''))) . '</textarea></label><button>Сохранить</button>';
            if ($editing) echo '<a href="' . h(url('student', ['id' => $id])) . '">Отмена</a>';
            echo '</form></section>';
        }
        if ($user['role'] === 'admin') {
            echo '<details class="panel"><summary>Данные ученика</summary><form class="form-grid" method="post">' . csrfField() . '<input type="hidden" name="action" value="save_student">';
            field('Фамилия', 'last_name', 'text', $student['last_name']); field('Имя', 'first_name', 'text', $student['first_name']); field('Отчество', 'middle_name', 'text', $student['middle_name'], false);
            echo '<button>Сохранить</button></form></details>';
        }
    } elseif ($page === 'subjects') {
        renderHeader('Предметы', $user, $error);
        echo '<ul class="plain-list">';
        foreach ($diary->subjects($user) as $subject) echo '<li>' . h($subject['name']) . '</li>';
        echo '</ul><section class="panel"><h2>Добавить предмет</h2><form class="form-grid" method="post">' . csrfField() . '<input type="hidden" name="action" value="create_subject">';
        field('Название', 'name'); echo '<button>Добавить</button></form></section>';
    } elseif ($page === 'users') {
        renderHeader('Пользователи', $user, $error);
        echo '<div class="table-wrap"><table><thead><tr><th>Имя</th><th>Логин</th><th>Роль</th></tr></thead><tbody>';
        foreach ($db->all('SELECT username, display_name, role FROM sd_users ORDER BY display_name') as $account)
            echo '<tr><td>' . h($account['display_name']) . '</td><td>' . h($account['username']) . '</td><td>' . ($account['role'] === 'admin' ? 'Администратор' : 'Учитель') . '</td></tr>';
        echo '</tbody></table></div><section class="panel"><h2>Добавить пользователя</h2><form class="form-grid" method="post">' . csrfField() . '<input type="hidden" name="action" value="create_user">';
        field('Имя', 'display_name'); field('Логин', 'username'); field('Пароль', 'password', 'password');
        echo '<label>Роль<select name="role"><option value="teacher">Учитель</option><option value="admin">Администратор</option></select></label><button>Добавить</button></form></section>';
    }
    renderFooter();
} catch (Throwable $e) {
    $code = $e instanceof AccessDenied ? 403 : ($e instanceof NotFound ? 404 : ($e instanceof InvalidArgumentException ? 422 : 500));
    if (http_response_code() !== 405) http_response_code($code);
    if ($code === 500) error_log('SchoolDiary: ' . $e::class . ', code ' . $e->getCode());
    renderHeader('Не удалось открыть страницу', $user);
    echo '<p class="error">' . h($code === 500 ? 'Проверьте настройки и создание базы: php scripts/init.php.' : $e->getMessage()) . '</p><a href="' . h(url()) . '">К классам</a>';
    renderFooter();
}
