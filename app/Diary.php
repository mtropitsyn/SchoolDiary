<?php
declare(strict_types=1);

final class AccessDenied extends RuntimeException {}
final class NotFound extends RuntimeException {}

final class Diary
{
    public function __construct(public readonly Database $db) {}

    public function user(int $id): ?array
    {
        return $this->db->one('SELECT id, username, display_name, role, active FROM sd_users WHERE id = ? AND active = 1', [$id]);
    }

    public function requireAdmin(array $actor): void
    {
        if ($actor['role'] !== 'admin') throw new AccessDenied('Доступно только администратору.');
    }

    public function createUser(array $actor, string $username, string $name, string $password, string $role): int
    {
        $this->requireAdmin($actor);
        $username = strtolower(trim($username));
        if (!preg_match('/^[a-z0-9_.-]{3,40}$/', $username))
            throw new InvalidArgumentException('Логин: от 3 до 40 латинских букв, цифр, точек, дефисов или подчёркиваний.');
        $name = textValue($name, 'Имя пользователя');
        if (mb_strlen($password, 'UTF-8') < 10 || strlen($password) > 72 || str_contains($password, "\0"))
            throw new InvalidArgumentException('Пароль: минимум 10 символов, максимум 72 байта.');
        if (!in_array($role, ['admin', 'teacher'], true)) throw new InvalidArgumentException('Неизвестная роль.');
        if ($this->db->one('SELECT id FROM sd_users WHERE username = ?', [$username]))
            throw new InvalidArgumentException('Этот логин уже занят.');
        return $this->db->insert('sd_users', ['username' => $username, 'display_name' => $name,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => $role, 'active' => 1]);
    }

    public function authenticate(string $username, string $password, string $ip): array
    {
        $username = strtolower(trim($username));
        $key = hash('sha256', $ip . '|' . $username);
        $attempt = $this->db->one('SELECT * FROM sd_login_attempts WHERE attempt_key = ?', [$key]);
        $now = time();
        if ($attempt && (int)$attempt['failures'] >= 5 && (int)$attempt['started_at'] > $now - 900)
            throw new OverflowException('Слишком много попыток. Повторите через 15 минут.');
        $user = $this->db->one('SELECT * FROM sd_users WHERE username = ? AND active = 1', [$username]);
        $hash = $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        $valid = password_verify($password, $hash);
        if (!$user || !$valid || strlen($password) > 72) {
            if (!$attempt || (int)$attempt['started_at'] <= $now - 900) {
                $this->db->query('DELETE FROM sd_login_attempts WHERE attempt_key = ?', [$key]);
                $this->db->query('INSERT INTO sd_login_attempts (attempt_key, failures, started_at) VALUES (?, 1, ?)', [$key, $now]);
            } else $this->db->query('UPDATE sd_login_attempts SET failures = failures + 1 WHERE attempt_key = ?', [$key]);
            throw new InvalidArgumentException('Неверный логин или пароль.');
        }
        $this->db->query('DELETE FROM sd_login_attempts WHERE attempt_key = ?', [$key]);
        unset($user['password_hash']);
        return $user;
    }

    public function classes(array $actor): array
    {
        $where = $actor['role'] === 'admin' ? '' : ' WHERE EXISTS (SELECT 1 FROM sd_assignments a WHERE a.class_id = c.id AND a.teacher_id = ?)';
        return $this->db->all('SELECT c.*, (SELECT COUNT(*) FROM sd_students s WHERE s.class_id = c.id) AS students_count FROM sd_classes c'
            . $where . ' ORDER BY c.year_number, c.letter', $actor['role'] === 'admin' ? [] : [$actor['id']]);
    }

    public function classFor(array $actor, int $id): array
    {
        $class = $this->db->one('SELECT * FROM sd_classes WHERE id = ?', [$id]);
        if (!$class) throw new NotFound('Класс не найден.');
        if ($actor['role'] !== 'admin' && !$this->db->one('SELECT class_id FROM sd_assignments WHERE class_id = ? AND teacher_id = ?', [$id, $actor['id']]))
            throw new AccessDenied('Этот класс вам не назначен.');
        return $class;
    }

