<?php
    // Admin - Inner content only for AJAX loading
require_once __DIR__ . '/../includes/page_bootstrap.php';
    require_once __DIR__ . '/../includes/backup_utils.php';

$lookupLinks = [
        ['page' => 'setup_funds', 'title' => 'Funds', 'icon' => 'bi-wallet2'],
        ['page' => 'setup_accounts', 'title' => 'Accounts', 'icon' => 'bi-credit-card'],
        ['page' => 'setup_naturalclasses', 'title' => 'Natural Classes', 'icon' => 'bi-tag'],
        ['page' => 'setup_functionalclasses', 'title' => 'Functional Classes', 'icon' => 'bi-tags'],
    ];

    $recentBackups = listRecentBackupSummaries(getBackupDir(), 4);

    $activeCards = [
        [
            'title' => 'Database Maintenance',
            'description' => 'Clear test data, reset users, and run destructive database utilities.',
            'icon' => 'bi-database-gear',
            'page' => 'admin-database',
        ],
    ];

    $placeholderCards = [
        [
            'title' => 'Users & Roles',
            'description' => 'Manage user accounts, roles, and access permissions.',
            'icon' => 'bi-people',
        ],
        [
            'title' => 'System Settings',
            'description' => 'Configure application preferences and organization details.',
            'icon' => 'bi-sliders',
        ],
        [
            'title' => 'Audit Log',
            'description' => 'Review system activity and administrative changes.',
            'icon' => 'bi-journal-text',
        ],
    ];
?>

<style>
    .admin-card {
        border-width: 1px;
        border-style: solid;
    }
    .admin-card--primary {
        border-color: rgba(var(--bs-primary-rgb), 0.25);
        background-color: rgba(var(--bs-primary-rgb), 0.08);
    }
    .admin-card--success {
        border-color: rgba(var(--bs-success-rgb), 0.25);
        background-color: rgba(var(--bs-success-rgb), 0.08);
    }
    .admin-card--secondary {
        border-color: rgba(var(--bs-secondary-rgb), 0.25);
        background-color: rgba(var(--bs-secondary-rgb), 0.06);
    }
    .admin-card .card-header {
        background: transparent;
        padding: 0.5rem 0.75rem;
        border-bottom-color: rgba(0, 0, 0, 0.06);
    }
    .admin-card .card-body {
        padding: 0.65rem 0.75rem;
    }
    .admin-lookup-link,
    .admin-active-link {
        font-size: 0.875rem;
        padding: 0.35rem 0;
        color: inherit;
        text-decoration: none;
        display: flex;
        align-items: center;
        border-radius: 0.25rem;
    }
    .admin-lookup-link:hover,
    .admin-active-link:hover {
        background-color: rgba(255, 255, 255, 0.55);
        color: #0d6efd;
    }
    .admin-backup-dates {
        display: flex;
        flex-direction: column;
        gap: 0.3rem;
    }
    .admin-backup-date {
        font-weight: 600;
        font-size: 0.95rem;
        color: var(--bs-success);
        line-height: 1.2;
    }

</style>

<div class="row mb-3">
    <div class="col-12">
        <h2 class="h4 mb-0">Admin</h2>
        <p class="text-muted small mb-0">System configuration and administration</p>
    </div>
</div>

<div class="row g-2">
    <div class="col-sm-6 col-xl-4">
        <div class="card admin-card admin-card--primary h-100 shadow-sm">
            <div class="card-header border-bottom d-flex align-items-center gap-2">
                <i class="bi bi-list text-primary"></i>
                <span class="fw-semibold small">Lookups</span>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-2">Reference tables used across the system.</p>
                <div class="d-flex flex-column gap-1">
                    <?php foreach ($lookupLinks as $link): ?>
                        <a href="javascript:void(0)"
                           class="admin-lookup-link admin-page-link px-1"
                           data-page="<?= htmlspecialchars($link['page']) ?>">
                            <i class="bi <?= $link['icon'] ?> me-2 text-muted"></i>
                            <?= htmlspecialchars($link['title']) ?>
                            <i class="bi bi-chevron-right ms-auto small text-muted"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-4">
        <div class="card admin-card admin-card--success h-100 shadow-sm">
            <div class="card-body d-flex flex-column">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <i class="bi bi-cloud-arrow-up text-success"></i>
                    <h6 class="mb-0 small fw-semibold">Backup & Restore</h6>
                </div>
                <p class="text-muted small mb-2">Export a full database backup or restore from a .sql file.</p>

                <?php if (count($recentBackups) > 0): ?>
                    <div class="mb-2">
                        <div class="text-muted text-uppercase mb-1" style="font-size: 0.7rem; letter-spacing: 0.03em;">Recent Backups</div>
                        <div class="admin-backup-dates">
                            <?php foreach ($recentBackups as $backup): ?>
                                <div class="admin-backup-date">
                                    <i class="bi bi-calendar-check me-1"></i><?= htmlspecialchars((string)($backup['display_datetime'] ?? 'Unknown')) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="text-muted small mb-2">No saved backups yet.</p>
                <?php endif; ?>

                <a href="javascript:void(0)"
                   class="admin-active-link admin-page-link px-1 mt-auto"
                   data-page="admin-backup">
                    Open Backup &amp; Restore
                    <i class="bi bi-chevron-right ms-auto small text-muted"></i>
                </a>
            </div>
        </div>
    </div>

    <?php foreach ($activeCards as $card): ?>
        <div class="col-sm-6 col-xl-4">
            <div class="card admin-card admin-card--secondary h-100 shadow-sm">
                <div class="card-body d-flex flex-column">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <i class="bi <?= $card['icon'] ?> text-secondary"></i>
                        <h6 class="mb-0 small fw-semibold"><?= htmlspecialchars($card['title']) ?></h6>
                    </div>
                    <p class="text-muted small mb-2 flex-grow-1"><?= htmlspecialchars($card['description']) ?></p>
                    <a href="javascript:void(0)"
                       class="admin-active-link admin-page-link px-1 mt-auto"
                       data-page="<?= htmlspecialchars($card['page']) ?>">
                        Open <?= htmlspecialchars($card['title']) ?>
                        <i class="bi bi-chevron-right ms-auto small text-muted"></i>
                    </a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php foreach ($placeholderCards as $card): ?>
        <div class="col-sm-6 col-xl-4">
            <div class="card admin-card admin-card--secondary h-100 shadow-sm">
                <div class="card-body d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi <?= $card['icon'] ?> text-secondary"></i>
                            <h6 class="mb-0 small fw-semibold"><?= htmlspecialchars($card['title']) ?></h6>
                        </div>
                        <span class="badge rounded-pill bg-light text-muted border">Soon</span>
                    </div>
                    <p class="text-muted small mb-0 flex-grow-1"><?= htmlspecialchars($card['description']) ?></p>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script type="text/plain" id="init-admin-script">
(function() {
    document.querySelectorAll('.admin-page-link').forEach(link => {
        link.addEventListener('click', () => loadPage(link.dataset.page));
    });
})();
</script>
<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" style="display:none" alt="" onload="var s=document.getElementById('init-admin-script');if(s){(new Function(s.textContent))();}this.remove();">

<?php $db->close(); ?>