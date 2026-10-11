<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportRequest;
use App\Http\Resources\DeliveryResource;
use App\Services\DeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly DeliveryService $deliveryService
    ) {}

    /**
     * Generate filtered report (JSON).
     */
    public function generate(ReportRequest $request): JsonResponse
    {
        $report = $this->deliveryService->generateReport($request->validated());

        return response()->json([
            'records' => DeliveryResource::collection($report['records']),
            'totalRecords' => $report['totalRecords'],
            'filters' => $report['filters'],
            'generatedAt' => $report['generatedAt']->toISOString(),
        ]);
    }

    /**
     * Export report as CSV.
     */
    public function exportCsv(ReportRequest $request)
    {
        $records = $this->deliveryService->getExportRecords($request->validated());

        $filename = 'incoming-letter-report-' . now()->format('Ymd-His') . '.csv';

        $csv = $this->generateCsv($records);

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * Export report as Excel.
     */
    public function exportExcel(ReportRequest $request)
    {
        $records = $this->deliveryService->getExportRecords($request->validated());

        $filename = 'incoming-letter-report-' . now()->format('Ymd-His') . '.xlsx';

        $excel = $this->generateExcel($records);

        return response($excel)
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * Generate CSV content.
     */
    private function generateCsv($records): string
    {
        $handle = fopen('php://temp', 'w');

        // BOM for UTF-8
        fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

        // Headers
        fputcsv($handle, [
            'ID',
            'Reference',
            'Status',
            'Delivery Person',
            'Recipient',
            'Delivered At',
            'Received At',
            'Received By',
        ]);

        foreach ($records as $record) {
            fputcsv($handle, [
                $record->id,
                $record->tracking_number,
                $record->status,
                $record->delivery_person_name,
                $record->recipient_title,
                $record->delivered_at?->format('Y-m-d H:i:s'),
                $record->received_at?->format('Y-m-d H:i:s'),
                $record->receivedBy?->display_name ?? '',
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Generate Excel content (tab-separated for simplicity).
     */
    private function generateExcel($records): string
    {
        $handle = fopen('php://temp', 'w');

        // Headers
        fputcsv($handle, [
            'ID',
            'Reference',
            'Status',
            'Delivery Person',
            'Recipient',
            'Delivered At',
            'Received At',
            'Received By',
        ], "\t");

        foreach ($records as $record) {
            fputcsv($handle, [
                $record->id,
                $record->tracking_number,
                $record->status,
                $record->delivery_person_name,
                $record->recipient_title,
                $record->delivered_at?->format('Y-m-d H:i:s'),
                $record->received_at?->format('Y-m-d H:i:s'),
                $record->receivedBy?->display_name ?? '',
            ], "\t");
        }

        rewind($handle);
        $excel = stream_get_contents($handle);
        fclose($handle);

        return $excel;
    }
}