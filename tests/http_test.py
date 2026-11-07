import csv
from contextlib import closing
from datetime import datetime, timezone
import http.cookiejar
import io
import os
from pathlib import Path
import re
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY') or shutil.which('php')
assert PHP, 'PHP is required'


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        return None


class Client:
    def __init__(self, base):
        self.base = base
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())

    def request(self, path='/', data=None):
        request = urllib.request.Request(self.base + path,
            data=None if data is None else urllib.parse.urlencode(data).encode())
        try:
            response = self.opener.open(request, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode('utf-8-sig'), response.headers

    def token(self, path='/?page=login'):
        status, html, _ = self.request(path)
        assert status == 200, (path, status)
        return re.search(r'name="csrf" value="([a-f0-9]+)"', html).group(1)

    def login(self, username):
        token = self.token()
        status, _, headers = self.request('/?page=login',
            {'csrf': token, 'username': username, 'password': 'Local-http-password-123'})
        assert status == 303 and 'password' not in headers['Location']


passed = 0


def check(condition, name):
    global passed
    assert condition, name
    passed += 1
    print('PASS:', name)


with tempfile.TemporaryDirectory(prefix='school-diary-http-') as temporary:
    directory = Path(temporary)
    env = os.environ.copy()
    env.update(DB_DRIVER='sqlite', DB_PATH=str(directory / 'diary.sqlite'),
        SESSION_PATH=str(directory / 'sessions'), ADMIN_PASSWORD='Local-http-password-123',
        DEMO_PASSWORD='Local-http-password-123', COOKIE_SECURE='0')
    initialized = subprocess.run([PHP, 'scripts/init.php', '--demo', '--verbose'], cwd=ROOT, env=env,
        capture_output=True, text=True)
    assert initialized.returncode == 0, initialized.stdout + initialized.stderr
    with socket.socket() as socket_:
        socket_.bind(('127.0.0.1', 0))
        port = socket_.getsockname()[1]
    with (directory / 'server.log').open('w', encoding='utf-8') as log:
        server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', 'public'], cwd=ROOT,
            env=env, stdout=log, stderr=subprocess.STDOUT,
            creationflags=subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0)
        try:
            base = f'http://127.0.0.1:{port}'
            anonymous = Client(base)
            for _ in range(100):
                try:
                    status, _, _ = anonymous.request()
                    break
                except OSError:
                    if server.poll() is not None:
                        raise RuntimeError((directory / 'server.log').read_text(encoding='utf-8'))
                    time.sleep(.1)
            check(status == 303, 'anonymous user is redirected to login')
            token = anonymous.token()
            status, _, _ = anonymous.request('/?page=login', {'username': 'admin', 'password': 'Local-http-password-123', 'csrf': 'wrong'})
            check(status == 403, 'login requires CSRF token')
            admin = Client(base)
            admin.login('admin')
            status, html, headers = admin.request()
            check(status == 200 and '7А' in html and '8Б' in html, 'administrator sees all classes')
            check("frame-ancestors 'none'" in headers['Content-Security-Policy'], 'security headers are present')
            token = admin.token('/')
            status, _, _ = admin.request('/', {'csrf': token, 'action': 'create_class', 'year_number': '9', 'letter': 'В'})
            check(status == 303, 'class can be created through form')
            status, _, _ = admin.request('/?page=class&id=3', {'csrf': token, 'action': 'create_student', 'last_name': 'Новый', 'first_name': 'Ученик', 'middle_name': ''})
            check(status == 303, 'student can be added through form')
            status, _, _ = admin.request('/?page=users', {'csrf': token, 'action': 'create_user', 'username': 'teacher3', 'display_name': 'Учитель 3', 'password': 'Local-http-password-123', 'role': 'teacher'})
            check(status == 303, 'administrator can register teacher')
            status, _, _ = admin.request('/?page=class&id=3', {'csrf': token, 'action': 'assign', 'subject_id': '1', 'teacher_id': '2'})
            check(status == 303, 'teacher can be assigned to a class and subject')
            teacher = Client(base)
            teacher.login('teacher1')
            status, html, _ = teacher.request('/')
            check(status == 200 and '7А' in html and '9В' in html and '8Б' not in html, 'teacher sees only assigned classes')
            check(teacher.request('/?page=class&id=2')[0] == 403, 'teacher cannot open another class by URL')
            check(teacher.request('/?page=student&id=4')[0] == 403, 'teacher cannot open another student by URL')
            check(teacher.request('/?page=users')[0] == 403, 'teacher cannot open user registration')
            teacher_token = teacher.token('/?page=student&id=5')
            status, _, _ = teacher.request('/?page=student&id=5', {'csrf': teacher_token, 'action': 'save_grade', 'subject_id': '2', 'mark': '5', 'grade_date': datetime.now(timezone.utc).date().isoformat(), 'comment': ''})
            check(status == 403, 'teacher cannot grade an unassigned subject')
            grade = {'csrf': teacher_token, 'action': 'save_grade', 'subject_id': '1', 'mark': '4',
                'grade_date': datetime.now(timezone.utc).date().isoformat(), 'comment': '<script>alert(1)</script>'}
            status, _, _ = teacher.request('/?page=student&id=5', grade)
            check(status == 303, 'teacher can save a grade')
            status, html, _ = teacher.request('/?page=student&id=5')
            check(status == 200 and '&lt;script&gt;' in html and '<script>alert' not in html, 'saved comment is HTML escaped')
            check(teacher.request('/?page=student&id=5', {**grade, 'mark': '7'})[0] == 422, 'invalid mark is rejected by form')
            with closing(sqlite3.connect(directory / 'diary.sqlite')) as db:
                grade_id = db.execute('SELECT id FROM sd_grades WHERE student_id = 5').fetchone()[0]
            status, _, _ = teacher.request('/?page=student&id=5', {'csrf': 'wrong', 'action': 'delete_grade', 'grade_id': grade_id})
            check(status == 403, 'grade deletion requires CSRF token')
            teacher.request(f'/?page=student&id=5&action=delete_grade&grade_id={grade_id}')
            with closing(sqlite3.connect(directory / 'diary.sqlite')) as db:
                check(db.execute('SELECT COUNT(*) FROM sd_grades WHERE id = ?', [grade_id]).fetchone()[0] == 1, 'GET cannot delete a grade')
            status, csv_text, headers = teacher.request('/?page=report&id=3')
            report = list(csv.reader(io.StringIO(csv_text), delimiter=';'))
            check(status == 200 and report[1][-1] == '4,00' and 'text/csv' in headers['Content-Type'], 'CSV report contains calculated average')
            grade.update(grade_id=str(grade_id), mark='5', comment='Исправлено')
            check(teacher.request('/?page=student&id=5', grade)[0] == 303, 'grade can be edited')
            check(teacher.request('/?page=student&id=5', {'csrf': teacher_token, 'action': 'delete_grade', 'grade_id': grade_id})[0] == 303, 'grade can be deleted with valid form')
            check(teacher.request('/?page=student&id=5&from=2026-12-01&to=2026-01-01')[0] == 422, 'invalid report period is rejected')
            check(teacher.request('/info.php')[0] == 404, 'phpinfo endpoint is absent')
            token = teacher.token('/')
            check(teacher.request('/?page=logout', {'csrf': token})[0] == 303 and teacher.request('/')[0] == 303, 'logout clears authenticated session')
            limited = Client(base)
            for _ in range(5):
                token = limited.token()
                limited.request('/?page=login', {'csrf': token, 'username': 'teacher2', 'password': 'wrong'})
            token = limited.token()
            check(limited.request('/?page=login', {'csrf': token, 'username': 'teacher2', 'password': 'Local-http-password-123'})[0] == 429, 'HTTP login rate limit applies')
            print(f'HTTP tests passed: {passed}')
        finally:
            server.terminate()
            server.wait(timeout=10)
