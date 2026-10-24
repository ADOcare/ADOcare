<?php

namespace Tests\Feature;

use App\Enums\InvoiceBatchMode;
use App\Enums\InvoicePatientCategory;
use PHPUnit\Framework\TestCase;

class InvoicePatientCategoryTest extends TestCase
{
    public function test_invoice_categories_map_to_distinct_claim_batch_subtypes(): void
    {
        self::assertSame(['N', 'O', 'A'], InvoicePatientCategory::DOMESTIC->batchSubtypes());
        self::assertSame(['E', 'F', 'G'], InvoicePatientCategory::EU->batchSubtypes());
        self::assertSame(['I', 'J', 'K'], InvoicePatientCategory::SPECIAL->batchSubtypes());
    }

    public function test_invoice_modes_select_the_correct_category_specific_batch_subtype(): void
    {
        self::assertSame(['N'], InvoiceBatchMode::REGULAR->batchSubtypes(InvoicePatientCategory::DOMESTIC));
        self::assertSame(['O'], InvoiceBatchMode::CORRECTIVE->batchSubtypes(InvoicePatientCategory::DOMESTIC));
        self::assertSame(['A'], InvoiceBatchMode::ADDITIVE->batchSubtypes(InvoicePatientCategory::DOMESTIC));

        self::assertSame(['E'], InvoiceBatchMode::REGULAR->batchSubtypes(InvoicePatientCategory::EU));
        self::assertSame(['F'], InvoiceBatchMode::CORRECTIVE->batchSubtypes(InvoicePatientCategory::EU));
        self::assertSame(['G'], InvoiceBatchMode::ADDITIVE->batchSubtypes(InvoicePatientCategory::EU));

        self::assertSame(['I'], InvoiceBatchMode::REGULAR->batchSubtypes(InvoicePatientCategory::SPECIAL));
        self::assertSame(['J'], InvoiceBatchMode::CORRECTIVE->batchSubtypes(InvoicePatientCategory::SPECIAL));
        self::assertSame(['K'], InvoiceBatchMode::ADDITIVE->batchSubtypes(InvoicePatientCategory::SPECIAL));
    }

    public function test_invoice_purpose_identifies_service_and_patient_category(): void
    {
        self::assertSame(
            'Ošetrovateľská starostlivosť – Poistenci EÚ/EHP/Švajčiarska',
            InvoicePatientCategory::EU->purpose('procedures'),
        );
        self::assertSame(
            'Doprava – Ostatní poistenci',
            InvoicePatientCategory::SPECIAL->purpose('transport'),
        );
    }
}