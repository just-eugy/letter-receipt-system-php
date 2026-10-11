<?php

namespace Tests\Feature\Api;

use App\Models\LetterDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $receptionist;
    protected User $admin;
    protected string $receptionistToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->receptionist = User::factory()->create([
            'username' => 'receptionist',
            'password' => bcrypt('password123'),
            'display_name' => 'Test Receptionist',
            'role' => 'RECEPTIONIST',
            'active' => true,
        ]);

        $this->admin = User::factory()->create([
            'username' => 'admin',
            'password' => bcrypt('password123'),
            'display_name' => 'Test Admin',
            'role' => 'ADMIN',
            'active' => true,
        ]);

        $this->receptionistToken = $this->receptionist->createToken('test')->plainTextToken;
    }

    // ===== Test Data Setup =====

    protected function createTestData(): void
    {
        LetterDelivery::factory()->create([
            'tracking_number' => 'REF-2026-000001',
            'delivery_person_name' => 'John Doe',
            'delivery_person_phone' => '0200000001',
            'delivery_person_email' => 'john@example.com',
            'recipient_title' => 'HR Officer',
            'status' => 'DELIVERED',
            'delivered_at' => now()->subDays(5),
        ]);

        LetterDelivery::factory()->create([
            'tracking_number' => 'REF-2026-000002',
            'delivery_person_name' => 'Jane Smith',
            'delivery_person_phone' => '0200000002',
            'delivery_person_email' => 'jane@example.com',
            'recipient_title' => 'IT Officer',
            'status' => 'RECEIVED',
            'delivered_at' => now()->subDays(3),
            'received_at' => now()->subDays(2),
            'received_by_user_id' => $this->receptionist->id,
        ]);

        LetterDelivery::factory()->create([
            'tracking_number' => 'REF-2026-000003',
            'delivery_person_name' => 'Bob Wilson',
            'delivery_person_phone' => '0200000003',
            'delivery_person_email' => 'bob@example.com',
            'recipient_title' => 'HR Officer',
            'status' => 'RECEIVED',
            'delivered_at' => now()->subDay(),
            'received_at' => now()->subHours(2),
            'received_by_user_id' => $this->receptionist->id,
        ]);
    }

    // ===== POST /reception/reports/generate =====

    public function test_generate_report_without_filters(): void
    {
        $this->createTestData();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->postJson('/api/reception/reports/generate', []);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'records',
                'totalRecords',
                'filters',
                'generatedAt',
            ])
            ->assertJsonPath('totalRecords', 3)
            ->assertJsonCount(3, 'records');
    }

    public function test_generate_report_with_status_filter(): void
    {
        $this->createTestData();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->postJson('/api/reception/reports/generate', [
                'status' => 'DELIVERED',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('totalRecords', 1)
            ->assertJsonPath('records.0.status', 'DELIVERED');
    }

    public function test_generate_report_with_recipient_position_filter(): void
    {
        $this->createTestData();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->postJson('/api/reception/reports/generate', [
                'recipientPosition' => 'HR Officer',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('totalRecords', 2)
            ->assertJsonPath('records.0.recipientTitle', 'HR Officer')
            ->assertJsonPath('records.1.recipientTitle', 'HR Officer');
    }

    public function test_generate_report_with_date_filters(): void
    {
        $this->createTestData();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->postJson('/api/reception/reports/generate', [
                'dateFrom' => now()->subDays(4)->format('Y-m-d'),
                'dateTo' => now()->subDays(2)->format('Y-m-d'),
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('totalRecords', 1) // Only the one from 3 days ago
            ->assertJsonPath('records.0.trackingNumber', 'REF-2026-000002');
    }

    public function test_generate_report_requires_authentication(): void
    {
        $response = $this->postJson('/api/reception/reports/generate', []);

        $response->assertStatus(401);
    }

    public function test_generate_report_validates_date_format(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->postJson('/api/reception/reports/generate', [
                'dateFrom' => 'invalid-date',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('errors.dateFrom.0', 'Date from must be a valid date.');
    }

    public function test_generate_report_validates_date_order(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->postJson('/api/reception/reports/generate', [
                'dateFrom' => now()->format('Y-m-d'),
                'dateTo' => now()->subDay()->format('Y-m-d'),
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('errors.dateTo.0', 'Date to must be after or equal to date from.');
    }

    public function test_generate_report_validates_status_enum(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->postJson('/api/reception/reports/generate', [
                'status' => 'INVALID',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('errors.status.0', 'Status must be either DELIVERED or RECEIVED.');
    }

    // ===== GET /reception/reports/export/csv =====

    public function test_export_csv_returns_file(): void
    {
        $this->createTestData();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->get('/api/reception/reports/export/csv');

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="incoming-letter-report-' . now()->format('Ymd-His') . '.csv"');

        $content = $response->getContent();
        // fputcsv adds quotes around fields with spaces
        $this->assertStringContainsString('ID,Reference,Status', $content);
        $this->assertStringContainsString('REF-2026-000001', $content);
        $this->assertStringContainsString('REF-2026-000002', $content);
        $this->assertStringContainsString('REF-2026-000003', $content);
    }

    public function test_export_csv_with_filters(): void
    {
        $this->createTestData();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->get('/api/reception/reports/export/csv?status=DELIVERED');

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('REF-2026-000001', $content);
        $this->assertStringNotContainsString('REF-2026-000002', $content);
    }

    // ===== GET /reception/reports/export/excel =====

    public function test_export_excel_returns_file(): void
    {
        $this->createTestData();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->receptionistToken])
            ->get('/api/reception/reports/export/excel');

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('Content-Disposition', 'attachment; filename="incoming-letter-report-' . now()->format('Ymd-His') . '.xlsx"');
    }
}