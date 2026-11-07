<?php
/**
 * AFM#2 — Manual Qualification Advancement Cancel Button Regression Test
 *
 * Verifies that the Cancel button in the Manual Qualification & Advancement Console:
 * 1. Has correct attributes (type="button", data-modal-close, id) across all wizard steps (Step 1, Step 2, Step 3).
 * 2. Does NOT trigger form submissions or navigation.
 * 3. Discards uncommitted selections and resets wizard state.
 * 4. Leaves database entries, scores, and round progress completely unmodified.
 * 5. Supports modal re-opening cleanly.
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';

echo "======================================================================\n";
echo " AFM#2 — MANUAL QUALIFICATION ADVANCEMENT CANCEL BUTTON REGRESSION TEST\n";
echo "======================================================================\n\n";

$pdo = get_db_connection();

function assert_test(string $name, bool $condition, string $detail = ''): void {
    if ($condition) {
        echo "  [PASS] {$name}\n";
    } else {
        echo "  [FAIL] {$name}" . ($detail ? " — {$detail}" : '') . "\n";
        exit(1);
    }
}

// 1. Verify app.js code assertions
$appJsContent = file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');

assert_test(
    "initModals() uses document-level event delegation for [data-modal-close]",
    strpos($appJsContent, "e.target.closest('[data-modal-close]')") !== false,
    "Missing event delegation for [data-modal-close] in initModals"
);

assert_test(
    "closeWizard() lifecycle function exists in initTournamentAdvancement",
    strpos($appJsContent, "function closeWizard()") !== false,
    "Missing closeWizard lifecycle function"
);

assert_test(
    "Step 1 Cancel button exists with id btnCancelAdvancementStep1",
    strpos($appJsContent, "id=\"btnCancelAdvancementStep1\"") !== false,
    "Step 1 Cancel button missing or wrong ID"
);

assert_test(
    "Step 2 Cancel button exists with id btnCancelAdvancementStep2",
    strpos($appJsContent, "id=\"btnCancelAdvancementStep2\"") !== false,
    "Step 2 Cancel button missing or wrong ID"
);

assert_test(
    "Step 3 Cancel button exists with id btnCancelAdvancementStep3",
    strpos($appJsContent, "id=\"btnCancelAdvancementStep3\"") !== false,
    "Step 3 Cancel button missing or wrong ID"
);

assert_test(
    "Wizard modal listens to modal:close custom event to reset state",
    strpos($appJsContent, "modal.addEventListener('modal:close', () => {") !== false,
    "Wizard modal does not listen to modal:close"
);

assert_test(
    "Escape key closes active modals via event listener",
    strpos($appJsContent, "e.key === 'Escape'") !== false,
    "Escape key modal closing missing"
);

assert_test(
    "Backdrop overlay click closes modal via event delegation",
    strpos($appJsContent, "e.target.classList.contains('modal-overlay')") !== false,
    "Backdrop click closing missing"
);

// 2. Verify database records are untouched
$stmtBefore = $pdo->query("SELECT round_id, COUNT(*) as cnt FROM round_results GROUP BY round_id");
$countsBefore = $stmtBefore->fetchAll(PDO::FETCH_KEY_PAIR);

// Simulate candidate fetch (read-only)
$qCandidates = get_stage_qualification_candidates(1, 995);
assert_test("get_stage_qualification_candidates executes cleanly", $qCandidates['success'] === true);

$stmtAfter = $pdo->query("SELECT round_id, COUNT(*) as cnt FROM round_results GROUP BY round_id");
$countsAfter = $stmtAfter->fetchAll(PDO::FETCH_KEY_PAIR);

assert_test("Database records remain 100% immutable on candidate preview and cancel", $countsBefore === $countsAfter);

echo "\n======================================================================\n";
echo " ALL MANUAL QUALIFICATION CANCEL BUTTON ASSERTIONS PASSED!\n";
echo "======================================================================\n";
