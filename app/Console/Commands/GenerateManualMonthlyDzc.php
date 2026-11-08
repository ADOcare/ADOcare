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
                    $this->line(
                        $day['date'] . ' | štart: ' . ($day['planned_start_time'] ?? $day['departure_time'] ?? '--:--') .
                        ' | návrat: ' . ($day['arrival_time'] ?? '--:--') .
                        ' | ' . $names . ' | ' . $day['distance_km'] . ' km'
                    );
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
                $analysis = $coverage['candidate_analysis'] ?? null;
                if ($analysis !== null && $this->output->isVerbose()) {
                    $this->line('Hodnotené realizovateľné vzory trás: ' . $analysis['evaluated_candidates']);
                    $parts = [];
                    foreach ($analysis['candidates_by_stop_count'] as $stops => $count) {
                        $parts[] = $stops . ' zast.: ' . $count;
                    }
                    $this->line('Rozdelenie kandidátov: ' . implode(', ', $parts));
                    $this->line('Najdlhší hodnotený denný okruh: ' . $analysis['longest_candidate_km'] . ' km');
                    $this->line('Najdlhší okruh bez obmedzených adries: ' . $analysis['longest_unrestricted_candidate_km'] . ' km');
                    $this->line('Potrebný priemer na deň: ' . $analysis['required_average_daily_km'] . ' km');
                    $this->line('Optimistický horný odhad pre kandidátov: ' . $analysis['optimistic_upper_bound_km'] . ' km');
                }
                if ($this->option('preview') && !empty($coverage['visits_per_destination'])) {
                    $this->newLine();
                    $this->line('Počet navrhnutých návštev podľa adresy:');
                    foreach ($coverage['visits_per_destination'] as $stop) {
                        if ($stop['visits'] === 0) {
                            continue;
                        }
                        $limit = $stop['max_visits_per_month'] === null
                            ? '' : ' (max ' . $stop['max_visits_per_month'] . ')';
                        $this->line('  ' . ($stop['name'] ?: $stop['address']) . ': ' . $stop['visits'] . $limit);
                    }
                    $this->line('Nevyužité destinácie: ' . (
                        $coverage['total_destinations'] - $coverage['distinct_destinations']
                    ) . ' (ich návšteva sa nevyžaduje)');
                }
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
