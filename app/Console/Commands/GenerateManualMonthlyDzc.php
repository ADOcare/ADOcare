<?php

namespace App\Console\Commands;

use App\Services\Transport\ManualMonthlyDzcExporter;
use App\Services\Transport\ManualMonthlyDzcPlanner;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class GenerateManualMonthlyDzc extends Command
{
    protected $signature = 'dzc:generate
        {input : Path to a monthly JSON configuration}
        {--format= : Override JSON output.format (pdf, xlsx, both)}
        {--output-dir= : Output directory (default storage/app/private/dzc-manual/YYYY-MM)}
        {--force : Overwrite existing outputs for this month}
        {--preview : Show the complete proposed daily itinerary without saving PDF/XLSX}';

    protected $description = 'Generate a proposed monthly DZC (Blade PDF, odometer XLSX, or both) from JSON and GPS coordinates.';

    public function handle(ManualMonthlyDzcPlanner $planner, ManualMonthlyDzcExporter $exporter): int
    {
        $inputPath = (string) $this->argument('input');
        if (!is_file($inputPath) || !is_readable($inputPath)) {
            $this->error('Nepodarilo sa prečítať JSON: ' . $inputPath);
            return self::FAILURE;
        }
        try {
            $raw = file_get_contents($inputPath);
            $input = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($input) || array_is_list($input)) {
                throw new \InvalidArgumentException('Vstup musí byť JSON objekt.');
            }
            $format = (string) ($this->option('format') ?: ($input['output']['format'] ?? 'pdf'));
            if (!in_array($format, ['pdf', 'xlsx', 'both'], true)) {
                throw new \InvalidArgumentException('Povolené formáty: pdf, xlsx, both.');
            }
            $this->line('Plánujem NÁVRH mesačných trás cez existujúcu TSP službu...');
            $payload = $planner->plan($input);
            $period = $payload['year'] . '-' . $payload['month'];
            $paths = [];
            if ($this->option('preview')) {
                foreach ($payload['day_totals'] as $day) {
                    $stops = array_values(array_filter(
                        $payload['patient_addresses'][$day['date']] ?? [],
                        static fn (array $stop): bool => ($stop['type'] ?? null) === 'destination'
                    ));
                    $names = implode(' -> ', array_column($stops, 'address'));
                    $this->line($day['date'] . ': ' . $names . ' | ' . $day['distance_km'] . ' km');
                }
            } else {
                $destination = $this->option('output-dir') ?: storage_path('app/private/dzc-manual/' . $period);
                $paths = $exporter->export(
                    $payload, $format, (string) $destination,
                    (bool) $this->option('force')
                );
            }

            $this->newLine();
            $this->info($this->option('preview') ? 'Náhľad DZC NÁVRHU (bez uloženia).' : 'DZC NÁVRH bol vytvorený.');
            $this->line('Obdobie: ' . $period);
            $this->line('Cieľ: ' . $payload['month_totals']['target_km'] . ' km');
            $this->line('Vypočítané: ' . $payload['month_totals']['distance_km'] . ' km');
            $this->line('Rozdiel: ' . $payload['month_totals']['difference_km'] . ' km');
            $this->line('Navrhnuté dni: ' . count($payload['day_totals']));
            $coverage = $payload['coverage'] ?? null;
            if ($coverage !== null) {
                $this->line('Pokryté destinácie: ' . $coverage['distinct_destinations'] . '/' . $coverage['total_destinations']);
                $this->line('Dostupné pracovné dni: ' . $coverage['available_workdays']);
                $this->line('Spolu zastávok vrátane opakovaní: ' . $coverage['total_stops']);
            }
            foreach ($payload['warnings'] as $warning) {
                $this->warn($warning);
            }
            foreach ($paths as $format => $path) {
                $this->line(strtoupper($format) . ': ' . $path);
            }
            $this->warn('Toto je návrh, nie potvrdená evidencia skutočne vykonaných jázd.');
            return self::SUCCESS;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->error($field . ': ' . $message);
                }
            }
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
        }
        return self::FAILURE;
    }
}
