<?php
declare(strict_types=1);

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string {
        $base = dirname(__DIR__);
        return $path ? $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : $base;
    }
}
if (!function_exists('app_path')) {
    function app_path(string $path = ''): string {
        return base_path('app' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : ''));
    }
}
if (!function_exists('config_path')) {
    function config_path(string $path = ''): string {
        return base_path('config' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : ''));
    }
}
if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string {
        return base_path('storage' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : ''));
    }
}
if (!function_exists('asset')) {
    function asset(string $path): string {
        return rtrim((string) env('APP_URL', ''), '/') . '/assets/' . ltrim($path, '/');
    }
}
if (!function_exists('url')) {
    function url(string $path = ''): string {
        $base = rtrim((string) env('APP_URL', ''), '/');
        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}
if (!function_exists('redirect')) {
    function redirect(string $to): never {
        header('Location: ' . $to);
        exit;
    }
}
if (!function_exists('e')) {
    function e(?string $value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        if (is_string($value)) {
            $l = strtolower($value);
            return match ($l) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => $value,
            };
        }
        return $value;
    }
}
