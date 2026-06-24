<?php
declare(strict_types=1);

namespace PortoSender\Portability;

/**
 * Builds RFC-4180 CSV text with spreadsheet formula-injection protection.
 *
 * Any cell whose first character is one of `= + - @` (or a tab/CR) is prefixed
 * with a single quote so spreadsheet applications treat it as text rather than
 * evaluating it as a formula. Escaping is applied *before* RFC-4180 quoting, so
 * the two compose (e.g. `=SUM(A1,A2)` -> `'=SUM(A1,A2)` -> `"'=SUM(A1,A2)"`).
 */
final class CsvWriter
{
    /** First-character triggers for formula injection (all ASCII, single-byte). */
    private const DANGEROUS = ['=', '+', '-', '@', "\t", "\r"];

    public static function escapeCell(string $value): string
    {
        // $value[0] is the first byte; every trigger is ASCII, and a UTF-8
        // multibyte lead byte is always >= 0x80, so this never false-matches.
        if ($value !== '' && in_array($value[0], self::DANGEROUS, true)) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * @param array<int,string> $header
     * @param array<int,array<int,mixed>> $rows
     */
    public static function toString(array $header, array $rows): string
    {
        $out = self::row($header);
        foreach ($rows as $row) {
            $out .= self::row($row);
        }
        return $out;
    }

    /** @param array<int,mixed> $cells */
    private static function row(array $cells): string
    {
        $fields = array_map(
            static fn ($cell): string => self::field(self::escapeCell((string) $cell)),
            array_values($cells)
        );
        return implode(',', $fields) . "\r\n";
    }

    private static function field(string $value): string
    {
        if (preg_match('/[",\r\n]/', $value) === 1) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }
}
