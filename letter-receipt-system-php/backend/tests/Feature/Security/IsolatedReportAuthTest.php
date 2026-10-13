<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IsolatedReportAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_generate_no_auth(): void
    {
        $response = $this->postJson('/api/reception/reports/generate', []);
        $response->assertStatus(401);
    }

    public function test_report_csv_no_auth(): void
    {
        $response = $this->getJson('/api/reception/reports/export/csv');
        $response->assertStatus(401);
    }

    public function test_report_excel_no_auth(): void
    {
        $response = $this->getJson('/api/reception/reports/export/excel');
        $response->assertStatus(401);
    }
}