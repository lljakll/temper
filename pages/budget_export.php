<?php
/**
 * Download a budget as CSV or PDF.
 * Restricted to roles that can view budgets (page.budget).
 */
$temperPageKey = 'budget';
require_once __DIR__ . '/../includes/page_bootstrap.php';
require_once __DIR__ . '/../includes/budget_export.php';

budgetEnsureSimplifiedSchema($db);

$budgetId = (int)($_GET['budget_id'] ?? $_POST['budget_id'] ?? 0);
$format = strtolower(trim((string)($_GET['format'] ?? $_POST['format'] ?? 'csv')));

if ($budgetId <= 0) {
    budgetExportSendError('Select a budget to export.');
}

$payload = budgetExportBuildPayload($db, $budgetId);
if ($payload === null) {
    budgetExportSendError('Budget not found.', 404);
}

budgetExportSendDownload($payload, $format);
