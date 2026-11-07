IF OBJECT_ID('sd_users', 'U') IS NULL
CREATE TABLE sd_users (
    id INT IDENTITY PRIMARY KEY,
    username VARCHAR(40) NOT NULL UNIQUE,
    display_name NVARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(10) NOT NULL CHECK (role IN ('admin', 'teacher')),
    active BIT NOT NULL DEFAULT 1
);
GO
IF OBJECT_ID('sd_classes', 'U') IS NULL
CREATE TABLE sd_classes (
    id INT IDENTITY PRIMARY KEY,
    year_number INT NOT NULL CHECK (year_number BETWEEN 1 AND 11),
    letter NVARCHAR(1) NOT NULL,
    UNIQUE (year_number, letter)
);
GO
IF OBJECT_ID('sd_subjects', 'U') IS NULL
CREATE TABLE sd_subjects (
    id INT IDENTITY PRIMARY KEY,
    name NVARCHAR(100) NOT NULL UNIQUE
);
GO
IF OBJECT_ID('sd_students', 'U') IS NULL
BEGIN
    CREATE TABLE sd_students (
        id INT IDENTITY PRIMARY KEY,
        class_id INT NOT NULL REFERENCES sd_classes(id),
        last_name NVARCHAR(100) NOT NULL,
        first_name NVARCHAR(100) NOT NULL,
        middle_name NVARCHAR(100) NOT NULL DEFAULT ''
    );
    CREATE INDEX sd_students_class ON sd_students(class_id);
END;
GO
IF OBJECT_ID('sd_assignments', 'U') IS NULL
BEGIN
    CREATE TABLE sd_assignments (
        class_id INT NOT NULL REFERENCES sd_classes(id),
        subject_id INT NOT NULL REFERENCES sd_subjects(id),
        teacher_id INT NOT NULL REFERENCES sd_users(id),
        PRIMARY KEY (class_id, subject_id)
    );
    CREATE INDEX sd_assignments_teacher ON sd_assignments(teacher_id, class_id);
END;
GO
IF OBJECT_ID('sd_grades', 'U') IS NULL
BEGIN
    CREATE TABLE sd_grades (
        id INT IDENTITY PRIMARY KEY,
        student_id INT NOT NULL REFERENCES sd_students(id),
        subject_id INT NOT NULL REFERENCES sd_subjects(id),
        mark INT NOT NULL CHECK (mark BETWEEN 2 AND 5),
        grade_date DATE NOT NULL,
        comment NVARCHAR(500) NOT NULL DEFAULT '',
        created_by INT NOT NULL REFERENCES sd_users(id),
        updated_by INT NOT NULL REFERENCES sd_users(id),
        created_at DATETIME2 NOT NULL,
        updated_at DATETIME2 NOT NULL
    );
    CREATE INDEX sd_grades_student_date ON sd_grades(student_id, grade_date);
END;
GO
IF OBJECT_ID('sd_login_attempts', 'U') IS NULL
CREATE TABLE sd_login_attempts (
    attempt_key CHAR(64) PRIMARY KEY,
    failures INT NOT NULL,
    started_at BIGINT NOT NULL
);
GO
