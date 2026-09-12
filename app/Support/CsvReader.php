<?php

declare(strict_types=1);

namespace App\Support;

use Generator;
use RuntimeException;

/**
 * Streaming CSV reader.
 *
 * Rows are yielded one at a time — a 500k-row import must never be held in
 * memory. Delimiter and BOM detection are handled because real customer exports
 * come from Excel as often as from a CRM.
 */
final class CsvReader
{
    private string $delimiter = ',';

    /** @var array<int,string> */
    private array $headers = [];

    public function __construct(private readonly string $path)
    {
        if (!is_readable($this->path)) {
            throw new RuntimeException("CSV file is not readable: {$this->path}");
        }
    }

    public function detectDelimiter(): string
    {
        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            return ',';
        }

        $line = (string) fgets($handle, 8192);
        fclose($handle);

        $line   = $this->stripBom($line);
        $counts = [
            ','  => substr_count($line, ','),
            ';'  => substr_count($line, ';'),
            "\t" => substr_count($line, "\t"),
            '|'  => substr_count($line, '|'),
        ];

        arsort($counts);
        $best = (string) array_key_first($counts);

        return $counts[$best] > 0 ? $best : ',';
    }

    /** @return array<int,string> */
    public function headers(): array
    {
        if ($this->headers !== []) {
            return $this->headers;
        }

        $this->delimiter = $this->detectDelimiter();

        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            return [];
        }

        $row = fgetcsv($handle, 0, $this->delimiter);
        fclose($handle);

        if ($row === false || $row === null) {
            return [];
        }

        $headers = [];

        foreach ($row as $index => $value) {
            $value     = $this->stripBom((string) $value);
            $headers[] = trim($value) !== '' ? trim($value) : 'column_' . ($index + 1);
        }

        return $this->headers = $headers;
    }

    /**
     * Yield each data row as an associative array keyed by header.
     *
     * @return Generator<int,array<string,string>>
     */
    public function rows(int $limit = 0): Generator
    {
        $headers = $this->headers();

        if ($headers === []) {
            return;
        }

        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            return;
        }

        // Skip the header line.
        fgetcsv($handle, 0, $this->delimiter);

        $rowNumber = 1;

        try {
            while (($row = fgetcsv($handle, 0, $this->delimiter)) !== false) {
                if ($row === null || ($row === [null])) {
                    continue;
                }

                $rowNumber++;

                $assoc = [];

                foreach ($headers as $index => $header) {
                    $assoc[$header] = isset($row[$index]) ? trim((string) $row[$index]) : '';
                }

                // Skip entirely blank lines rather than counting them as errors.
                if (implode('', $assoc) === '') {
                    continue;
                }

                yield $rowNumber => $assoc;

                if ($limit > 0 && ($rowNumber - 1) >= $limit) {
                    return;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Row count excluding the header. Streams rather than loading the file.
     */
    public function countRows(): int
    {
        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            return 0;
        }

        $count = 0;

        while (($row = fgetcsv($handle, 0, $this->delimiter)) !== false) {
            if ($row === null) {
                continue;
            }

            if (implode('', array_map(static fn ($v): string => (string) $v, $row)) === '') {
                continue;
            }

            $count++;
        }

        fclose($handle);

        return max(0, $count - 1);
    }

    /** @return array<int,array<string,string>> */
    public function preview(int $rows = 5): array
    {
        $preview = [];

        foreach ($this->rows($rows) as $row) {
            $preview[] = $row;
        }

        return $preview;
    }

    /**
     * Best-effort column mapping from header names, so the mapping step starts
     * pre-filled instead of empty.
     *
     * @return array<string,string> contact field => csv header
     */
    public function guessMapping(): array
    {
        $candidates = [
            'email'           => ['email', 'email address', 'e-mail', 'emailaddress', 'mail'],
            'first_name'      => ['first name', 'firstname', 'given name', 'first'],
            'last_name'       => ['last name', 'lastname', 'surname', 'family name', 'last'],
            'phone'           => ['phone', 'mobile', 'telephone', 'phone number', 'contact number', 'cell'],
            'company'         => ['company', 'business', 'organisation', 'organization', 'company name'],
            'job_title'       => ['job title', 'title', 'position', 'role'],
            'country'         => ['country', 'country code'],
            'state'           => ['state', 'region', 'province'],
            'city'            => ['city', 'suburb', 'town'],
            'postcode'        => ['postcode', 'post code', 'zip', 'zip code', 'postal code'],
            'customer_status' => ['status', 'customer status'],
            'source'          => ['source', 'lead source'],
            'notes'           => ['notes', 'note', 'comments'],
        ];

        $mapping = [];

        foreach ($this->headers() as $header) {
            $normalised = strtolower(trim(preg_replace('/[^a-z0-9 ]/i', ' ', $header) ?? $header));
            $normalised = preg_replace('/\s+/', ' ', $normalised) ?? $normalised;

            foreach ($candidates as $field => $aliases) {
                if (isset($mapping[$field])) {
                    continue;
                }

                if (in_array($normalised, $aliases, true)) {
                    $mapping[$field] = $header;
                    break;
                }
            }
        }

        return $mapping;
    }

    private function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }
}
