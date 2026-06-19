<?php
    // Reports - Inner content only for AJAX loading

    if (!isset($db)) {
        require_once __DIR__ . '/../config.php';
        $db = getDbConnection();
    }
?>

<div class="container-fluid mt-2">
    <div class="mb-3">
        <h2 class="mb-1">Reports</h2>
        <p class="text-muted mb-0 small">Select a report to view details or export</p>
    </div>

    <!-- Top search / filter bar -->
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <div class="input-group input-group-sm" style="max-width: 320px;">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" id="reportSearch" class="form-control" placeholder="Search reports..." oninput="filterReports()">
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearReportSearch()">Clear</button>
        <span class="small text-muted ms-auto" id="reportCount">4 reports available</span>
    </div>

    <!-- Report Cards Grid -->
    <div class="row g-3" id="reportsGrid">

        <!-- Fund Balances -->
        <div class="col-md-6 col-xl-3">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <i class="bi bi-wallet2 fs-3 text-primary me-3 mt-1"></i>
                        <div class="flex-grow-1">
                            <h6 class="card-title mb-1">Fund Balances Report</h6>
                            <p class="card-text small text-muted mb-0">Current balances for all funds with subtotals by restriction type (WODR / WDR).</p>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-0 pt-0 pb-3 px-3">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary btn-sm flex-grow-1" onclick="viewReport('fund-balances')">View</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exportReport('fund-balances')" title="Export">
                            <i class="bi bi-download"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Transaction Listing -->
        <div class="col-md-6 col-xl-3">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <i class="bi bi-journal-text fs-3 text-primary me-3 mt-1"></i>
                        <div class="flex-grow-1">
                            <h6 class="card-title mb-1">Transaction Listing Report</h6>
                            <p class="card-text small text-muted mb-0">Searchable list of transactions for a selected date range with full details.</p>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-0 pt-0 pb-3 px-3">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary btn-sm flex-grow-1" onclick="viewReport('transaction-listing')">View</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exportReport('transaction-listing')" title="Export">
                            <i class="bi bi-download"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Budget vs Actual -->
        <div class="col-md-6 col-xl-3">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <i class="bi bi-graph-up fs-3 text-primary me-3 mt-1"></i>
                        <div class="flex-grow-1">
                            <h6 class="card-title mb-1">Budget vs Actual Report</h6>
                            <p class="card-text small text-muted mb-0">Compare budgeted amounts to actual activity by category, account, or period.</p>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-0 pt-0 pb-3 px-3">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary btn-sm flex-grow-1" onclick="viewReport('budget-vs-actual')">View</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exportReport('budget-vs-actual')" title="Export">
                            <i class="bi bi-download"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Restricted Funds Status -->
        <div class="col-md-6 col-xl-3">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <i class="bi bi-lock fs-3 text-success me-3 mt-1"></i>
                        <div class="flex-grow-1">
                            <h6 class="card-title mb-1">Restricted Funds Status</h6>
                            <p class="card-text small text-muted mb-0">Activity, inflows, outflows, and remaining balances for donor-restricted funds.</p>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-0 pt-0 pb-3 px-3">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary btn-sm flex-grow-1" onclick="viewReport('restricted-funds')">View</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exportReport('restricted-funds')" title="Export">
                            <i class="bi bi-download"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Report Viewer -->
    <div id="reportViewer" class="mt-4 d-none">
        <div class="card border-primary shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center py-2 bg-light">
                <div id="viewerHeader" class="fw-semibold"></div>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="closeViewer()">Close</button>
            </div>
            <div class="card-body">
                <!-- Filters injected by JS -->
                <div id="viewerFilters" class="mb-3"></div>

                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="button" class="btn btn-primary btn-sm" onclick="runCurrentReport()">
                        <i class="bi bi-play-fill"></i> Run Report
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exportCurrentReport()">
                        <i class="bi bi-download"></i> Export
                    </button>
                </div>

                <!-- Results area -->
                <div id="viewerResults"></div>
            </div>
        </div>
    </div>

    <div class="mt-3 small text-muted">
        All reports currently display placeholder content. Full queries, filtering, and exports are planned for future updates.
    </div>
</div>

