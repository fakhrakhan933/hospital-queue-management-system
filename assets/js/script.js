/**
 * Hospital Queue Management System - Client Script
 * Provides UI interactivity, live table filtering, confirmation modals,
 * auto-dismissing alerts, and dynamic emergency urgency form toggles.
 */

document.addEventListener('DOMContentLoaded', function () {
    // 1. Auto-dismiss alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(function (alert) {
        setTimeout(function () {
            try {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                if (bsAlert) {
                    bsAlert.close();
                }
            } catch (e) {
                // Ignore if already closed
            }
        }, 5000);
    });

    // 2. Dynamic Emergency Urgency Input Toggle on Patient Registration
    const queueTypeSelect = document.getElementById('queue_type');
    const urgencyContainer = document.getElementById('urgency_container');
    const urgencyInput = document.getElementById('urgency_level');

    if (queueTypeSelect && urgencyContainer) {
        function updateUrgencyVisibility() {
            if (queueTypeSelect.value === 'Emergency') {
                urgencyContainer.style.display = 'block';
                if (urgencyInput) {
                    urgencyInput.required = true;
                    if (!urgencyInput.value) {
                        urgencyInput.value = '5';
                    }
                }
            } else {
                urgencyContainer.style.display = 'none';
                if (urgencyInput) {
                    urgencyInput.required = false;
                    urgencyInput.value = '';
                }
            }
        }

        queueTypeSelect.addEventListener('change', updateUrgencyVisibility);
        updateUrgencyVisibility(); // Initialize on load
    }

    // 3. Confirmation Dialog Helper for state-changing or destructive actions
    const confirmActions = document.querySelectorAll('[data-confirm]');
    confirmActions.forEach(function (element) {
        element.addEventListener('click', function (e) {
            const message = this.getAttribute('data-confirm') || 'Are you sure you want to proceed with this action?';
            if (!window.confirm(message)) {
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }
        });
    });

    // 4. Live Table Search Filter
    const searchInputs = document.querySelectorAll('#tableSearchInput, .table-search-input');
    searchInputs.forEach(function (input) {
        input.addEventListener('keyup', function () {
            const filter = this.value.toLowerCase().trim();
            const targetSelector = this.getAttribute('data-target-table') || '.searchable-table';
            const targetTable = document.querySelector(targetSelector);
            if (targetTable) {
                const rows = targetTable.querySelectorAll('tbody tr');
                rows.forEach(function (row) {
                    // Do not hide empty table notice rows
                    if (row.querySelector('td[colspan]')) return;
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(filter) ? '' : 'none';
                });
            }
        });
    });

    // 5. Form Double-Submit Protection
    const standardForms = document.querySelectorAll('form[method="POST"]');
    standardForms.forEach(function (form) {
        form.addEventListener('submit', function () {
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn && !submitBtn.disabled) {
                // Brief delay so form submission payload is sent before disabling
                setTimeout(function () {
                    submitBtn.disabled = true;
                    submitBtn.classList.add('opacity-75');
                }, 50);
            }
        });
    });
});
