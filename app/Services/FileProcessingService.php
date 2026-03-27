<?php declare(strict_types=1);

namespace App\Services;

use App\Models\FileUpload;
use App\Models\Instrument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class FileProcessingService
{
    private const int BATCH_SIZE = 500;

    /**
     * Mapping from file headers to database column names.
     * Adjust according to your actual table structure.
     */
    private array $headerMapping = [
        'RptDt'          => 'report_date',
        'TckrSymb'       => 'ticker_symbol',
        'Asst'           => 'asset',
        'AsstDesc'       => 'asset_description',
        'SgmtNm'         => 'segment_name',
        'MktNm'          => 'market_name',
        'SctyCtgyNm'     => 'security_category_name',
        'ISIN'           => 'isin',
        'CrpnNm'         => 'company_name'
    ];

    // ── Entry point ───────────────────────────────────────────────────────────

    public function process(FileUpload $upload, string $storagePath): void
    {
        $extension = strtolower(pathinfo($upload->original_name, PATHINFO_EXTENSION));

        match ($extension) {
            'csv' => $this->processCsv($upload, $storagePath),
            'xlsx', 'xls' => $this->processExcel($upload, $storagePath),
            default => throw new InvalidArgumentException("Unsupported file type: $extension"),
        };
    }

    // ── CSV ───────────────────────────────────────────────────────────────────

    private function processCsv(FileUpload $upload, string $path): void
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open file: $path");
        }

        // Remove BOM UTF-8 se presente
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        try {
            // Lê primeira linha para detectar delimitador
            $firstLine = fgets($handle);
            $delimiter = $this->detectDelimiter($firstLine);

            if (substr_count($firstLine, $delimiter) === 0) {
                $firstLine = fgets($handle);
                $delimiter = $this->detectDelimiter($firstLine);
            }

            // Processa firstLine como headers (já foi consumida do handle)
            $headers = array_map('trim', str_getcsv($firstLine, $delimiter));

            $batch = [];
            $total = 0;

            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                if (count($row) !== count($headers)) {
                    continue;
                }

                $data = array_combine($headers, $row);
                $validated = $this->validateAndTransformRow($data);
                if ($validated === null) {
                    continue;
                }

                $batch[] = $this->mapRow($validated, $upload->id);

                if (count($batch) >= self::BATCH_SIZE) {
                    $this->insertBatch($batch);
                    $total += count($batch);
                    $batch  = [];
                    $upload->update(['processed_records' => $total]);
                }
            }

            if (!empty($batch)) {
                $this->insertBatch($batch);
                $total += count($batch);
            }

            $upload->markAsCompleted($total);
        } finally {
            fclose($handle);
        }
    }

    // ── Excel (row-by-row) ───────────────────────────────────────────────────

    private function processExcel(FileUpload $upload, string $path): void
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        $spreadsheet = $reader->load($path);
        $worksheet = $spreadsheet->getActiveSheet();

        $highestRow = $worksheet->getHighestRow();
        $highestColumn = $worksheet->getHighestColumn();

        // Read headers from first row
        $headers = array_map('trim', $worksheet->rangeToArray("A1:{$highestColumn}1", null, true, true, false)[0]);

        $batch = [];
        $total = 0;

        for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
            $rowData = $worksheet->rangeToArray("A{$rowIndex}:{$highestColumn}{$rowIndex}", null, true, true, false)[0];

            if (count($rowData) !== count($headers)) {
                continue;
            }

            $data = array_combine($headers, $rowData);
            $validated = $this->validateAndTransformRow($data);
            if ($validated === null) {
                continue;
            }

            $batch[] = $this->mapRow($validated, $upload->id);

            if (count($batch) >= self::BATCH_SIZE) {
                $this->insertBatch($batch);
                $total += count($batch);
                $batch = [];
                $upload->update(['processed_records' => $total]);
            }
        }

        if (!empty($batch)) {
            $this->insertBatch($batch);
            $total += count($batch);
        }

        $upload->markAsCompleted($total);
    }

    // ── Validation & Transformation ───────────────────────────────────────────

    /**
     * Validate and transform a single row.
     * Returns the transformed array or null if invalid.
     */
    private function validateAndTransformRow(array $row): ?array
    {
        // Required fields
        $required = ['RptDt', 'TckrSymb'];
        foreach ($required as $field) {
            if (empty($row[$field])) {
                Log::warning("Row skipped: missing required field '$field'", $row);
                return null;
            }
        }

        // Convert date to Y-m-d
        $row['RptDt'] = date('Y-m-d', strtotime($row['RptDt']));
        if ($row['RptDt'] === '1970-01-01') { // invalid date
            Log::warning("Row skipped: invalid date", $row);
            return null;
        }

        // Trim all string values
        foreach ($row as $key => $value) {
            if (is_string($value)) {
                $row[$key] = trim(preg_replace('/[\r\n]+/', ' ', $value));
            }
        }

        return $row;
    }

    // ── Mapping ───────────────────────────────────────────────────────────────


    private function mapRow(array $row, string $fileUploadId): array
    {
        $mapped = ['file_upload_id' => $fileUploadId];

        foreach ($row as $key => $value) {
            $mapped[trim($key)] = is_string($value) ? trim($value) : $value;
        }

        $mapped['created_at'] = now();
        $mapped['updated_at'] = now();

        return $mapped;
    }

    // ── Batch Insert with Error Handling ──────────────────────────────────────

    private function insertBatch(array $batch): void
    {
        try {
            DB::transaction(function () use ($batch) {
                Instrument::insert($batch);
            });
        } catch (\Exception $e) {
            Log::error("Batch insert failed, falling back to single inserts", [
                'error' => $e->getMessage(),
                'batch_size' => count($batch),
            ]);

            foreach ($batch as $item) {
                try {
                    Instrument::create($item);
                } catch (\Exception $inner) {
                    Log::warning("Single row insert failed, skipping", [
                        'data' => $item,
                        'error' => $inner->getMessage(),
                    ]);
                }
            }
        }
    }

    // ── Delimiter Detection ───────────────────────────────────────────────────

    private function detectDelimiter(string $line): string
    {
        $delimiters = [',', ';', "\t", '|'];
        $counts = array_map(fn($d) => substr_count($line, $d), $delimiters);
        $maxCount = max($counts);

        if ($maxCount === 0) {
            return ',';
        }

        return $delimiters[array_search($maxCount, $counts)];
    }

    // ── Helper Methods (already present) ──────────────────────────────────────

    public function extractReferenceDate(string $filename): ?string
    {
        if (preg_match('/(\d{8})/', $filename, $matches)) {
            $raw = $matches[1];
            return substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2);
        }
        return null;
    }

    public function hashFile(string $path): string
    {
        return hash_file('sha256', $path);
    }
}