<script type="text/plain" id="init-reports-script">
(function() {
    let currentReportKey = null;

    const reportTitles = {
        'fund-balances': 'Fund Balances Report',
        'transaction-listing': 'Transaction Listing Report',
        'budget-vs-actual': 'Budget vs Actual Report',
        'restricted-funds': 'Restricted Funds Status Report'
    };

    const reportIcons = {
        'fund-balances': '<i class="bi bi-wallet2 text-primary me-2"></i>',
        'transaction-listing': '<i class="bi bi-journal-text text-primary me-2"></i>',
        'budget-vs-actual': '<i class="bi bi-graph-up text-primary me-2"></i>',
        'restricted-funds': '<i class="bi bi-lock text-success me-2"></i>'
    };

    function filterReports() {
        const term = (document.getElementById('reportSearch')?.value || '').toLowerCase().trim();
        const cards = document.querySelectorAll('#reportsGrid > div');
        let visible = 0;

        cards.forEach(cardCol => {
            const text = cardCol.textContent.toLowerCase();
            const show = !term || text.includes(term);
            cardCol.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        const countEl = document.getElementById('reportCount');
        if (countEl) {
            countEl.textContent = visible + ' report' + (visible === 1 ? '' : 's') + ' available';
        }
    }

    function clearReportSearch() {
        const input = document.getElementById('reportSearch');
        if (input) input.value = '';
        filterReports();
    }

    function getFiltersHTML(key) {
        if (key === 'fund-balances') {
            return `
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small mb-1">As of Date</label>
                        <input type="date" id="fb-date" class="form-control form-control-sm" value="2025-06-19">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Fund Type</label>
                        <select id="fb-type" class="form-select form-select-sm">
                            <option value="">All Types</option>
                            <option value="WODR">WODR - Without Donor Restrictions</option>
                            <option value="WDR">WDR - With Donor Restrictions</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check mt-3 mt-md-2">
                            <input class="form-check-input" type="checkbox" id="fb-include-archived">
                            <label class="form-check-label small" for="fb-include-archived">Include archived funds</label>
                        </div>
                    </div>
                </div>`;
        }
        if (key === 'transaction-listing') {
            return `
                <div class="row g-2">
                    <div class="col-auto">
                        <label class="form-label small mb-1">Date From</label>
                        <input type="date" id="tl-from" class="form-control form-control-sm" value="2025-01-01">
                    </div>
                    <div class="col-auto">
                        <label class="form-label small mb-1">Date To</label>
                        <input type="date" id="tl-to" class="form-control form-control-sm" value="2025-06-19">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">Status</label>
                        <select id="tl-status" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option>Pending</option>
                            <option>Cleared</option>
                            <option>Reconciled</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Search</label>
                        <input type="text" id="tl-search" class="form-control form-control-sm" placeholder="Pay to, ref, memo...">
                    </div>
                </div>`;
        }
        if (key === 'budget-vs-actual') {
            return `
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Fiscal Year</label>
                        <select id="ba-year" class="form-select form-select-sm">
                            <option>2025</option>
                            <option>2024</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Period</label>
                        <select id="ba-period" class="form-select form-select-sm">
                            <option value="ytd">Year to Date</option>
                            <option value="q2">Q2 2025</option>
                            <option value="may">May 2025</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Group By</label>
                        <select id="ba-group" class="form-select form-select-sm">
                            <option>Natural Category</option>
                            <option>Account</option>
                            <option>Functional Class</option>
                        </select>
                    </div>
                </div>`;
        }
        if (key === 'restricted-funds') {
            return `
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small mb-1">As of Date</label>
                        <input type="date" id="rf-date" class="form-control form-control-sm" value="2025-06-19">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Fund</label>
                        <select id="rf-fund" class="form-select form-select-sm">
                            <option value="">All Restricted Funds</option>
                            <option>Missions Fund</option>
                            <option>Benevolence Fund</option>
                            <option>Building Fund</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check mt-3 mt-md-2">
                            <input class="form-check-input" type="checkbox" id="rf-active-only" checked>
                            <label class="form-check-label small" for="rf-active-only">Active restrictions only</label>
                        </div>
                    </div>
                </div>`;
        }
        return '<p class="text-muted small">No filters defined.</p>';
    }

    function getPlaceholderResultsHTML(key) {
        const now = new Date().toLocaleString();

        if (key === 'fund-balances') {
            return `
                <div class="small text-muted mb-2">Generated ${now}</div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Fund</th>
                                <th>Code</th>
                                <th>Type</th>
                                <th class="text-end">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>General Fund</td><td>GF</td><td>WODR</td><td class="text-end">$42,850.00</td></tr>
                            <tr><td>Operating Reserve</td><td>OR</td><td>WODR</td><td class="text-end">$18,200.00</td></tr>
                            <tr><td>Missions Fund</td><td>MF</td><td>WDR</td><td class="text-end">$7,450.00</td></tr>
                            <tr><td>Benevolence Fund</td><td>BF</td><td>WDR</td><td class="text-end">$3,125.00</td></tr>
                            <tr><td>Building Fund</td><td>BLD</td><td>WDR</td><td class="text-end">$29,800.00</td></tr>
                        </tbody>
                        <tfoot class="table-light">
                            <tr><th colspan="3">Total WODR</th><th class="text-end text-primary">$61,050.00</th></tr>
                            <tr><th colspan="3">Total WDR</th><th class="text-end text-success">$40,375.00</th></tr>
                            <tr class="fw-bold"><th colspan="3">Grand Total</th><th class="text-end">$101,425.00</th></tr>
                        </tfoot>
                    </table>
                </div>`;
        }

        if (key === 'transaction-listing') {
            return `
                <div class="small text-muted mb-2">Generated ${now} • 4 transactions shown (sample)</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Date</th>
                                <th>Ref #</th>
                                <th>Pay To</th>
                                <th>Fund</th>
                                <th class="text-end">Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>2025-06-15</td><td>INV-8841</td><td>City Utilities</td><td>General Fund</td><td class="text-end">$245.00</td><td><span class="badge bg-success">Cleared</span></td></tr>
                            <tr><td>2025-06-12</td><td>CHK-1203</td><td>Office Supplies Co.</td><td>General Fund</td><td class="text-end">$87.45</td><td><span class="badge bg-success">Cleared</span></td></tr>
                            <tr><td>2025-06-10</td><td>—</td><td>Hope Baptist Missions</td><td>Missions Fund</td><td class="text-end">$1,500.00</td><td><span class="badge bg-info">Reconciled</span></td></tr>
                            <tr><td>2025-06-08</td><td>DON-551</td><td>Member Donation</td><td>General Fund</td><td class="text-end text-success">+$3,200.00</td><td><span class="badge bg-secondary">Pending</span></td></tr>
                        </tbody>
                    </table>
                </div>`;
        }

        if (key === 'budget-vs-actual') {
            return `
                <div class="small text-muted mb-2">Generated ${now} • YTD comparison (sample)</div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Category</th>
                                <th class="text-end">Budget</th>
                                <th class="text-end">Actual</th>
                                <th class="text-end">Variance</th>
                                <th class="text-end">% Used</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>Personnel</td><td class="text-end">$65,000</td><td class="text-end">$61,200</td><td class="text-end text-success">+$3,800</td><td class="text-end">94%</td></tr>
                            <tr><td>Facilities</td><td class="text-end">$18,000</td><td class="text-end">$19,450</td><td class="text-end text-danger">-$1,450</td><td class="text-end">108%</td></tr>
                            <tr><td>Programs</td><td class="text-end">$12,500</td><td class="text-end">$9,875</td><td class="text-end text-success">+$2,625</td><td class="text-end">79%</td></tr>
                            <tr><td>Administration</td><td class="text-end">$4,800</td><td class="text-end">$4,120</td><td class="text-end text-success">+$680</td><td class="text-end">86%</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="small mt-2"><strong>Net variance:</strong> <span class="text-success">+$5,655</span></div>`;
        }

        if (key === 'restricted-funds') {
            return `
                <div class="small text-muted mb-2">Generated ${now}</div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Fund</th>
                                <th class="text-end">Beginning</th>
                                <th class="text-end">Inflows</th>
                                <th class="text-end">Outflows</th>
                                <th class="text-end">Ending Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>Missions Fund</td><td class="text-end">$5,200</td><td class="text-end text-success">+$4,100</td><td class="text-end text-danger">$1,850</td><td class="text-end fw-bold">$7,450</td></tr>
                            <tr><td>Benevolence Fund</td><td class="text-end">$2,875</td><td class="text-end text-success">+$1,600</td><td class="text-end text-danger">$1,350</td><td class="text-end fw-bold">$3,125</td></tr>
                            <tr><td>Building Fund</td><td class="text-end">$24,300</td><td class="text-end text-success">+$7,500</td><td class="text-end text-danger">$2,000</td><td class="text-end fw-bold">$29,800</td></tr>
                        </tbody>
                    </table>
                </div>`;
        }

        return `<div class="text-muted small">No sample data available for this report.</div>`;
    }

    function viewReport(key) {
        currentReportKey = key;

        const viewer = document.getElementById('reportViewer');
        const header = document.getElementById('viewerHeader');
        const filters = document.getElementById('viewerFilters');
        const results = document.getElementById('viewerResults');

        if (!viewer || !header || !filters || !results) return;

        // Set header
        header.innerHTML = (reportIcons[key] || '') + (reportTitles[key] || key);

        // Inject filters
        filters.innerHTML = getFiltersHTML(key);

        // Clear previous results
        results.innerHTML = '<div class="text-muted small fst-italic">Configure filters above and click "Run Report".</div>';

        // Show viewer
        viewer.classList.remove('d-none');

        // Scroll into view
        viewer.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function closeViewer() {
        const viewer = document.getElementById('reportViewer');
        if (viewer) {
            viewer.classList.add('d-none');
        }
        currentReportKey = null;
    }

    function runCurrentReport() {
        if (!currentReportKey) return;

        const results = document.getElementById('viewerResults');
        if (!results) return;

        // Optional: collect filter values for display
        let applied = '';
        const filterContainer = document.getElementById('viewerFilters');
        if (filterContainer) {
            const inputs = filterContainer.querySelectorAll('input, select');
            const parts = [];
            inputs.forEach(el => {
                if (!el.id) return;
                let val = '';
                if (el.type === 'checkbox') val = el.checked ? 'Yes' : 'No';
                else val = el.value || '';
                if (val) parts.push(el.id.replace(/^(fb|tl|ba|rf)-/, '') + ': ' + val);
            });
            if (parts.length) applied = '<div class="small text-muted mb-1">Filters: ' + parts.join(' • ') + '</div>';
        }

        results.innerHTML = applied + getPlaceholderResultsHTML(currentReportKey);
    }

    function exportCurrentReport() {
        const results = document.getElementById('viewerResults');
        if (!results || !currentReportKey) {
            alert('Please open a report first.');
            return;
        }

        const title = reportTitles[currentReportKey] || 'Report';
        results.innerHTML = `
            <div class="alert alert-info py-2 small mb-0">
                <i class="bi bi-info-circle me-1"></i>
                <strong>${title}</strong> export prepared (placeholder).<br>
                In a future version this will generate a downloadable PDF or Excel file.
            </div>`;

        // Also show the generated content underneath
        setTimeout(() => {
            if (results && currentReportKey) {
                results.innerHTML += getPlaceholderResultsHTML(currentReportKey);
            }
        }, 450);
    }

    function exportReport(key) {
        // Open the viewer for that report, then show export message
        viewReport(key);

        // Slight delay so viewer is rendered
        setTimeout(() => {
            const results = document.getElementById('viewerResults');
            if (results) {
                const title = reportTitles[key] || 'Report';
                results.innerHTML = `
                    <div class="alert alert-warning py-2 small mb-2">
                        <i class="bi bi-download me-1"></i>
                        Export requested for <strong>${title}</strong>.<br>
                        File download (PDF / Excel) will be available in a future update.
                    </div>`;
            }
        }, 380);
    }

    // Wire up live search (in case oninput didn't attach)
    const searchInput = document.getElementById('reportSearch');
    if (searchInput) {
        searchInput.addEventListener('input', filterReports);
    }

    // Make functions globally available (for inline onclick handlers)
    window.viewReport = viewReport;
    window.exportReport = exportReport;
    window.clearReportSearch = clearReportSearch;
    window.runCurrentReport = runCurrentReport;
    window.closeViewer = closeViewer;
    window.exportCurrentReport = exportCurrentReport;
    window.filterReports = filterReports;

    // Initial filter (in case search has value on reload)
    filterReports();
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-reports-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>