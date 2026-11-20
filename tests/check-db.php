<?php
declare(strict_types=1);

require dirname(__DIR__) . '/db.php';
$pdo = db();
$integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();
if ($integrity !== 'ok') {
    fwrite(STDERR, "فشل فحص سلامة قاعدة البيانات: $integrity\n");
    exit(1);
}

$required = [
    'users','students','staff','classes','subjects','attendance','grades',
    'fee_types','invoices','payments','expenses','parent_student_links',
    'notifications','curricula','learning_resources','assignments','lectures',
    'official_forms','correspondence','counseling_cases','behavior_notes',
    'improvement_plans','counseling_sessions','exam_calendars','grade_policies',
    'final_results','audit_logs','login_attempts'
];
foreach ($required as $table) {
    $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($table))->fetchColumn();
    if (!$exists) {
        fwrite(STDERR, "جدول مفقود: $table\n");
        exit(1);
    }
}

echo "قاعدة البيانات سليمة وجميع الجداول الأساسية موجودة\n";
