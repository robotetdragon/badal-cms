<?php

// ═══════════════════════════════════════════════════════════════════════════════
//  core/Config.php — Safe writer for config/config.php
//
//  config.php is a PHP file returning an array. It contains expressions
//  (e.g. dirname(__DIR__) . '/content') that must survive a rewrite, so it is
//  edited in place, key by key, instead of being regenerated with var_export().
//
//  Guarantees:
//    - Values are encoded with var_export(): any string (quotes, backslashes,
//      "$1", newlines...) round-trips exactly and can never escape its literal
//    - Replacements use preg_replace_callback(), so "$n" / "\n" sequences in
//      values are never interpreted as backreferences
//    - The resulting file is syntax-checked before being written; on failure
//      the original file is left untouched
//    - Writes are atomic (temp file + rename) and serialized by a lock file
//
//  Typical usage:
//      Config::set($configFile, 'podcast_title', $title);
//      Config::setMany($configFile, ['author' => $a, 'email' => $m]);
// ═══════════════════════════════════════════════════════════════════════════════

class Config {

    // ═══ PUBLIC API ══════════════════════════════════════════════════════════

    /**
     * Writes a single string key into the config file.
     *
     * @param  string $file   Absolute path to config.php
     * @param  string $key    Config key (letters, digits, underscores)
     * @param  string $value  Raw value (no escaping needed)
     * @return bool           true if the file was written
     */
    public static function set(string $file, string $key, string $value): bool {
        return self::setMany($file, [$key => $value]);
    }

    /**
     * Writes several string keys into the config file in one atomic operation.
     *
     * Existing keys are replaced in place; missing keys are appended before
     * the closing "];" of the returned array.
     *
     * @param  string                $file    Absolute path to config.php
     * @param  array<string, string> $values  Key => raw value
     * @return bool                           true if the file was written
     */
    public static function setMany(string $file, array $values): bool {
        $lock = fopen($file . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            return false;
        }

        try {
            $content = @file_get_contents($file);
            if ($content === false) {
                return false;
            }

            foreach ($values as $key => $value) {
                $content = self::replaceKey($content, (string) $key, (string) $value);
                if ($content === null) {
                    return false;
                }
            }

            if (!self::isValidPhp($content)) {
                return false;
            }

            return self::atomicWrite($file, $content);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    // ═══ PRIVATE HELPERS ═════════════════════════════════════════════════════

    /**
     * Replaces (or appends) one key in the config source.
     *
     * @param  string $content  Current config.php source
     * @param  string $key      Config key
     * @param  string $value    Raw value
     * @return string|null      New source, or null if the key is invalid / no insertion point
     */
    private static function replaceKey(string $content, string $key, string $value): ?string {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $key)) {
            return null;
        }

        // 'key' => 'value' where value may contain escaped quotes/backslashes
        $line    = "'" . $key . "' => " . var_export($value, true);
        $pattern = "/'" . $key . "'\s*=>\s*'(?:[^'\\\\]|\\\\.)*'/";
        $count   = 0;

        $result = preg_replace_callback($pattern, fn() => $line, $content, 1, $count);
        if ($result === null) {
            return null;
        }
        if ($count > 0) {
            return $result;
        }

        // Key not present: insert it before the final "];"
        $result = preg_replace_callback(
            '/\n\];\s*$/',
            fn() => "\n    " . $line . ",\n\n];\n",
            $content,
            1,
            $count
        );

        return $count > 0 ? $result : null;
    }

    /**
     * Checks that a PHP source parses without executing it.
     *
     * @param  string $source  PHP source code
     * @return bool            true if the source is syntactically valid
     */
    private static function isValidPhp(string $source): bool {
        try {
            token_get_all($source, TOKEN_PARSE);
            return true;
        } catch (\ParseError $e) {
            return false;
        }
    }

    /**
     * Writes a file atomically, preserving its permissions.
     *
     * @param  string $file     Destination path
     * @param  string $content  File content
     * @return bool             true on success
     */
    private static function atomicWrite(string $file, string $content): bool {
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));

        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            return false;
        }

        if (file_exists($file)) {
            @chmod($tmp, fileperms($file) & 0777);
        }

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }

        // Make sure the next request reads the new values
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }

        return true;
    }
}
