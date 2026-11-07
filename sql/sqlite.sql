CREATE TABLE IF NOT EXISTS sd_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    display_name TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('admin', 'teacher')),
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1))
);
CREATE TABLE IF NOT EXISTS sd_classes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    year_number INTEGER NOT NULL CHECK (year_number BETWEEN 1 AND 11),
    letter TEXT NOT NULL,
    UNIQUE (year_number, letter)
);
CREATE TABLE IF NOT EXISTS sd_subjects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE
);
CREATE TABLE IF NOT EXISTS sd_students (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    class_id INTEGER NOT NULL REFERENCES sd_classes(id),
    last_name TEXT NOT NULL,
    first_name TEXT NOT NULL,
    middle_name TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS sd_assignments (
    class_id INTEGER NOT NULL REFERENCES sd_classes(id),
    subject_id INTEGER NOT NULL REFERENCES sd_subjects(id),
    teacher_id INTEGER NOT NULL REFERENCES sd_users(id),
    PRIMARY KEY (class_id, subject_id)
);
CREATE TABLE IF NOT EXISTS sd_grades (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL REFERENCES sd_students(id),
    subject_id INTEGER NOT NULL REFERENCES sd_subjects(id),
    mark INTEGER NOT NULL CHECK (mark BETWEEN 2 AND 5),
    grade_date TEXT NOT NULL,
    comment TEXT NOT NULL DEFAULT '',
    created_by INTEGER NOT NULL REFERENCES sd_users(id),
    updated_by INTEGER NOT NULL REFERENCES sd_users(id),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS sd_grades_student_date ON sd_grades(student_id, grade_date);
CREATE INDEX IF NOT EXISTS sd_students_class ON sd_students(class_id);
CREATE INDEX IF NOT EXISTS sd_assignments_teacher ON sd_assignments(teacher_id, class_id);
CREATE TABLE IF NOT EXISTS sd_login_attempts (
    attempt_key TEXT PRIMARY KEY,
    failures INTEGER NOT NULL,
    started_at INTEGER NOT NULL
);
