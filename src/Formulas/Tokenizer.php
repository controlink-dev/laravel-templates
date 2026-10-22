<?php

namespace Controlink\Templates\Formulas;

/**
 * Splits formula text into tokens using a character whitelist: anything outside
 * letters, digits, `_ . " , ( ) + - * / = < > &` and spaces is a syntax error,
 * so `{`, `}`, `|`, `%`, `^` and friends can never reach the parser. Text
 * literals are the one place any character is allowed, because they are data.
 *
 * Token shapes: ['t' => 'num'|'str'|'ident'|'op'|'(' |')'|',', 'v' => mixed].
 * Identifiers keep their dots (`lines.total`); the parser decides whether one
 * is a reference, a function name or TRUE/FALSE.
 */
final class Tokenizer
{
    public const MAX_LENGTH = 1000;

    /**
     * @return list<array{t: string, v: mixed}>
     */
    public static function tokenize(string $source): array
    {
        if (mb_strlen($source) > self::MAX_LENGTH) {
            throw new FormulaException('syntax', 'Formula is too long.');
        }

        $tokens = [];
        $length = strlen($source);
        $i = 0;

        while ($i < $length) {
            $c = $source[$i];

            if ($c === ' ') {
                $i++;
            } elseif ($c === '"') {
                $value = '';
                $i++;
                while (true) {
                    if ($i >= $length) {
                        throw new FormulaException('syntax', 'Unterminated text.');
                    }
                    if ($source[$i] === '"') {
                        if (($source[$i + 1] ?? '') === '"') {
                            $value .= '"';
                            $i += 2;

                            continue;
                        }
                        $i++;
                        break;
                    }
                    $value .= $source[$i++];
                }
                $tokens[] = ['t' => 'str', 'v' => $value];
            } elseif (ctype_digit($c)) {
                preg_match('/\G\d+(?:\.\d+)?/', $source, $m, 0, $i);
                $i += strlen($m[0]);
                // `12abc` and `1..5` must not silently split into two tokens.
                if ($i < $length && (ctype_alpha($source[$i]) || $source[$i] === '_')) {
                    throw new FormulaException('syntax', 'Invalid number.');
                }
                $tokens[] = ['t' => 'num', 'v' => str_contains($m[0], '.') ? (float) $m[0] : (int) $m[0]];
            } elseif (ctype_alpha($c) || $c === '_') {
                preg_match('/\G[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)*/', $source, $m, 0, $i);
                $i += strlen($m[0]);
                $tokens[] = ['t' => 'ident', 'v' => $m[0]];
            } elseif (in_array($c, ['(', ')', ','], true)) {
                $tokens[] = ['t' => $c, 'v' => $c];
                $i++;
            } elseif (in_array($c, ['+', '-', '*', '/', '=', '&'], true)) {
                $tokens[] = ['t' => 'op', 'v' => $c];
                $i++;
            } elseif ($c === '<' || $c === '>') {
                $pair = $c.($source[$i + 1] ?? '');
                if (in_array($pair, ['<>', '<=', '>='], true)) {
                    $tokens[] = ['t' => 'op', 'v' => $pair];
                    $i += 2;
                } else {
                    $tokens[] = ['t' => 'op', 'v' => $c];
                    $i++;
                }
            } else {
                throw new FormulaException('syntax', 'Character not allowed.');
            }
        }

        return $tokens;
    }
}