    public function createClass(array $actor, int $year, string $letter): int
    {
        $this->requireAdmin($actor);
        $letter = mb_strtoupper(trim($letter), 'UTF-8');
        if ($year < 1 || $year > 11 || !preg_match('/^[А-ЯA-Z]$/u', $letter))
            throw new InvalidArgumentException('Класс: параллель от 1 до 11 и одна буква.');
        if ($this->db->one('SELECT id FROM sd_classes WHERE year_number = ? AND letter = ?', [$year, $letter]))
            throw new InvalidArgumentException('Такой класс уже существует.');
        return $this->db->insert('sd_classes', ['year_number' => $year, 'letter' => $letter]);
    }

    public function createSubject(array $actor, string $name): int
    {
        $this->requireAdmin($actor);
        $name = textValue($name, 'Предмет');
        if ($this->db->one('SELECT id FROM sd_subjects WHERE name = ?', [$name]))
            throw new InvalidArgumentException('Такой предмет уже существует.');
        return $this->db->insert('sd_subjects', ['name' => $name]);
    }

    public function assign(array $actor, int $class, int $subject, int $teacher): void
    {
        $this->requireAdmin($actor);
        $this->classFor($actor, $class);
        if (!$this->db->one('SELECT id FROM sd_subjects WHERE id = ?', [$subject])) throw new NotFound('Предмет не найден.');
        if (!$this->db->one("SELECT id FROM sd_users WHERE id = ? AND role = 'teacher' AND active = 1", [$teacher]))
            throw new InvalidArgumentException('Выберите действующего учителя.');
        $this->db->pdo->beginTransaction();
        try {
            $this->db->query('DELETE FROM sd_assignments WHERE class_id = ? AND subject_id = ?', [$class, $subject]);
            $this->db->query('INSERT INTO sd_assignments (class_id, subject_id, teacher_id) VALUES (?, ?, ?)', [$class, $subject, $teacher]);
            $this->db->pdo->commit();
        } catch (Throwable $e) { $this->db->pdo->rollBack(); throw $e; }
    }

    public function subjects(array $actor, ?int $class = null): array
    {
        if ($actor['role'] === 'admin') return $this->db->all('SELECT * FROM sd_subjects ORDER BY name');
        if ($class === null) throw new InvalidArgumentException('Не указан класс.');
        $this->classFor($actor, $class);
        return $this->db->all('SELECT s.* FROM sd_subjects s JOIN sd_assignments a ON a.subject_id = s.id WHERE a.class_id = ? AND a.teacher_id = ? ORDER BY s.name', [$class, $actor['id']]);
    }

    public function saveStudent(array $actor, int $class, string $last, string $first, string $middle, ?int $studentId = null): int
    {
        $this->requireAdmin($actor);
        $this->classFor($actor, $class);
        $last = textValue($last, 'Фамилия');
        $first = textValue($first, 'Имя');
        $middle = trim($middle);
        if ($middle !== '') $middle = textValue($middle, 'Отчество');
        if ($studentId !== null) {
            $student = $this->studentFor($actor, $studentId);
            if ((int)$student['class_id'] !== $class) throw new InvalidArgumentException('Нельзя менять класс этой формой.');
            $this->db->query('UPDATE sd_students SET last_name = ?, first_name = ?, middle_name = ? WHERE id = ?', [$last, $first, $middle, $studentId]);
            return $studentId;
        }
        return $this->db->insert('sd_students', ['class_id' => $class, 'last_name' => $last, 'first_name' => $first, 'middle_name' => $middle]);
    }

    public function studentFor(array $actor, int $id): array
    {
        $student = $this->db->one('SELECT s.*, c.year_number, c.letter FROM sd_students s JOIN sd_classes c ON c.id = s.class_id WHERE s.id = ?', [$id]);
        if (!$student) throw new NotFound('Ученик не найден.');
        $this->classFor($actor, (int)$student['class_id']);
        return $student;
    }

    public function canGrade(array $actor, int $student, int $subject): array
    {
        $row = $this->studentFor($actor, $student);
        if (!$this->db->one('SELECT id FROM sd_subjects WHERE id = ?', [$subject])) throw new NotFound('Предмет не найден.');
        if ($actor['role'] !== 'admin' && !$this->db->one('SELECT class_id FROM sd_assignments WHERE class_id = ? AND subject_id = ? AND teacher_id = ?', [$row['class_id'], $subject, $actor['id']]))
            throw new AccessDenied('Этот предмет вам не назначен.');
        return $row;
    }

