<?php

ini_set('default_charset', 'UTF-8');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return substr($haystack, -strlen($needle)) === $needle;
    }
}

if (!function_exists('app_load_env_file')) {
    function app_load_env_file(string $filePath): void
    {
        static $loadedFiles = [];

        if (isset($loadedFiles[$filePath]) || !is_file($filePath) || !is_readable($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $separatorPos = strpos($line, '=');
            if ($separatorPos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separatorPos));
            $value = trim(substr($line, $separatorPos + 1));

            if ($key === '') {
                continue;
            }

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            if (getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        $loadedFiles[$filePath] = true;
    }
}

if (!function_exists('app_bootstrap_env')) {
    function app_bootstrap_env(): void
    {
        static $bootstrapped = false;
        if ($bootstrapped) {
            return;
        }

        $rootDir = dirname(__DIR__);
        app_load_env_file($rootDir . DIRECTORY_SEPARATOR . '.env');
        $bootstrapped = true;
    }
}

if (!function_exists('app_env_value')) {
    function app_env_value(string $key, ?string $default = null): ?string
    {
        app_bootstrap_env();

        $value = getenv($key);
        if ($value === false) {
            return $default;
        }

        return (string) $value;
    }
}

if (!function_exists('app_env_bool')) {
    function app_env_bool(string $key, bool $default = false): bool
    {
        $value = app_env_value($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('app_env')) {
    function app_env(): string
    {
        return strtolower((string) app_env_value('APP_ENV', 'production'));
    }
}

if (!function_exists('app_is_production')) {
    function app_is_production(): bool
    {
        return app_env() === 'production';
    }
}

if (!function_exists('app_request_is_https')) {
    function app_request_is_https(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($forwardedProto === 'https') {
            return true;
        }

        $forwardedSsl = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));
        return $forwardedSsl === 'on';
    }
}

if (!function_exists('app_force_https')) {
    function app_force_https(): bool
    {
        return app_env_bool('APP_FORCE_HTTPS', false);
    }
}

if (!function_exists('app_base_url_configured')) {
    function app_base_url_configured(): ?string
    {
        $configured = trim((string) app_env_value('APP_BASE_URL', ''));
        if ($configured === '') {
            return null;
        }

        $path = (string) parse_url($configured, PHP_URL_PATH);
        $path = trim(str_replace('\\', '/', $path), '/');

        return $path === '' ? '' : '/' . $path;
    }
}

if (!function_exists('app_run_legacy_data_migrations_on_boot')) {
    function app_run_legacy_data_migrations_on_boot(): bool
    {
        return app_env_bool('APP_RUN_LEGACY_DATA_MIGRATIONS_ON_BOOT', false);
    }
}

if (!function_exists('app_session_cookie_secure')) {
    function app_session_cookie_secure(): bool
    {
        return app_env_bool('SESSION_COOKIE_SECURE', app_request_is_https());
    }
}
