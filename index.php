<?php
require_once 'includes/header.php';
require_once 'includes/nav.php';
?>

<div id="main-content" class="p-2">
    <!-- Content will be loaded here via AJAX -->
</div>

<!--
  Toast host: kept in markup for first paint; footer ensureAppToastContainer()
  reparents it to document.body so it stacks above open modals (not under
  #main-content-col z-index:1). CSS z-index is set in header.php.
-->
<div class="toast-container position-fixed top-0 start-50 translate-middle-x p-3" id="appToastContainer" aria-live="polite" aria-atomic="true"></div>

<!-- Idle login-timeout warning (shown 60s before session ends; always above other modals) -->
<div class="modal fade session-timeout-modal" id="sessionTimeoutModal" tabindex="-1"
     role="dialog" aria-modal="true" aria-labelledby="sessionTimeoutModalLabel"
     aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false"
     data-bs-focus="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-warning shadow-lg">
            <div class="modal-header py-2 border-warning-subtle">
                <h5 class="modal-title h6 mb-0" id="sessionTimeoutModalLabel">
                    <i class="bi bi-exclamation-triangle text-warning me-1"></i>
                    Session expiring soon
                </h5>
            </div>
            <div class="modal-body">
                <p class="mb-2">
                    You will be signed out due to inactivity in
                    <strong><span id="sessionTimeoutCountdown">60</span></strong> second<span id="sessionTimeoutCountdownPlural">s</span>.
                </p>
                <p class="small text-muted mb-0">
                    Click <strong>Stay logged in</strong> to continue your session. If you do nothing, you will be redirected to the login page.
                    Open forms are not closed by this warning.
                </p>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-primary" id="sessionTimeoutStayBtn">
                    Stay logged in
                </button>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
