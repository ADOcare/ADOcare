<?php

return [
    // Keep OFF until privacy, accuracy and cost controls are tested.
    'auto_enhance' => (bool) env('AI_OCR_AUTO_ENHANCE', false),
    'pipeline_version' => 'ocr-v1-auto',
    'max_pages' => (int) env('AI_OCR_MAX_PAGES', 8),
    'max_chars_per_page' => (int) env('AI_OCR_MAX_CHARS_PER_PAGE', 8000),
    'max_candidates' => (int) env('AI_OCR_MAX_CANDIDATES', 20),
    // Best-effort guard, not an atomic billing cap under high concurrency.
    'daily_requests_per_company' => (int) env('AI_OCR_DAILY_REQUESTS_PER_COMPANY', 50),
];
