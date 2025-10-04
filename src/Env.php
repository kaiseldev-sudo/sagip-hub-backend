<?php
declare(strict_types=1);

namespace ReliefHub\Backend;

final class Env
{
    /**
     * Load a simple KEY=VALUE .env file from the project root.
     * Lines starting with # are comments. Quotes are supported for values.
     * Returns an associative array of env vars.
     */
    public static function load(string $path): array
    {
        $vars = [];
        if (!is_file($path)) {
            return $_ENV + $_SERVER + $vars;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '#')) {
                continue;
            }
            $pos = strpos($trim, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($trim, 0, $pos));
            $value = trim(substr($trim, $pos + 1));
            if ($value !== '' && ($value[0] === '"' || $value[0] === '\'')) {
                $quote = $value[0];
                if (str_ends_with($value, $quote)) {
                    $value = substr($value, 1, -1);
                } else {
                    $value = substr($value, 1);
                }
            }
            $vars[$key] = $value;
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
        return $_ENV + $_SERVER + $vars;
    }
}