    public function saveGrade(array $actor, int $student, int $subject, int $mark, string $date, string $comment, ?int $gradeId = null): int
    {
        $this->canGrade($actor, $student, $subject);
        if ($mark < 2 || $mark > 5) throw new InvalidArgumentException('Оценка должна быть от 2 до 5.');
        $date = validDate($date);
        if ($date > date('Y-m-d')) throw new InvalidArgumentException('Нельзя поставить оценку на будущую дату.');
        $comment = trim($comment);
        if (mb_strlen($comment, 'UTF-8') > 500) throw new InvalidArgumentException('Комментарий: максимум 500 символов.');
        $now = gmdate('Y-m-d H:i:s');
        if ($gradeId !== null) {
            $existing = $this->gradeFor($actor, $gradeId);
            if ((int)$existing['student_id'] !== $student || (int)$existing['subject_id'] !== $subject)
                throw new InvalidArgumentException('Ученик и предмет существующей оценки не меняются.');
            $this->db->query('UPDATE sd_grades SET mark = ?, grade_date = ?, comment = ?, updated_by = ?, updated_at = ? WHERE id = ?', [$mark, $date, $comment, $actor['id'], $now, $gradeId]);
            return $gradeId;
        }
        return $this->db->insert('sd_grades', ['student_id' => $student, 'subject_id' => $subject, 'mark' => $mark,
            'grade_date' => $date, 'comment' => $comment, 'created_by' => $actor['id'], 'updated_by' => $actor['id'],
            'created_at' => $now, 'updated_at' => $now]);
    }

    public function gradeFor(array $actor, int $id): array
    {
        $grade = $this->db->one('SELECT * FROM sd_grades WHERE id = ?', [$id]);
        if (!$grade) throw new NotFound('Оценка не найдена.');
        $this->canGrade($actor, (int)$grade['student_id'], (int)$grade['subject_id']);
        return $grade;
    }

    public function deleteGrade(array $actor, int $id): void
    {
        $this->gradeFor($actor, $id);
        $this->db->query('DELETE FROM sd_grades WHERE id = ?', [$id]);
    }

    public function roster(array $actor, int $class, string $from, string $to): array
    {
        $this->classFor($actor, $class);
        [$from, $to] = reportDates(['from' => $from, 'to' => $to]);
        $extra = $actor['role'] === 'admin' ? '' : ' AND EXISTS (SELECT 1 FROM sd_assignments a WHERE a.class_id = ? AND a.subject_id = g.subject_id AND a.teacher_id = ?)';
        $params = [$from, $to];
        if ($actor['role'] !== 'admin') array_push($params, $class, $actor['id']);
        $params[] = $class;
        return $this->db->all('SELECT s.id, s.last_name, s.first_name, s.middle_name, COUNT(g.id) AS marks_count, AVG(CAST(g.mark AS FLOAT)) AS average_mark FROM sd_students s LEFT JOIN sd_grades g ON g.student_id = s.id AND g.grade_date >= ? AND g.grade_date <= ?'
            . $extra . ' WHERE s.class_id = ? GROUP BY s.id, s.last_name, s.first_name, s.middle_name ORDER BY s.last_name, s.first_name, s.id', $params);
    }

    public function grades(array $actor, int $student, string $from, string $to): array
    {
        $row = $this->studentFor($actor, $student);
        [$from, $to] = reportDates(['from' => $from, 'to' => $to]);
        $extra = $actor['role'] === 'admin' ? '' : ' AND EXISTS (SELECT 1 FROM sd_assignments a WHERE a.class_id = ? AND a.subject_id = g.subject_id AND a.teacher_id = ?)';
        $params = [$student, $from, $to];
        if ($actor['role'] !== 'admin') array_push($params, $row['class_id'], $actor['id']);
        return $this->db->all('SELECT g.*, s.name AS subject_name, u.display_name AS author_name FROM sd_grades g JOIN sd_subjects s ON s.id = g.subject_id JOIN sd_users u ON u.id = g.created_by WHERE g.student_id = ? AND g.grade_date >= ? AND g.grade_date <= ?'
            . $extra . ' ORDER BY g.grade_date DESC, g.id DESC', $params);
    }
}
