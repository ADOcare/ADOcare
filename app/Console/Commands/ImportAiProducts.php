<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportAiProducts extends Command
{
    protected $signature = 'ai:products:import
        {file=resources/ai/ados_slovakia_products.csv : CSV path relative to project root or absolute}
        {--dry-run : Validate only, no writes}
        {--update-existing : Replace existing catalogue rows from CSV}';

    protected $description = 'Import editable medical product catalogue; preserve manual edits by default.';

    public function handle(): int
    {
        $argument = (string) $this->argument('file');
        $path = str_starts_with($argument, '/') ? $argument : base_path($argument);
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('Readable CSV file not found.');
            return self::FAILURE;
        }
        $file = fopen($path, 'rb');
        if ($file === false) {
            return self::FAILURE;
        }
        try {
            $header = fgetcsv($file, 0, ',', '"', '');
            if (! is_array($header)) {
                throw new \RuntimeException('CSV header missing.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            $expected = ['Product ID', 'Product name', 'Manufacturer', 'Category', 'Variant', 'Alternative names', 'Active'];
            if ($header !== $expected) {
                throw new \RuntimeException('Unexpected CSV columns or column order.');
            }
            $rows = [];
            $line = 1;
            while (($values = fgetcsv($file, 0, ',', '"', '')) !== false) {
                $line++;
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== 7) {
                    throw new \RuntimeException("Invalid column count at row {$line}.");
                }
                $item = array_combine($expected, $values);
                $id = filter_var($item['Product ID'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false || trim($item['Product name']) === '' || trim($item['Category']) === '') {
                    throw new \RuntimeException("Missing/invalid product ID, name or category at row {$line}.");
                }
                if (array_key_exists($id, $rows)) {
                    throw new \RuntimeException("Duplicate product ID {$id} in CSV.");
                }
                $active = strtolower(trim($item['Active']));
                if (! in_array($active, ['yes', 'no', 'true', 'false', '1', '0'], true)) {
                    throw new \RuntimeException("Invalid Active flag at row {$line}.");
                }
                $aliases = trim($item['Alternative names']) === ''
                    ? []
                    : array_values(array_filter(array_map('trim', preg_split('/[;|]/', $item['Alternative names']) ?: [])));
                $rows[$id] = [
                    'id' => $id,
                    'name' => trim($item['Product name']),
                    'manufacturer' => trim($item['Manufacturer']) ?: null,
                    'category' => trim($item['Category']),
                    'variant' => trim($item['Variant']) ?: null,
                    'alternative_names' => json_encode($aliases, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'active' => in_array($active, ['yes', 'true', '1'], true),
                    'origin' => 'ados_csv_2026_11',
                    'updated_at' => now(),
                ];
            }
            $this->info('Validated ' . count($rows) . ' products.');
            if ($this->option('dry-run')) {
                return self::SUCCESS;
            }
            $inserted = 0;
            $updated = 0;
            $skipped = 0;
            DB::transaction(function () use ($rows, &$inserted, &$updated, &$skipped): void {
                foreach ($rows as $id => $row) {
                    $exists = DB::table('ai_medical_products')->where('id', $id)->exists();
                    if ($exists && ! $this->option('update-existing')) {
                        $skipped++;
                        continue;
                    }
                    if ($exists) {
                        DB::table('ai_medical_products')->where('id', $id)->update($row);
                        $updated++;
                    } else {
                        $row['created_at'] = now();
                        DB::table('ai_medical_products')->insert($row);
                        $inserted++;
                    }
                }
            });
            $this->info("Imported: {$inserted}; updated: {$updated}; preserved existing: {$skipped}.");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        } finally {
            fclose($file);
        }
    }
}
