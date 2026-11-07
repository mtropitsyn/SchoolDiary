<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

function config(string $key, string $default = ''): string
{
    static $file = null;
    if ($file === null) {
        $file = is_file(APP_ROOT . '/.env') ? parse_ini_file(APP_ROOT . '/.env', false, INI_SCANNER_RAW) : [];
        if ($file === false) throw new RuntimeException('Не удалось прочитать .env.');
    }
    $value = getenv($key);
    return $value !== false ? $value : (string)($file[$key] ?? $default);
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $page = 'classes', array $params = []): string
{
    return '/?' . http_build_query(['page' => $page] + $params);
}

function redirect(string $page, array $params = []): never
{
    header('Location: ' . url($page, $params), true, 303);
    exit;
}

function input(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : '';
}

function positiveId(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) throw new InvalidArgumentException('Некорректный идентификатор.');
    return $id;
}

function textValue(string $value, string $label, int $maximum = 100): string
{
    $value = trim($value);
    if ($value === '' || mb_strlen($value, 'UTF-8') > $maximum || preg_match('/[\x00-\x1F\x7F]/u', $value))
        throw new InvalidArgumentException("Поле «$label» обязательно, максимум $maximum символов.");
    return $value;
}

function validDate(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value)
        throw new InvalidArgumentException('Укажите дату в формате ГГГГ-ММ-ДД.');
    return $value;
}

function reportDates(array $data): array
{
    $year = (int)date('Y') - ((int)date('n') < 9 ? 1 : 0);
    $fromValue = $data['from'] ?? "$year-09-01";
    $toValue = $data['to'] ?? ($year + 1) . '-08-31';
    if (!is_string($fromValue) || !is_string($toValue)) throw new InvalidArgumentException('Некорректный период.');
    $from = validDate($fromValue);
    $to = validDate($toValue);
    if ($from > $to) throw new InvalidArgumentException('Начало периода должно быть раньше окончания.');
    return [$from, $to];
}

function csvValue(mixed $value): string
{
    $text = (string)$value;
    return preg_match('/^[\s]*[=+@\-]/u', $text) ? "'" . $text : $text;
}

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Diary.php';
require_once __DIR__ . '/web.php';
