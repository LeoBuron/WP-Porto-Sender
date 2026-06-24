<?php
declare(strict_types=1);

namespace PortoSender\Portability;

/**
 * Parses CSV text into header-keyed row maps, tolerant of column order.
 *
 * Strict by design: a missing required column or a row count over the cap
 * throws, so a caller never silently imports a malformed or oversized file.
 * The proprietary backslash-escape is disabled (escape ''), so parsing is
 * RFC-4180 compliant and round-trips output from {@see CsvWriter}.
 */
final class CsvReader
{
    public function __construct(private int $maxRows = 5000)
    {
    }

    /**
     * @param array<int,string> $requiredHeaders lower-case columns that must be present
     * @return array<int,array<string,string>>
     * @throws \RuntimeException on a missing required column or more than maxRows data rows
     */
    public function parse(string $csv, array $requiredHeaders): array
    {
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            throw new \RuntimeException('Unable to open a stream for CSV parsing.');
        }
        fwrite($fh, $this->stripBom($csv));
        rewind($fh);

        $header = fgetcsv($fh, null, ',', '"', '');
        if ($header === false || $header === [null]) {
            fclose($fh);
            throw new \RuntimeException('CSV is empty: no header row.');
        }
        $header = array_map(static fn ($h): string => strtolower(trim((string) $h)), $header);

        foreach ($requiredHeaders as $required) {
            if (!in_array($required, $header, true)) {
                fclose($fh);
                throw new \RuntimeException('Missing required column: ' . $required);
            }
        }

        $rows = [];
        while (($cols = fgetcsv($fh, null, ',', '"', '')) !== false) {
            if ($cols === [null]) {
                continue; // blank line
            }
            if (count(array_filter($cols, static fn ($c): bool => trim((string) $c) !== '')) === 0) {
                continue; // all-empty row
            }
            if (count($rows) >= $this->maxRows) {
                fclose($fh);
                throw new \RuntimeException('CSV exceeds the maximum of ' . $this->maxRows . ' rows.');
            }
            $map = [];
            foreach ($header as $i => $key) {
                $map[$key] = isset($cols[$i]) ? (string) $cols[$i] : '';
            }
            $rows[] = $map;
        }
        fclose($fh);

        return $rows;
    }

    private function stripBom(string $csv): string
    {
        return str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
    }
}
