<?php

$temperExportSelf = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
if ($temperExportSelf !== false && $temperExportSelf === realpath(__FILE__)) {
    header('Location: ../login.php');
    exit;
}
unset($temperExportSelf);

require_once __DIR__ . '/budget_utils.php';
require_once __DIR__ . '/simple_pdf.php';
require_once __DIR__ . '/system_config.php';

/**
 * @return array{
 *   id:int,
 *   name:string,
 *   fiscal_year:int,
 *   start_date:string,
 *   end_date:string,
 *   status:string,
 *   description:string,
 *   reference_number:string,
 *   approved_date:string,
 *   church_name:string,
 *   period_label:string,
 *   total_budgeted:float,
 *   total_remaining:?float,
 *   lines:list<array{coa_number:string,account_name:string,budgeted_amount:float,remaining:?float}>
 * }|null
 */
function budgetExportBuildPayload(mysqli $db, int $budgetId): ?array
{
    $detail = budgetFetchDetailWithLines($db, $budgetId);
    if ($detail === null) {
        return null;
    }

    $lines = [];
    $sumBudgeted = 0.0;
    $sumRemaining = 0.0;
    $hasRemaining = false;
    foreach ($detail['lines'] ?? [] as $line) {
        $amt = round((float)($line['budgeted_amount'] ?? 0), 2);
        $sumBudgeted += $amt;
        $remaining = $line['remaining'] ?? null;
        $remainingVal = null;
        if ($remaining !== null && $remaining !== '') {
            $remainingVal = round((float)$remaining, 2);
            $sumRemaining += $remainingVal;
            $hasRemaining = true;
        }
        $lines[] = [
            'coa_number' => (string)($line['coa_number'] ?? ''),
            'account_name' => (string)($line['account_name'] ?? ''),
            'budgeted_amount' => $amt,
            'remaining' => $remainingVal,
        ];
    }

    $start = (string)($detail['start_date'] ?? '');
    $end = (string)($detail['end_date'] ?? '');

    return [
        'id' => (int)$detail['id'],
        'name' => (string)($detail['name'] ?? ''),
        'fiscal_year' => (int)($detail['fiscal_year'] ?? 0),
        'start_date' => $start,
        'end_date' => $end,
        'status' => (string)($detail['status'] ?? ''),
        'description' => (string)($detail['description'] ?? ''),
        'reference_number' => (string)($detail['reference_number'] ?? ''),
        'approved_date' => (string)($detail['approved_date'] ?? ''),
        'church_name' => function_exists('getChurchDisplayName') ? getChurchDisplayName() : '',
        'period_label' => budgetFormatPeriodLabel($start, $end),
        'total_budgeted' => round($sumBudgeted, 2),
        'total_remaining' => $hasRemaining ? round($sumRemaining, 2) : null,
        'lines' => $lines,
    ];
}

function budgetExportMoney(float $n): string
{
    $neg = $n < 0;
    return ($neg ? '-' : '') . '$' . number_format(abs($n), 2, '.', ',');
}

function budgetExportStatusLabel(string $status): string
{
    $status = trim($status);
    if ($status === '') {
        return '—';
    }
    return ucfirst($status);
}

function budgetExportFilename(array $payload, string $ext): string
{
    $name = (string)($payload['name'] ?? 'budget');
    $id = (int)($payload['id'] ?? 0);
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'budget';
    $base = trim($base, '._-');
    if ($base === '') {
        $base = 'budget';
    }
    if (strlen($base) > 60) {
        $base = substr($base, 0, 60);
    }
    $ext = strtolower($ext) === 'pdf' ? 'pdf' : 'csv';
    return $base . '_' . $id . '.' . $ext;
}

