<?php

require_once __DIR__ . '/../includes/page_bootstrap.php';

?>
<div class="row mb-3 mb-md-4 page-title-row">
    <div class="col-12">
        <h2 class="mb-0 h3 h-md-2">Financial Dashboard</h2>
        <p class="text-muted small mb-0 d-none d-sm-block">Stewardship &amp; Accountability Dashboard | Based on Treasurer’s Guide Rev 1.0</p>
        <p class="text-muted small mb-0 d-sm-none">Stewardship dashboard</p>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body py-4">
        <p class="mb-2">Dashboard cards have been removed. The previous totals and quick-link cards were out of date and are no longer shown.</p>
        <p class="text-muted small mb-0">Use Ledger, Budget, Reports, and Tasks from the navigation. A replacement dashboard will be added in a later release.</p>
    </div>
</div>

<?php $db->close(); ?>
