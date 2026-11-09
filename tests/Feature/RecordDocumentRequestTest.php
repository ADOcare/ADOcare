<?php

namespace Tests\Feature;

use App\Http\Requests\PreviewRecordDocumentRequest;
use App\Http\Requests\StoreRecordDocumentRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

class RecordDocumentRequestTest extends TestCase
{
    public function test_issue_date_is_required_and_uses_api_date_format(): void
    {
        $rules = (new StoreRecordDocumentRequest)->rules()['date'];
        $validator = new Factory(new Translator(new ArrayLoader, 'en'));

        self::assertTrue($validator->make([], ['date' => $rules])->fails());
        self::assertTrue($validator->make(
            ['date' => '03.11.2026'],
            ['date' => $rules],
        )->fails());
        self::assertFalse($validator->make(
            ['date' => '2026-11-03'],
            ['date' => $rules],
        )->fails());
    }

    public function test_preview_allows_an_empty_date_but_validates_provided_dates(): void
    {
        $rules = (new PreviewRecordDocumentRequest)->rules()['date'];
        $validator = new Factory(new Translator(new ArrayLoader, 'en'));

        self::assertFalse($validator->make(
            ['date' => null],
            ['date' => $rules],
        )->fails());
        self::assertTrue($validator->make(
            ['date' => '03.11.2026'],
            ['date' => $rules],
        )->fails());
        self::assertFalse($validator->make(
            ['date' => '2026-11-03'],
            ['date' => $rules],
        )->fails());
    }
}
