<?php

namespace App\Services\AI\Ocr;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MedicalProductMatcher
{
    /** @return array<int, array<string, mixed>> */
    public function activeProducts(): array
    {
        return DB::table('ai_medical_products')
            ->where('active', true)->orderBy('id')->get()
            ->map(function ($row): array {
                return [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'variant' => (string) ($row->variant ?? ''),
                    'alternative_names' => json_decode((string) ($row->alternative_names ?? '[]'), true) ?: [],
                ];
            })->all();
    }

    public function normalized(string $value): string
    {
        $ascii = Str::ascii(mb_strtolower(str_replace(['×', '✕', '*'], ' x ', $value)));
        return trim((string) preg_replace('/[^a-z0-9]+/u', ' ', $ascii));
    }

    /**
     * Select candidates locally. A name-only match with multiple variants MUST
     * remain ambiguous; no dosage/size can be inferred from a brand alone.
     *
     * @param array<int, array<string, mixed>> $products
     * @return array<int, array<string, mixed>>
     */
    public function shortlist(string $rawText, array $products, int $limit = 20): array
    {
        $raw = ' ' . $this->normalized($rawText) . ' ';
        $terms = preg_split('/\s+/', trim($raw)) ?: [];
        $windows = [];
        for ($i = 0; $i < count($terms); $i++) {
            for ($length = 1; $length <= 4 && $i + $length <= count($terms); $length++) {
                $windows[] = implode(' ', array_slice($terms, $i, $length));
            }
        }
        $windows = array_unique($windows);
        $scores = [];
        foreach ($products as $product) {
            $name = $this->normalized((string) $product['name']);
            if (mb_strlen($name) < 5) {
                continue;
            }
            $names = array_merge([$name], array_map($this->normalized(...), (array) ($product['alternative_names'] ?? [])));
            $best = 0;
            foreach ($names as $alias) {
                if (mb_strlen($alias) < 5) {
                    continue;
                }
                if (str_contains($raw, ' ' . $alias . ' ')) {
                    $best = max($best, 100);
                    break;
                }
                foreach ($windows as $window) {
                    if (abs(strlen($window) - strlen($alias)) > 3) {
                        continue;
                    }
                    $distance = levenshtein($alias, $window);
                    if ($distance <= 2 && $distance < strlen($alias) * 0.25) {
                        $best = max($best, 60 - $distance * 8);
                    }
                }
            }
            if ($best > 0) {
                $scores[] = ['score' => $best, 'product' => $product];
            }
        }
        usort($scores, static fn ($a, $b): int => ($b['score'] <=> $a['score']) ?: ($a['product']['id'] <=> $b['product']['id']));
        return array_map(static fn ($row): array => $row['product'], array_slice($scores, 0, max(0, $limit)));
    }

    /**
     * Only exact brand+variant or unique alias can be considered an exact match.
     * All fuzzy matches and brand-only multi-variant matches stay suggestions.
     *
     * @param array<int, array<string, mixed>> $products
     * @return array{product_id: ?int, match_type: string}
     */
    public function resolve(string $observed, string $proposed, ?int $modelId, array $products): array
    {
        $input = $this->normalized($observed);
        $suggestion = $this->normalized($proposed);
        $matching = [];
        foreach ($products as $product) {
            $keys = [$this->normalized($product['name'] . ' ' . $product['variant'])];
            foreach ($product['alternative_names'] as $alias) {
                $keys[] = $this->normalized((string) $alias);
            }
            if ($input !== '' && in_array($input, $keys, true)) {
                $matching[] = (int) $product['id'];
            }
        }
        if (count($matching) === 1) {
            return ['product_id' => $matching[0], 'match_type' => 'exact_unique'];
        }
        if (count($matching) > 1) {
            return ['product_id' => null, 'match_type' => 'ambiguous'];
        }
        $byId = [];
        foreach ($products as $product) {
            $byId[(int) $product['id']] = $product;
        }
        if ($modelId !== null && isset($byId[$modelId])) {
            $candidate = $byId[$modelId];
            $canonical = $this->normalized($candidate['name']);
            // Even a model-selected ID is a suggestion, not verified fact.
            if ($suggestion !== '' && ($suggestion === $canonical || str_contains($suggestion, $canonical))) {
                return ['product_id' => $modelId, 'match_type' => 'model_suggestion'];
            }
        }
        $matches = [];
        foreach ($products as $product) {
            $name = $this->normalized($product['name']);
            if ($name === $suggestion && $name !== '') {
                $matches[] = (int) $product['id'];
            }
        }
        return count($matches) === 1
            ? ['product_id' => $matches[0], 'match_type' => 'name_suggestion']
            : ['product_id' => null, 'match_type' => count($matches) > 1 ? 'ambiguous' : 'unmatched'];
    }
}
