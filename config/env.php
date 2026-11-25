<?php
declare(strict_types=1);

function cozycafe_load_env(?string $path = null): void
{
    $path = $path ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';

    if (!file_exists($path)) {
        return;
    }

    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('The environment file is not a readable file: ' . $path);
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Unable to read the environment file: ' . $path);
    }

    foreach ($lines as $index => $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $separator = strpos($line, '=');
        if ($separator === false) {
            throw new RuntimeException('Invalid environment entry on line ' . ($index + 1));
        }

        $key = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));

        if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $key)) {
            throw new RuntimeException('Invalid environment key on line ' . ($index + 1));
        }

        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $quote = $value[0];
            if (strlen($value) < 2 || substr($value, -1) !== $quote) {
                throw new RuntimeException('Unclosed quoted value on line ' . ($index + 1));
            }
            $value = substr($value, 1, -1);
        } else {
            $commentPosition = strpos($value, ' #');
            if ($commentPosition !== false) {
                $value = rtrim(substr($value, 0, $commentPosition));
            }
        }

        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

function cozycafe_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

cozycafe_load_env();
