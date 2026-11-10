<?php

namespace Tests\Unit;

use App\Services\AI\Ocr\MedicalProductMatcher;
use App\Services\AI\Ocr\ScanOcrSource;
use PHPUnit\Framework\TestCase;

class MedicalOcrSafetyTest extends TestCase
{
    public function test_variant_free_brand_name_is_never_an_exact_match(): void
    {
        $matcher = new MedicalProductMatcher();
        $products = [
            ['id' => 2001, 'name' => 'Mepilex Border Flex', 'variant' => '10 × 10 cm', 'alternative_names' => []],
            ['id' => 2002, 'name' => 'Mepilex Border Flex', 'variant' => '15 × 15 cm', 'alternative_names' => []],
        ];
        $match = $matcher->resolve('Mepilex Border Flex', 'Mepilex Border Flex', null, $products);
        $this->assertNull($match['product_id']);
        $this->assertSame('ambiguous', $match['match_type']);
    }

    public function test_exact_variant_has_one_match(): void
    {
        $matcher = new MedicalProductMatcher();
        $products = [
            ['id' => 2001, 'name' => 'Mepilex Border Flex', 'variant' => '10 × 10 cm', 'alternative_names' => []],
            ['id' => 2002, 'name' => 'Mepilex Border Flex', 'variant' => '15 × 15 cm', 'alternative_names' => []],
        ];
        $match = $matcher->resolve('Mepilex Border Flex 10 x 10 cm', '', null, $products);
        $this->assertSame(2001, $match['product_id']);
        $this->assertSame('exact_unique', $match['match_type']);
    }

    public function test_ocr_page_fingerprint_changes_with_content(): void
    {
        $source = new ScanOcrSource();
        $first = $source->fingerprint([['page_index' => 0, 'raw_text' => 'Betadine 10 ml']]);
        $second = $source->fingerprint([['page_index' => 0, 'raw_text' => 'Betadine 20 ml']]);
        $this->assertNotSame($first, $second);
    }
}
