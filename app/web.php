<?php
declare(strict_types=1);

function startWebSession(): void
{
    ini_set('display_errors', '0');
    $directory = config('SESSION_PATH', APP_ROOT . '/var/sessions');
    if (!is_dir($directory)) mkdir($directory, 0700, true);
    session_save_path($directory);
    ini_set('session.use_strict_mode', '1');
    session_name('school_diary');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => config('COOKIE_SECURE', '0') === '1', 'path' => '/']);
    session_start();
    if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
}

function csrfField(): string { return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">'; }

function verifyCsrf(): void
{
    $value = $_POST['csrf'] ?? null;
    if (!is_string($value) || !hash_equals($_SESSION['csrf'], $value))
        throw new AccessDenied('Форма устарела. Обновите страницу и повторите действие.');
}

function renderHeader(string $title, ?array $user, ?string $error = null): void
{
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . ' — Дневник</title><link rel="stylesheet" href="/styles.css"><script src="/app.js" defer></script></head><body>';
    echo '<header><a class="brand" href="' . h(url()) . '">Дневник</a>';
    if ($user) {
        echo '<nav><a href="' . h(url()) . '">Классы</a>';
        if ($user['role'] === 'admin') echo '<a href="' . h(url('subjects')) . '">Предметы</a><a href="' . h(url('users')) . '">Пользователи</a>';
        echo '</nav><span class="user-name">' . h($user['display_name']) . '</span><form method="post" action="' . h(url('logout')) . '">' . csrfField() . '<button class="link-button">Выйти</button></form>';
    }
    echo '</header><main><h1>' . h($title) . '</h1>';
    if (isset($_SESSION['flash'])) { echo '<p class="notice">' . h($_SESSION['flash']) . '</p>'; unset($_SESSION['flash']); }
    if ($error) echo '<p class="error" role="alert">' . h($error) . '</p>';
}

function renderFooter(): void { echo '</main><footer>Школьный дневник</footer></body></html>'; }

function field(string $label, string $name, string $type = 'text', string $default = '', bool $required = true): void
{
    $value = $type === 'password' ? '' : input($name, $default);
    echo '<label>' . h($label) . '<input type="' . h($type) . '" name="' . h($name) . '" value="' . h($value) . '"'
        . ($required ? ' required' : '') . ($type === 'password' ? ' minlength="10" maxlength="72" autocomplete="new-password"' : '') . '></label>';
}

function dateFilter(string $page, int $id, string $from, string $to): void
{
    echo '<form class="filters" method="get"><input type="hidden" name="page" value="' . h($page) . '"><input type="hidden" name="id" value="' . $id . '">';
    echo '<label>С <input type="date" name="from" value="' . h($from) . '" required></label><label>По <input type="date" name="to" value="' . h($to) . '" required></label><button>Показать</button></form>';
}
