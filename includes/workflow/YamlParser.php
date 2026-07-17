<?php
/**
 * Minimal indented YAML parser for Temper workflow definitions.
 *
 * Supports the subset needed for workflow YAML:
 * - maps, lists, nested structures
 * - scalars: strings, int, float, bool, null
 * - single- and double-quoted strings
 * - # comments
 * - block list items (-)
 *
 * Not a full YAML 1.2 implementation (no anchors, multi-doc, complex tags).
 * Definitions are authored externally and validated against DefinitionDictionary.
 *
 * Security: Prevent direct access.
 */
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    header('Location: ../../login.php');
    exit;
}

final class WorkflowYamlParser
{
    /**
     * Parse a YAML string into a PHP array.
     *
     * @return array<string, mixed>
     * @throws InvalidArgumentException on syntax errors
     */
    public static function parse(string $yaml): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $yaml) ?: [];
        $normalized = [];
        foreach ($lines as $i => $line) {
            // Strip full-line comments; preserve inline comments carefully
            if (preg_match('/^\s*#/', $line)) {
                continue;
            }
            if (trim($line) === '') {
                continue;
            }
            $normalized[] = ['num' => $i + 1, 'raw' => $line];
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('YAML document is empty.');
        }

        $idx = 0;
        $result = self::parseBlock($normalized, $idx, 0);
        if (!is_array($result) || self::isList($result)) {
            throw new InvalidArgumentException('Workflow YAML root must be a mapping (key: value).');
        }
        return $result;
    }

    /**
     * Parse a YAML file.
     *
     * @return array<string, mixed>
     */
    public static function parseFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('YAML file is not readable: ' . $path);
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new InvalidArgumentException('Could not read YAML file: ' . $path);
        }
        return self::parse($contents);
    }

    /**
     * @param list<array{num:int,raw:string}> $lines
     * @return mixed
     */
    private static function parseBlock(array $lines, int &$idx, int $minIndent): mixed
    {
        if ($idx >= count($lines)) {
            return [];
        }

        $first = $lines[$idx];
        $indent = self::indentOf($first['raw']);
        if ($indent < $minIndent) {
            return [];
        }

        $trimmed = ltrim($first['raw']);
        if (str_starts_with($trimmed, '- ')) {
            return self::parseList($lines, $idx, $indent);
        }
        return self::parseMap($lines, $idx, $indent);
    }

    /**
     * @param list<array{num:int,raw:string}> $lines
     * @return array<string, mixed>
     */
    private static function parseMap(array $lines, int &$idx, int $indent): array
    {
        $map = [];
        while ($idx < count($lines)) {
            $line = $lines[$idx];
            $lineIndent = self::indentOf($line['raw']);
            if ($lineIndent < $indent) {
                break;
            }
            if ($lineIndent > $indent) {
                throw new InvalidArgumentException(
                    'Unexpected indentation at line ' . $line['num'] . '.'
                );
            }

            $trimmed = self::stripInlineComment(ltrim($line['raw']));
            if (str_starts_with($trimmed, '- ')) {
                throw new InvalidArgumentException(
                    'Unexpected list item in mapping at line ' . $line['num'] . '.'
                );
            }

            if (!preg_match('/^([^:]+):(.*)$/', $trimmed, $m)) {
                throw new InvalidArgumentException(
                    'Expected "key: value" at line ' . $line['num'] . '.'
                );
            }

            $key = trim($m[1]);
            if ($key === '' || preg_match('/\s/', $key)) {
                // Allow keys with spaces only if quoted — reject unquoted spaces for simplicity
                if (!preg_match('/^["\'].*["\']$/', $key)) {
                    // Still accept simple keys; strip quotes if present
                }
            }
            $key = self::unquote(trim($key));
            $rest = trim($m[2]);
            $idx++;

            if ($rest === '' || $rest === '|' || $rest === '>') {
                // Nested block or empty value
                if ($idx < count($lines)) {
                    $nextIndent = self::indentOf($lines[$idx]['raw']);
                    if ($nextIndent > $indent) {
                        $map[$key] = self::parseBlock($lines, $idx, $nextIndent);
                        continue;
                    }
                }
                $map[$key] = $rest === '' ? null : '';
                continue;
            }

            $map[$key] = self::parseScalar($rest, $line['num']);
        }
        return $map;
    }

    /**
     * @param list<array{num:int,raw:string}> $lines
     * @return list<mixed>
     */
    private static function parseList(array $lines, int &$idx, int $indent): array
    {
        $list = [];
        while ($idx < count($lines)) {
            $line = $lines[$idx];
            $lineIndent = self::indentOf($line['raw']);
            if ($lineIndent < $indent) {
                break;
            }
            if ($lineIndent > $indent) {
                throw new InvalidArgumentException(
                    'Unexpected indentation in list at line ' . $line['num'] . '.'
                );
            }

            $trimmed = self::stripInlineComment(ltrim($line['raw']));
            if (!str_starts_with($trimmed, '- ')) {
                break;
            }

            $rest = trim(substr($trimmed, 2));
            $idx++;

            if ($rest === '' || $rest === '|' || $rest === '>') {
                if ($idx < count($lines)) {
                    $nextIndent = self::indentOf($lines[$idx]['raw']);
                    if ($nextIndent > $indent) {
                        $list[] = self::parseBlock($lines, $idx, $nextIndent);
                        continue;
                    }
                }
                $list[] = null;
                continue;
            }

            // Inline map on list item: "- key: value"
            if (preg_match('/^([^:]+):\s*(.*)$/', $rest, $m) && !self::isQuotedScalar($rest)) {
                $itemKey = self::unquote(trim($m[1]));
                $itemRest = trim($m[2]);
                $item = [];
                if ($itemRest === '') {
                    if ($idx < count($lines)) {
                        $nextIndent = self::indentOf($lines[$idx]['raw']);
                        if ($nextIndent > $indent) {
                            // Nested content under this list map item
                            $nested = self::parseBlock($lines, $idx, $nextIndent);
                            if (is_array($nested) && !self::isList($nested)) {
                                $item[$itemKey] = null;
                                // Merge sibling keys at nested indent as part of same map
                                // Re-parse: put key with empty then nested map keys
                                // Actually nested block is either map continuing the item or value
                                // Standard YAML: "- key:\n    nested: x" means item = {key: {nested:x}} if nested under key
                                // Simpler approach used by many minimal parsers:
                                $item[$itemKey] = $nested;
                            } else {
                                $item[$itemKey] = $nested;
                            }
                            // Continue reading sibling keys of the list-item map at indent+2
                            $siblingIndent = $indent + 2;
                            while ($idx < count($lines)) {
                                $sib = $lines[$idx];
                                $sibIndent = self::indentOf($sib['raw']);
                                if ($sibIndent < $siblingIndent) {
                                    break;
                                }
                                if ($sibIndent > $siblingIndent) {
                                    break;
                                }
                                $sibTrim = self::stripInlineComment(ltrim($sib['raw']));
                                if (str_starts_with($sibTrim, '- ')) {
                                    break;
                                }
                                if (!preg_match('/^([^:]+):(.*)$/', $sibTrim, $sm)) {
                                    break;
                                }
                                $sKey = self::unquote(trim($sm[1]));
                                $sRest = trim($sm[2]);
                                $idx++;
                                if ($sRest === '') {
                                    if ($idx < count($lines) && self::indentOf($lines[$idx]['raw']) > $siblingIndent) {
                                        $item[$sKey] = self::parseBlock($lines, $idx, self::indentOf($lines[$idx]['raw']));
                                    } else {
                                        $item[$sKey] = null;
                                    }
                                } else {
                                    $item[$sKey] = self::parseScalar($sRest, $sib['num']);
                                }
                            }
                            $list[] = $item;
                            continue;
                        }
                    }
                    $item[$itemKey] = null;
                } else {
                    $item[$itemKey] = self::parseScalar($itemRest, $line['num']);
                }

                // Sibling keys for list-item map (same indent+2)
                $siblingIndent = $indent + 2;
                while ($idx < count($lines)) {
                    $sib = $lines[$idx];
                    $sibIndent = self::indentOf($sib['raw']);
                    if ($sibIndent < $siblingIndent) {
                        break;
                    }
                    if ($sibIndent > $siblingIndent) {
                        // Nested under last key — should not happen if value was inline
                        break;
                    }
                    $sibTrim = self::stripInlineComment(ltrim($sib['raw']));
                    if (str_starts_with($sibTrim, '- ')) {
                        break;
                    }
                    if (!preg_match('/^([^:]+):(.*)$/', $sibTrim, $sm)) {
                        break;
                    }
                    $sKey = self::unquote(trim($sm[1]));
                    $sRest = trim($sm[2]);
                    $idx++;
                    if ($sRest === '') {
                        if ($idx < count($lines) && self::indentOf($lines[$idx]['raw']) > $siblingIndent) {
                            $item[$sKey] = self::parseBlock($lines, $idx, self::indentOf($lines[$idx]['raw']));
                        } else {
                            $item[$sKey] = null;
                        }
                    } else {
                        $item[$sKey] = self::parseScalar($sRest, $sib['num']);
                    }
                }
                $list[] = $item;
                continue;
            }

            $list[] = self::parseScalar($rest, $line['num']);
        }
        return $list;
    }

    private static function parseScalar(string $value, int $lineNum): mixed
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // Quoted strings
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            return self::unquote($value);
        }

        // Empty flow collections only (non-empty flow style is not supported)
        if ($value === '[]') {
            return [];
        }
        if ($value === '{}') {
            return [];
        }
        if (str_starts_with($value, '[') || str_starts_with($value, '{')) {
            throw new InvalidArgumentException(
                "Inline flow collections are not supported at line {$lineNum}. Use block style (or [] / {} empty)."
            );
        }

        $lower = strtolower($value);
        if ($lower === 'true' || $lower === 'yes' || $lower === 'on') {
            return true;
        }
        if ($lower === 'false' || $lower === 'no' || $lower === 'off') {
            return false;
        }
        if ($lower === 'null' || $lower === '~' || $lower === '') {
            return null;
        }

        if (preg_match('/^-?\d+$/', $value)) {
            return (int)$value;
        }
        if (preg_match('/^-?\d+\.\d+$/', $value)) {
            return (float)$value;
        }

        return $value;
    }

    private static function unquote(string $value): string
    {
        $value = trim($value);
        if (strlen($value) >= 2) {
            $q = $value[0];
            if (($q === '"' || $q === "'") && str_ends_with($value, $q)) {
                $inner = substr($value, 1, -1);
                if ($q === '"') {
                    $inner = str_replace(
                        ['\\\\', '\\"', '\\n', '\\t'],
                        ['\\', '"', "\n", "\t"],
                        $inner
                    );
                }
                return $inner;
            }
        }
        return $value;
    }

    private static function isQuotedScalar(string $value): bool
    {
        $value = trim($value);
        return (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"));
    }

    private static function indentOf(string $line): int
    {
        if (preg_match('/^( *)/', $line, $m)) {
            return strlen($m[1]);
        }
        return 0;
    }

    private static function stripInlineComment(string $line): string
    {
        $inSingle = false;
        $inDouble = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                continue;
            }
            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                continue;
            }
            if ($ch === '#' && !$inSingle && !$inDouble) {
                // Require space before # for inline comments (YAML-ish)
                if ($i === 0 || ctype_space($line[$i - 1])) {
                    return rtrim(substr($line, 0, $i));
                }
            }
        }
        return rtrim($line);
    }

    private static function isList(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}
