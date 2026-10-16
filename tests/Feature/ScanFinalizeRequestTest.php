<?php

namespace Tests\Feature;

use App\Http\Requests\Api\ScanFinalizeRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

class ScanFinalizeRequestTest extends TestCase
{
    public function test_reference_date_is_required_and_uses_api_date_format(): void
    {
        $rules = (new ScanFinalizeRequest())->rules()['reference_date'];
        $validator = new Factory(new Translator(new ArrayLoader(), 'en'));

        self::assertTrue($validator->make([], ['reference_date' => $rules])->fails());
        self::assertTrue($validator->make(
            ['reference_date' => '16.10.2026'],
            ['reference_date' => $rules],
        )->fails());
        self::assertFalse($validator->make(
            ['reference_date' => '2026-10-16'],
            ['reference_date' => $rules],
        )->fails());
    }
}