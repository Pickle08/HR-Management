<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    private const DEFAULT_PER_PAGE = 50;

    public function __construct(private ReportService $reports)
    {
    }

    public function show(Request $request, string $report): JsonResponse|StreamedResponse
    {
        if (! in_array($report, ReportService::REPORTS, true)) {
            abort(404, 'Laporan tidak ditemukan.');
        }

        $user = $request->user();

        if (! $this->reports->canView($user, $report)) {
            abort(403);
        }

        $filters = Validator::make($request->query(), $this->reports->rules($report))->validate();
        $this->reports->assertPeriodWithinLimit($filters);

        $definition = $this->reports->definition($report, $user, $filters);

        return ($filters['format'] ?? 'json') === 'csv'
            ? $this->csv($report, $definition, $filters)
            : $this->json($definition, $filters);
    }

    private function json(array $definition, array $filters): JsonResponse
    {
        $page = $definition['query']->paginate($filters['per_page'] ?? self::DEFAULT_PER_PAGE);
        $map = $definition['row'];

        return response()->json([
            'data' => $page->getCollection()->map(fn ($row) => $map($row))->values(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'columns' => $definition['columns'],
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    private function csv(string $report, array $definition, array $filters): StreamedResponse
    {
        $max = (int) config('hrm.report_max_export_rows', 5000);

        // Ambil satu baris lebih banyak dari batas: cukup untuk tahu apakah batas terlampaui.
        $rows = $definition['query']->limit($max + 1)->get();

        if ($rows->count() > $max) {
            throw ValidationException::withMessages([
                'format' => ["Ekspor melebihi batas {$max} baris. Persempit filter laporan."],
            ]);
        }

        $map = $definition['row'];
        $keys = array_keys($definition['columns']);
        $headers = array_values($definition['columns']);
        $lines = $rows->map(fn ($row) => $map($row))->all();

        // Dicatat setelah lolos batas dan sebelum data dikirim. Isi laporan tidak ikut dicatat.
        Audit::record('report.exported', newValues: [
            'report' => $report,
            'rows' => count($lines),
            'filters' => Arr::except($filters, ['format', 'per_page']),
        ]);

        $filename = 'laporan-'.$report.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($headers, $keys, $lines) {
            $out = fopen('php://output', 'w');

            // BOM supaya Excel membaca UTF-8 dengan benar.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ',', '"', '');

            foreach ($lines as $line) {
                fputcsv($out, array_map(fn ($key) => $this->cell($line[$key] ?? null), $keys), ',', '"', '');
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** Angka (termasuk negatif, juga yang datang sebagai string dari MySQL) dibiarkan; teks dinetralkan. */
    private function cell(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1) {
            return $value;
        }

        return ReportService::safeCell($value);
    }
}
