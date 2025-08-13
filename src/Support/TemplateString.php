<?php

namespace Fleetbase\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Class TemplateString.
 *
 * Resolve {placeholders} inside a template string using a model method,
 * then apply optional string modifiers (uppercase, lowercase, capitalize, etc.).
 *
 * Supported syntaxes:
 *   - {variable}
 *   - {modifier variable}
 *   - {variable | modifier | modifier2}
 *
 * Examples:
 *   - "Next stop: {capitalize waypoint.type}" -> "Next stop: Pickup"
 *   - "Ref: {order.number | snake | uppercase}"
 *
 * Escaping:
 *   - Use a backslash to prevent resolution: "\{do not touch}" -> "{do not touch}"
 */
class TemplateString
{
    /**
     * Resolve placeholders in a template string.
     *
     * @param string $template  the string containing {placeholders}
     * @param Model  $target    model used to resolve variables
     * @param string $resolveFn Name of resolver method on the model (default: resolveDynamicProperty).
     *                          Signature should be: function (string $key): mixed
     *
     * @return string the resolved string
     *
     * @throws \InvalidArgumentException if the resolver method does not exist or is not callable
     */
    public static function resolve(string $template, Model $target, string $resolveFn = 'resolveDynamicProperty'): string
    {
        if (!method_exists($target, $resolveFn) || !is_callable([$target, $resolveFn])) {
            throw new \InvalidArgumentException(sprintf('Resolver method "%s" is not callable on %s.', $resolveFn, get_class($target)));
        }

        // Pattern: match { ... } but NOT \{ ... } (escaped with backslash)
        $pattern = '/(?<!\\\\)\{([^{}]+)\}/u';

        $result = preg_replace_callback($pattern, function ($matches) use ($target, $resolveFn) {
            $inner = trim($matches[1]);

            // Parse placeholder into [$variable, $modifiers[]]
            [$variable, $modifiers] = self::parsePlaceholder($inner);

            // Resolve variable using the target model's resolver function.
            // Expecting scalar-ish; cast to string for safety.
            $resolved = $target->{$resolveFn}($variable);

            // If null/false, treat as empty string (adjust if you prefer leaving the original token)
            $resolved = (string) ($resolved ?? '');

            // Apply modifiers, in order.
            foreach ($modifiers as $mod) {
                $resolved = self::applyModifier($resolved, $mod);
            }

            return $resolved;
        }, $template);

        // Unescape "\{...}" -> "{...}" and "\\{" -> "\{"
        $result = str_replace(['\\{', '\\}'], ['{', '}'], $result);

        return $result;
    }

    /**
     * Parse a placeholder content string into a variable key and a list of modifiers.
     *
     * Supports:
     *   1) "variable | mod1 | mod2"
     *   2) "modifier variable"
     *   3) "variable modifier1 modifier2"
     *
     * @return array{0:string,1:array<int,string>} [variable, modifiers]
     */
    protected static function parsePlaceholder(string $inner): array
    {
        // Prefer pipe-chaining if present: variable | mod | mod2
        if (strpos($inner, '|') !== false) {
            $parts     = array_values(array_filter(array_map('trim', explode('|', $inner)), fn ($p) => $p !== ''));
            $variable  = array_shift($parts) ?? '';
            $modifiers = array_map('strtolower', $parts);

            return [$variable, $modifiers];
        }

        // Otherwise, split on whitespace; determine what's the variable vs. modifiers
        $tokens = preg_split('/\s+/u', $inner, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (empty($tokens)) {
            return ['', []];
        }

        // Heuristics:
        // - If first token is a known modifier, treat the LAST token as the variable, and all PRECEDING as modifiers.
        // - Else, treat first token as variable and remaining as modifiers (common case: "waypoint.type capitalize").
        $known = self::knownModifiers();

        if (in_array(strtolower($tokens[0]), $known, true)) {
            $variable = (string) array_pop($tokens); // last token is variable
            $mods     = array_map('strtolower', $tokens);

            return [$variable, $mods];
        }

        $variable = array_shift($tokens);
        $mods     = array_map('strtolower', $tokens);

        return [$variable, $mods];
    }

    /**
     * Apply a single string modifier.
     *
     * Add/adjust mappings as needed. Keep only argument-less helpers here.
     */
    protected static function applyModifier(string $value, string $modifier): string
    {
        switch ($modifier) {
            case 'uppercase':
            case 'upper':
                return Str::upper($value);

            case 'lowercase':
            case 'lower':
                return Str::lower($value);

            case 'capitalize':
            case 'ucfirst':
                return Str::ucfirst($value);

            case 'title':
                return Str::title($value);

            case 'camel':
                return Str::camel($value);

            case 'studly':
                return Str::studly($value);

            case 'snake':
                return Str::snake($value);

            case 'kebab':
            case 'slug':
                return Str::kebab($value);

            case 'plural':
                return Str::plural($value);

            case 'singular':
                return Str::singular($value);

                // No-op / unknown modifier: return unchanged (you may prefer to throw)
            default:
                return $value;
        }
    }

    /**
     * List of recognized modifiers (normalized lowercase).
     *
     * @return array<int,string>
     */
    protected static function knownModifiers(): array
    {
        return [
            'uppercase', 'upper',
            'lowercase', 'lower',
            'capitalize', 'ucfirst',
            'title',
            'camel',
            'studly',
            'snake',
            'kebab', 'slug',
            'plural',
            'singular',
        ];
    }
}