function budgetExportCsv(array $payload): string
{
    $fh = fopen('php://temp', 'r+');
    if ($fh === false) {
        return '';
    }
    $church = trim((string)($payload['church_name'] ?? ''));
    if ($church !== '') {
        fputcsv($fh, ['Church', $church], ',', '"', '\\');
    }
    fputcsv($fh, ['Budget', (string)($payload['name'] ?? '')], ',', '"', '\\');
    fputcsv($fh, ['Period', (string)($payload['period_label'] ?? '')], ',', '"', '\\');
    fputcsv($fh, ['Fiscal Year', (string)($payload['fiscal_year'] ?? '')], ',', '"', '\\');
    fputcsv($fh, ['Status', budgetExportStatusLabel((string)($payload['status'] ?? ''))], ',', '"', '\\');
    fputcsv($fh, ['Total', number_format((float)($payload['total_budgeted'] ?? 0), 2, '.', '')], ',', '"', '\\');
    fputcsv($fh, [], ',', '"', '\\');
    fputcsv($fh, ['CoA', 'Account Name', 'Budgeted Amount', 'Remaining'], ',', '"', '\\');
    foreach ($payload['lines'] as $line) {
        $remaining = $line['remaining'];
        fputcsv($fh, [
            (string)$line['coa_number'],
            (string)$line['account_name'],
            number_format((float)$line['budgeted_amount'], 2, '.', ''),
            $remaining === null ? '' : number_format((float)$remaining, 2, '.', ''),
        ], ',', '"', '\\');
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    if (!is_string($csv)) {
        $csv = '';
    }
    return "\xEF\xBB\xBF" . $csv;
}

function budgetExportPdf(array $payload): string
{
    $pdf = new TemperSimplePdf();
    $name = (string)($payload['name'] ?? 'Budget');
    $pdf->setTitle($name);
    $pdf->setRunningHeader($name);

    $church = trim((string)($payload['church_name'] ?? ''));
    if ($church !== '') {
        $pdf->text($church, 16, true);
        $pdf->space(4);
    }
    $pdf->text($name, 14, true);
    $pdf->space(8);
    $pdf->kv('Fiscal period', (string)($payload['period_label'] ?? ''));
    $fy = (int)($payload['fiscal_year'] ?? 0);
    if ($fy > 0) {
        $pdf->kv('Fiscal year', (string)$fy);
    }
    $pdf->kv('Status', budgetExportStatusLabel((string)($payload['status'] ?? '')));
    $pdf->kv('Total', budgetExportMoney((float)($payload['total_budgeted'] ?? 0)));
    $pdf->space(6);
    $pdf->rule();

    $widths = [70.0, 246.0, 104.0, 92.0];
    $align = ['left', 'left', 'right', 'right'];
    $headers = ['CoA', 'Account Name', 'Budgeted', 'Remaining'];
    $rows = [];
    foreach ($payload['lines'] as $line) {
        $remaining = $line['remaining'];
        $rows[] = [
            (string)$line['coa_number'],
            (string)$line['account_name'] !== '' ? (string)$line['account_name'] : '—',
            budgetExportMoney((float)$line['budgeted_amount']),
            $remaining === null ? '—' : budgetExportMoney((float)$remaining),
        ];
    }
    if ($rows === []) {
        $rows[] = ['', 'No budget lines.', '', ''];
    }
    $pdf->table($headers, $rows, $widths, $align, 9.0);
    $pdf->tableFooterRow([
        '',
        'Total',
        budgetExportMoney((float)($payload['total_budgeted'] ?? 0)),
        $payload['total_remaining'] === null ? '—' : budgetExportMoney((float)$payload['total_remaining']),
    ], $widths, $align, 9.0);

    return $pdf->output();
}

function budgetExportSendError(string $message, int $code = 400): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function budgetExportSendDownload(array $payload, string $format): void
{
    $format = strtolower(trim($format));
    if ($format !== 'csv' && $format !== 'pdf') {
        budgetExportSendError('Format must be CSV or PDF.');
    }

    if ($format === 'pdf') {
        $body = budgetExportPdf($payload);
        $filename = budgetExportFilename($payload, 'pdf');
        $mime = 'application/pdf';
    } else {
        $body = budgetExportCsv($payload);
        $filename = budgetExportFilename($payload, 'csv');
        $mime = 'text/csv; charset=utf-8';
    }

    $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?? $filename;
    if ($ascii === '') {
        $ascii = $filename;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Content-Length: ' . (string)strlen($body));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    echo $body;
    exit;
}
