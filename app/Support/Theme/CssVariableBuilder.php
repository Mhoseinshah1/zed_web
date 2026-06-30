<?php

namespace App\Support\Theme;

/**
 * Pure, side-effect-free helper that turns a resolved `name => value` map into
 * safe CSS. Centralises CSS-variable generation so it is not scattered across
 * views/services. No database access, fully unit-testable.
 */
class CssVariableBuilder
{
    /**
     * Build a `--name:value;` declaration body from a variable map.
     *
     * @param  array<string,string|int|float>  $vars
     */
    public static function declarations(array $vars): string
    {
        $out = '';
        foreach ($vars as $name => $value) {
            $name  = self::safeName($name);
            $value = self::safeValue((string) $value);
            if ($name === '' || $value === '') {
                continue;
            }
            $out .= $name . ':' . $value . ';';
        }
        return $out;
    }

    /**
     * Build a full `selector { … }` rule for the given variables.
     *
     * @param  array<string,string|int|float>  $vars
     */
    public static function block(string $selector, array $vars): string
    {
        return $selector . '{' . self::declarations($vars) . '}';
    }

    /** A custom-property name, normalised to start with `--`. */
    private static function safeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        if (! str_starts_with($name, '--')) {
            $name = '--' . ltrim($name, '-');
        }
        // Allow only sane custom-property characters.
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $name) ?? '';
    }

    /** Strip characters that could break out of a declaration / inject CSS. */
    private static function safeValue(string $value): string
    {
        // No braces, semicolons, angle brackets, or back-slashes in a value.
        $value = preg_replace('/[{};<>\\\\]/', '', $value) ?? '';
        return trim($value);
    }
}
