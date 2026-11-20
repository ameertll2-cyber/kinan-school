<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/backup.php';
require __DIR__ . '/drive.php';

$pdo = db();
$page = (string)($_GET['page'] ?? 'dashboard');
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'login') {
        $stmt = $pdo->prepare('SELECT id,name,username,role,active FROM users WHERE username = ? AND active = 1');
        $stmt->execute([(string)$_POST['username']]);
        $user = $stmt->fetch();
        $hashStmt = $pdo->prepare('SELECT password_hash FROM users WHERE username = ?');
        $hashStmt->execute([(string)$_POST['username']]);
        $hash = $hashStmt->fetchColumn();
        if ($user && is_string($hash) && password_verify((string)$_POST['password'], $hash)) {
            session_regenerate_id(true);
            $_SESSION['user'] = $user;
            header('Location: ?page=dashboard');
            exit;
        }
        $error = 'اسم المستخدم أو كلمة المرور غير صحيحة';
        $page = 'login';
    } elseif ($action === 'logout') {
        session_destroy();
        header('Location: ?page=login');
        exit;
    } else {
        $user = requireLogin();

        $accountingActions = ['fee_type_save','invoice_save','payment_save','expense_save'];
        if (!canManage($user) && $action !== 'attendance_save' && $action !== 'grade_save'
            && $action !== 'notification_read'
            && !in_array($action, $accountingActions, true)) {
            $error = 'ليست لديك صلاحية تنفيذ هذا الإجراء';
        } elseif (in_array($action, $accountingActions, true) && !canAccounting($user)) {
            $error = 'ليست لديك صلاحية تنفيذ الإجراء المحاسبي';
        } elseif ($action === 'student_save') {
            $pdo->prepare('INSERT INTO students (full_name,national_id,class_id,guardian_name,guardian_phone,address) VALUES (?,?,?,?,?,?)')
                ->execute([trim((string)$_POST['full_name']), trim((string)$_POST['national_id']), (int)$_POST['class_id'], trim((string)$_POST['guardian_name']), trim((string)$_POST['guardian_phone']), trim((string)$_POST['address'])]);
            $message = 'تمت إضافة الطالب';
        } elseif ($action === 'staff_save') {
            $pdo->prepare('INSERT INTO staff (full_name,job_title,phone,email) VALUES (?,?,?,?)')
                ->execute([trim((string)$_POST['full_name']), trim((string)$_POST['job_title']), trim((string)$_POST['phone']), trim((string)$_POST['email'])]);
            $message = 'تمت إضافة عضو الكادر';
        } elseif ($action === 'class_save') {
            $pdo->prepare('INSERT INTO classes (name,stage,section,academic_year) VALUES (?,?,?,?)')
                ->execute([trim((string)$_POST['name']), trim((string)$_POST['stage']), trim((string)$_POST['section']), trim((string)$_POST['academic_year'])]);
            $message = 'تمت إضافة الصف';
        } elseif ($action === 'subject_save') {
            $pdo->prepare('INSERT INTO subjects (name,code,class_id) VALUES (?,?,?)')
                ->execute([trim((string)$_POST['name']), trim((string)$_POST['code']), (int)$_POST['class_id']]);
            $message = 'تمت إضافة المادة';
        } elseif ($action === 'fee_type_save') {
            $amount = (float)$_POST['amount'];
            if (trim((string)$_POST['name']) === '' || $amount < 0) {
                $error = 'أدخل اسم ورسماً صحيحين';
            } else {
                $pdo->prepare('INSERT INTO fee_types (name,amount,academic_year) VALUES (?,?,?)')
                    ->execute([trim((string)$_POST['name']), $amount, trim((string)$_POST['academic_year'])]);
                $message = 'تمت إضافة نوع الرسم';
            }
        } elseif ($action === 'invoice_save') {
            $studentId = (int)$_POST['student_id'];
            $feeIds = array_map('intval', (array)($_POST['fee_type_ids'] ?? []));
            $discount = max(0, (float)$_POST['discount']);
            if (!$studentId || empty($feeIds)) {
                $error = 'اختر الطالب ونوع رسم واحد على الأقل';
            } else {
                $pdo->beginTransaction();
                try {
                    $year = trim((string)$_POST['academic_year']);
                    $invoiceNumber = 'INV-' . date('Y') . '-' . str_pad((string)($pdo->query('SELECT COUNT(*)+1 FROM invoices')->fetchColumn()), 4, '0', STR_PAD_LEFT);
                    $pdo->prepare('INSERT INTO invoices (invoice_number,student_id,academic_year,due_date,discount,note,status,created_by) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([$invoiceNumber, $studentId, $year, trim((string)$_POST['due_date']), $discount, trim((string)$_POST['note']), 'غير مدفوع', (int)$user['id']]);
                    $invoiceId = (int)$pdo->lastInsertId();
                    $feeStmt = $pdo->prepare('SELECT name,amount FROM fee_types WHERE id=? AND active=1');
                    $itemStmt = $pdo->prepare('INSERT INTO invoice_items (invoice_id,fee_type_id,description,amount) VALUES (?,?,?,?)');
                    foreach ($feeIds as $feeId) {
                        $feeStmt->execute([$feeId]);
                        $fee = $feeStmt->fetch();
                        if ($fee) {
                            $itemStmt->execute([$invoiceId, $feeId, $fee['name'], (float)$fee['amount']]);
                        }
                    }
                    $pdo->commit();
                    $message = 'تم إنشاء الفاتورة ' . $invoiceNumber;
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $error = 'تعذر إنشاء الفاتورة';
                }
            }
        } elseif ($action === 'payment_save') {
            $invoiceId = (int)$_POST['invoice_id'];
            $amount = (float)$_POST['amount'];
            if (!$invoiceId || $amount <= 0) {
                $error = 'أدخل فاتورة ومبلغاً صحيحين';
            } else {
                $invoiceStmt = $pdo->prepare('SELECT student_id FROM invoices WHERE id=?');
                $invoiceStmt->execute([$invoiceId]);
                $studentId = $invoiceStmt->fetchColumn();
                if (!$studentId) {
                    $error = 'الفاتورة غير موجودة';
                } else {
                    $balance = max(0, invoiceTotal($pdo, $invoiceId) - invoicePaid($pdo, $invoiceId));
                    if ($amount > $balance) {
                        $error = 'المبلغ أكبر من المتبقي على الفاتورة: ' . formatIQD($balance);
                    } else {
                    $receiptNumber = 'REC-' . date('Y') . '-' . str_pad((string)($pdo->query('SELECT COUNT(*)+1 FROM payments')->fetchColumn()), 5, '0', STR_PAD_LEFT);
                    $pdo->prepare('INSERT INTO payments (receipt_number,invoice_id,student_id,amount,method,paid_at,note,created_by) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([$receiptNumber, $invoiceId, (int)$studentId, $amount, (string)$_POST['method'], trim((string)$_POST['paid_at']), trim((string)$_POST['note']), (int)$user['id']]);
                    $total = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoice_items WHERE invoice_id=$invoiceId")->fetchColumn();
                    $discount = (float)$pdo->query("SELECT discount FROM invoices WHERE id=$invoiceId")->fetchColumn();
                    $paid = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=$invoiceId")->fetchColumn();
                    $status = $paid >= max(0, $total - $discount) ? 'مدفوعة بالكامل' : 'مدفوعة جزئيًا';
                    $pdo->prepare('UPDATE invoices SET status=? WHERE id=?')->execute([$status, $invoiceId]);
                    $message = 'تم تسجيل الدفع وإصدار إيصال ' . $receiptNumber;
                    }
                }
            }
        } elseif ($action === 'expense_save') {
            $amount = (float)$_POST['amount'];
            if (trim((string)$_POST['description']) === '' || $amount <= 0) {
                $error = 'أدخل وصف المصروف ومبلغه';
            } else {
                $pdo->prepare('INSERT INTO expenses (category,description,amount,expense_date,receipt_ref,created_by) VALUES (?,?,?,?,?,?)')
                    ->execute([trim((string)$_POST['category']), trim((string)$_POST['description']), $amount, trim((string)$_POST['expense_date']), trim((string)$_POST['receipt_ref']), (int)$user['id']]);
                $message = 'تمت إضافة المصروف';
            }
        } elseif ($action === 'attendance_save') {
            $stmt = $pdo->prepare('INSERT INTO attendance (student_id,date,status,note) VALUES (?,?,?,?)
                ON CONFLICT(student_id,date) DO UPDATE SET status=excluded.status,note=excluded.note');
            foreach (($_POST['attendance'] ?? []) as $studentId => $status) {
                $stmt->execute([(int)$studentId, (string)$_POST['date'], $status, (string)($_POST['note'][$studentId] ?? '')]);
            }
            $message = 'تم حفظ الحضور';
        } elseif ($action === 'grade_save') {
            $pdo->prepare('INSERT INTO grades (student_id,subject_id,type,score,max_score,date,note) VALUES (?,?,?,?,?,?,?)')
                ->execute([(int)$_POST['student_id'], (int)$_POST['subject_id'], trim((string)$_POST['type']), (float)$_POST['score'], (float)$_POST['max_score'], (string)$_POST['date'], trim((string)$_POST['note'])]);
            $message = 'تمت إضافة الدرجة';
        } elseif ($action === 'parent_link_save') {
            $parentUserId = (int)$_POST['parent_user_id'];
            $studentId = (int)$_POST['student_id'];
            if (!$parentUserId || !$studentId) {
                $error = 'اختر ولي الأمر والطالب';
            } else {
                $pdo->prepare('INSERT OR IGNORE INTO parent_student_links (parent_user_id,student_id,relationship) VALUES (?,?,?)')
                    ->execute([$parentUserId, $studentId, trim((string)$_POST['relationship']) ?: 'ولي أمر']);
                $message = 'تم ربط ولي الأمر بالطالب';
            }
        } elseif ($action === 'notification_save') {
            $title = trim((string)$_POST['title']);
            $body = trim((string)$_POST['body']);
            $audience = (string)$_POST['audience'];
            $parentUserId = $audience === 'ولي أمر محدد' ? (int)$_POST['parent_user_id'] : null;
            $studentId = $audience === 'طالب محدد' ? (int)$_POST['student_id'] : null;
            if ($title === '' || $body === '' || ($audience === 'ولي أمر محدد' && !$parentUserId) || ($audience === 'طالب محدد' && !$studentId)) {
                $error = 'أدخل عنوان ومحتوى والإستهداف المطلوب';
            } else {
                $pdo->prepare('INSERT INTO notifications (title,body,audience,parent_user_id,student_id,created_by) VALUES (?,?,?,?,?,?)')
                    ->execute([$title, $body, $audience, $parentUserId, $studentId, (int)$user['id']]);
                $message = 'تم إرسال الإشعار';
            }
        } elseif ($action === 'notification_read') {
            $notificationId = (int)$_POST['notification_id'];
            $pdo->prepare('INSERT OR IGNORE INTO notification_reads (notification_id,user_id) VALUES (?,?)')
                ->execute([$notificationId, (int)$user['id']]);
        } elseif ($action === 'backup_export') {
            $password = (string)$_POST['backup_password'];
            if (strlen($password) < 8) {
                $error = 'كلمة مرور النسخة يجب ألا تقل عن 8 أحرف';
            } else {
                $json = createEncryptedBackup($pdo, $password);
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="kinan-backup-' . date('Y-m-d-His') . '.kinan"');
                echo $json;
                exit;
            }
        } elseif ($action === 'backup_import') {
            if (empty($_FILES['backup_file']['tmp_name'])) {
                $error = 'اختر ملف النسخة الاحتياطية';
            } else {
                try {
                    restoreEncryptedBackup((string)file_get_contents($_FILES['backup_file']['tmp_name']), (string)$_POST['backup_password']);
                    header('Location: ?page=backup&restored=1');
                    exit;
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        } elseif ($action === 'drive_save') {
            saveDriveConfig($_POST);
            $message = 'تم حفظ إعدادات الربط. إضافة رمز التحديث وإجراء الاختبار مطلوبان قبل رفع النسخ.';
        } elseif ($action === 'drive_backup_now') {
            $password = (string)$_POST['backup_password'];
            if (strlen($password) < 8) {
                $error = 'كلمة مرور النسخة يجب ألا تقل عن 8 أحرف';
            } else {
                try {
                    $json = createEncryptedBackup($pdo, $password);
                    $uploaded = uploadBackupToDrive($json);
                    $message = 'تم رفع النسخة المشفرة إلى Google Drive: ' . ($uploaded['name'] ?? 'نسخة كِنان');
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }
    }
}

$user = currentUser();
if ($page === 'login' || (!$user && $page !== 'login')) {
    $page = 'login';
}

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function roleBadge(string $role): string {
    $classes = ['مدير'=>'danger','معاون'=>'warning','مدرس'=>'primary','محاسب'=>'success','ولي أمر'=>'info'];
    return '<span class="badge ' . ($classes[$role] ?? 'neutral') . '">' . e($role) . '</span>';
}

function invoiceTotal(PDO $pdo, int $invoiceId): float {
    $items = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoice_items WHERE invoice_id=$invoiceId")->fetchColumn();
    $discount = (float)$pdo->query("SELECT discount FROM invoices WHERE id=$invoiceId")->fetchColumn();
    return max(0, $items - $discount);
}

function invoicePaid(PDO $pdo, int $invoiceId): float {
    return (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=$invoiceId")->fetchColumn();
}

function formatIQD(float $amount): string {
    return number_format($amount, 0, '.', ',') . ' د.ع';
}

$title = 'كِنان';
if ($page === 'login' && $error === '') {
    $error = isset($_GET['required']) ? 'سجل الدخول للمتابعة' : '';
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($title) ?> - نظام إدارة المدرسة</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php if (!$user): ?>
    <main class="login-shell">
        <section class="login-card">
            <div class="brand-mark">كِ</div>
            <h1>نظام كِنان</h1>
            <p class="muted">إدارة المدرسة العربية المتكاملة</p>
            <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="login">
                <label>اسم المستخدم
                    <input name="username" autocomplete="username" required>
                </label>
                <label>كلمة المرور
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>
                <button class="btn primary" type="submit">تسجيل الدخول</button>
            </form>
            <div class="demo-hint">
                حساب تجريبي: <strong>admin</strong> / <strong>123456</strong>
            </div>
        </section>
    </main>
<?php else: ?>
    <aside class="sidebar">
        <div class="brand"><span class="brand-mark small">كِ</span><span>كِنان</span></div>
        <nav>
            <a class="<?= $page==='dashboard'?'active':'' ?>" href="?page=dashboard"><span>⌂</span> الرئيسية</a>
            <?php if (canManage($user)): ?>
                <a class="<?= $page==='students'?'active':'' ?>" href="?page=students"><span>♟</span> الطلبة</a>
                <a class="<?= $page==='staff'?'active':'' ?>" href="?page=staff"><span>◉</span> الكادر التدريسي</a>
                <a class="<?= $page==='classes'?'active':'' ?>" href="?page=classes"><span>▦</span> الصفوف والشعب</a>
                <a class="<?= $page==='subjects'?'active':'' ?>" href="?page=subjects"><span>▤</span> المواد الدراسية</a>
            <?php endif ?>
            <a class="<?= $page==='attendance'?'active':'' ?>" href="?page=attendance"><span>✓</span> الحضور والغياب</a>
            <a class="<?= $page==='grades'?'active':'' ?>" href="?page=grades"><span>★</span> الدرجات</a>
            <?php if (canManage($user)): ?>
                <a class="<?= $page==='backup'?'active':'' ?>" href="?page=backup"><span>↧</span> النسخ الاحتياطية</a>
            <?php endif ?>
            <?php if (canAccounting($user)): ?>
                <a class="<?= $page==='accounting'?'active':'' ?>" href="?page=accounting"><span>د.ع</span> المحاسبة والرسوم</a>
            <?php endif ?>
            <?php if (canManage($user)): ?>
                <a class="<?= $page==='parents'?'active':'' ?>" href="?page=parents"><span>♙</span> أولياء الأمور والإشعارات</a>
            <?php endif ?>
            <?php if ($user['role'] === 'ولي أمر'): ?>
                <a class="<?= $page==='parent_portal'?'active':'' ?>" href="?page=parent_portal"><span>⌂</span> بوابة ولي الأمر</a>
                <a class="<?= $page==='notifications'?'active':'' ?>" href="?page=notifications"><span>!</span> الإشعارات</a>
            <?php endif ?>
        </nav>
        <div class="sidebar-user">
            <strong><?= e($user['name']) ?></strong>
            <?= roleBadge((string)$user['role']) ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="logout">
                <button class="link-button" type="submit">تسجيل الخروج</button>
            </form>
        </div>
    </aside>
    <main class="app-main">
        <header class="topbar">
            <button class="menu-toggle" id="menuToggle" aria-label="القائمة">☰</button>
            <div>
                <h1><?= e([
                    'dashboard'=>'لوحة التحكم','students'=>'إدارة الطلبة','staff'=>'الكادر التدريسي',
                    'classes'=>'الصفوف والشعب','subjects'=>'المواد الدراسية','attendance'=>'الحضور والغياب',
                    'grades'=>'سجل الدرجات','backup'=>'النسخ الاحتياطية','accounting'=>'المحاسبة والرسوم',
                    'parents'=>'أولياء الأمور والإشعارات','parent_portal'=>'بوابة ولي الأمر','notifications'=>'الإشعارات'
               ][$page] ?? 'كِنان') ?></h1>
            </div>
        </header>
        <?php if ($message): ?><div class="alert success"><?= e($message) ?></div><?php endif ?>
        <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif ?>
        <section class="content">
        <?php if ($page === 'dashboard'): ?>
            <div class="stat-grid">
                <article class="stat-card"><span>عدد الطلبة</span><strong><?= (int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn() ?></strong></article>
                <article class="stat-card"><span>الكادر التدريسي</span><strong><?= (int)$pdo->query('SELECT COUNT(*) FROM staff')->fetchColumn() ?></strong></article>
                <article class="stat-card"><span>الصفوف</span><strong><?= (int)$pdo->query('SELECT COUNT(*) FROM classes')->fetchColumn() ?></strong></article>
                <article class="stat-card"><span>المواد</span><strong><?= (int)$pdo->query('SELECT COUNT(*) FROM subjects')->fetchColumn() ?></strong></article>
            </div>
            <?php if (canAccounting($user)): ?>
                <div class="stat-grid accounting-stats">
                    <article class="stat-card"><span>إجمالي المدفوعات</span><strong><?= formatIQD((float)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM payments')->fetchColumn()) ?></strong></article>
                    <article class="stat-card"><span>إجمالي المصروفات</span><strong><?= formatIQD((float)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM expenses')->fetchColumn()) ?></strong></article>
                    <article class="stat-card"><span>الفواتير غير المسددة</span><strong><?= (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE status!='مدفوعة بالكامل'")->fetchColumn() ?></strong></article>
                </div>
            <?php endif ?>
            <div class="panel">
                <h2>مرحبًا <?= e($user['name']) ?></h2>
                <p>هذه المرحلة الأولى جاهزة لإدارة بيانات المدرسة الأساسية والحضور والدرجات.</p>
            </div>
        <?php elseif ($page === 'students' && canManage($user)): ?>
            <div class="panel">
                <details>
                    <summary>إضافة طالب جديد</summary>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="student_save">
                        <label>اسم الطالب الكامل<input name="full_name" required></label>
                        <label>رقم الهوية<input name="national_id"></label>
                        <label>الصف<select name="class_id"><?php foreach($pdo->query('SELECT * FROM classes') as $row): ?><option value="<?= $row['id'] ?>"><?= e($row['name'].' - شعب '.$row['section']) ?></option><?php endforeach ?></select></label>
                        <label>اسم ولي الأمر<input name="guardian_name"></label>
                        <label>هاتف ولي الأمر<input name="guardian_phone"></label>
                        <label>العنوان<input name="address"></label>
                        <button class="btn primary" type="submit">حفظ الطالب</button>
                    </form>
                </details>
                <div class="table-wrap"><table><thead><tr><th>#</th><th>الاسم</th><th>الصف</th><th>ولي الأمر</th><th>الهاتف</th></tr></thead><tbody>
                <?php foreach($pdo->query('SELECT s.*,c.name class_name,c.section FROM students s LEFT JOIN classes c ON c.id=s.class_id WHERE s.active=1 ORDER BY s.id DESC') as $row): ?>
                    <tr><td><?= $row['id'] ?></td><td><?= e($row['full_name']) ?></td><td><?= e($row['class_name'].' - '.$row['section']) ?></td><td><?= e($row['guardian_name']) ?></td><td><?= e($row['guardian_phone']) ?></td></tr>
                <?php endforeach ?>
                </tbody></table></div>
            </div>
        <?php elseif ($page === 'staff' && canManage($user)): ?>
            <div class="panel">
                <details><summary>إضافة عضو كادر</summary><form method="post" class="form-grid">
                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="staff_save">
                    <label>الاسم الكامل<input name="full_name" required></label><label>الوظيفة<input name="job_title" required></label>
                    <label>الهاتف<input name="phone"></label><label>البريد<input name="email" type="email"></label>
                    <button class="btn primary">حفظ العضو</button>
                </form></details>
                <div class="table-wrap"><table><thead><tr><th>#</th><th>الاسم</th><th>الوظيفة</th><th>الهاتف</th><th>البريد</th></tr></thead><tbody>
                <?php foreach($pdo->query('SELECT * FROM staff WHERE active=1 ORDER BY id DESC') as $row): ?><tr><td><?= $row['id'] ?></td><td><?= e($row['full_name']) ?></td><td><?= e($row['job_title']) ?></td><td><?= e($row['phone']) ?></td><td><?= e($row['email']) ?></td></tr><?php endforeach ?>
                </tbody></table></div>
            </div>
        <?php elseif ($page === 'classes' && canManage($user)): ?>
            <div class="panel">
                <details><summary>إضافة صف وشعبة</summary><form method="post" class="form-grid">
                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="class_save">
                    <label>اسم الصف<input name="name" required></label><label>المرحلة<select name="stage"><option>ابتدائي</option><option>متوسط</option><option>إعدادي</option></select></label>
                    <label>الشعبة<input name="section" value="أ" required></label><label>السنة الدراسية<input name="academic_year" value="2026-2027" required></label>
                    <button class="btn primary">حفظ الصف</button>
                </form></details>
                <div class="table-wrap"><table><thead><tr><th>الصف</th><th>المرحلة</th><th>الشعبة</th><th>السنة</th></tr></thead><tbody>
                <?php foreach($pdo->query('SELECT * FROM classes ORDER BY id') as $row): ?><tr><td><?= e($row['name']) ?></td><td><?= e($row['stage']) ?></td><td><?= e($row['section']) ?></td><td><?= e($row['academic_year']) ?></td></tr><?php endforeach ?>
                </tbody></table></div>
            </div>
        <?php elseif ($page === 'subjects' && canManage($user)): ?>
            <div class="panel">
                <details><summary>إضافة مادة دراسية</summary><form method="post" class="form-grid">
                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="subject_save">
                    <label>اسم المادة<input name="name" required></label><label>رمز المادة<input name="code"></label>
                    <label>الصف<select name="class_id"><?php foreach($pdo->query('SELECT * FROM classes') as $row): ?><option value="<?= $row['id'] ?>"><?= e($row['name'].' - '.$row['section']) ?></option><?php endforeach ?></select></label>
                    <button class="btn primary">حفظ المادة</button>
                </form></details>
                <div class="table-wrap"><table><thead><tr><th>المادة</th><th>الرمز</th><th>الصف</th></tr></thead><tbody>
                <?php foreach($pdo->query('SELECT s.*,c.name class_name,c.section FROM subjects s LEFT JOIN classes c ON c.id=s.class_id') as $row): ?><tr><td><?= e($row['name']) ?></td><td><?= e($row['code']) ?></td><td><?= e($row['class_name'].' - '.$row['section']) ?></td></tr><?php endforeach ?>
                </tbody></table></div>
            </div>
        <?php elseif ($page === 'attendance'): ?>
            <div class="panel">
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="attendance_save">
                    <div class="toolbar"><label>التاريخ<input type="date" name="date" value="<?= e($_GET['date'] ?? date('Y-m-d')) ?>"></label></div>
                    <div class="table-wrap"><table><thead><tr><th>الطالب</th><th>الحالة</th><th>ملاحظة</th></tr></thead><tbody>
                    <?php $date=(string)($_GET['date'] ?? date('Y-m-d')); foreach($pdo->query('SELECT s.*,a.status,a.note FROM students s LEFT JOIN attendance a ON a.student_id=s.id AND a.date='.$pdo->quote($date).' WHERE s.active=1') as $row): ?>
                        <tr><td><?= e($row['full_name']) ?></td><td><select name="attendance[<?= $row['id'] ?>]"><?php foreach(['حاضر','غائب','متأخر','غياب بعذر'] as $status): ?><option <?= $row['status']===$status?'selected':'' ?>><?= e($status) ?></option><?php endforeach ?></select></td><td><input name="note[<?= $row['id'] ?>]" value="<?= e((string)$row['note']) ?>"></td></tr>
                    <?php endforeach ?>
                    </tbody></table></div>
                    <button class="btn primary">حفظ الحضور</button>
                </form>
            </div>
        <?php elseif ($page === 'grades'): ?>
            <div class="panel">
                <details><summary>إضافة درجة</summary><form method="post" class="form-grid">
                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="grade_save">
                    <label>الطالب<select name="student_id"><?php foreach($pdo->query('SELECT * FROM students WHERE active=1') as $row): ?><option value="<?= $row['id'] ?>"><?= e($row['full_name']) ?></option><?php endforeach ?></select></label>
                    <label>المادة<select name="subject_id"><?php foreach($pdo->query('SELECT * FROM subjects') as $row): ?><option value="<?= $row['id'] ?>"><?= e($row['name']) ?></option><?php endforeach ?></select></label>
                    <label>نوع التقييم<input name="type" value="شهري" required></label><label>الدرجة<input name="score" type="number" step="0.01" required></label>
                    <label>الدرجة العظمى<input name="max_score" type="number" step="0.01" value="100"></label><label>التاريخ<input name="date" type="date" value="<?= date('Y-m-d') ?>"></label>
                    <label>ملاحظة<input name="note"></label><button class="btn primary">حفظ الدرجة</button>
                </form></details>
                <div class="table-wrap"><table><thead><tr><th>الطالب</th><th>المادة</th><th>التقييم</th><th>الدرجة</th><th>التاريخ</th></tr></thead><tbody>
                <?php foreach($pdo->query('SELECT g.*,s.full_name student_name,j.name subject_name FROM grades g JOIN students s ON s.id=g.student_id JOIN subjects j ON j.id=g.subject_id ORDER BY g.id DESC') as $row): ?><tr><td><?= e($row['student_name']) ?></td><td><?= e($row['subject_name']) ?></td><td><?= e($row['type']) ?></td><td><?= e((string)$row['score'].' / '.(string)$row['max_score']) ?></td><td><?= e($row['date']) ?></td></tr><?php endforeach ?>
                </tbody></table></div>
            </div>
        <?php elseif ($page === 'accounting' && canAccounting($user)): ?>
            <div class="stat-grid accounting-stats">
                <article class="stat-card"><span>إجمالي المقبوضات</span><strong><?= formatIQD((float)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM payments')->fetchColumn()) ?></strong></article>
                <article class="stat-card"><span>إجمالي المصروفات</span><strong><?= formatIQD((float)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM expenses')->fetchColumn()) ?></strong></article>
                <article class="stat-card"><span>الرصيد النقدي</span><strong><?= formatIQD((float)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM payments')->fetchColumn() - (float)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM expenses')->fetchColumn()) ?></strong></article>
            </div>
            <div class="accounting-tabs">
                <details open>
                    <summary>أنواع الرسوم</summary>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="fee_type_save">
                        <label>اسم الرسم<input name="name" required></label>
                        <label>المبلغ بالدينار العراقي<input name="amount" type="number" min="0" step="250" required></label>
                        <label>السنة الدراسية<input name="academic_year" value="2026-2027" required></label>
                        <button class="btn primary">حفظ نوع الرسم</button>
                    </form>
                    <div class="table-wrap"><table><thead><tr><th>الرسم</th><th>المبلغ</th><th>السنة</th></tr></thead><tbody>
                    <?php foreach($pdo->query('SELECT * FROM fee_types WHERE active=1 ORDER BY id') as $row): ?>
                        <tr><td><?= e($row['name']) ?></td><td><?= formatIQD((float)$row['amount']) ?></td><td><?= e($row['academic_year']) ?></td></tr>
                    <?php endforeach ?>
                    </tbody></table></div>
                </details>
                <details open>
                    <summary>إنشاء فاتورة طالب</summary>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="invoice_save">
                        <label>الطالب
                            <select name="student_id" required><option value="">اختر الطالب</option><?php foreach($pdo->query('SELECT * FROM students WHERE active=1 ORDER BY full_name') as $row): ?><option value="<?= $row['id'] ?>"><?= e($row['full_name']) ?></option><?php endforeach ?></select>
                        </label>
                        <label>السنة الدراسية<input name="academic_year" value="2026-2027" required></label>
                        <fieldset class="fee-picker"><legend>الرسوم</legend>
                            <?php foreach($pdo->query('SELECT * FROM fee_types WHERE active=1 ORDER BY id') as $row): ?>
                                <label class="check-line"><input type="checkbox" name="fee_type_ids[]" value="<?= $row['id'] ?>"> <span><?= e($row['name']) ?> — <?= formatIQD((float)$row['amount']) ?></span></label>
                            <?php endforeach ?>
                        </fieldset>
                        <label>الخصم بالدينار العراقي<input name="discount" type="number" min="0" step="250" value="0"></label>
                        <label>تاريخ الاستحقاق<input name="due_date" type="date" value="<?= date('Y-m-d') ?>"></label>
                        <label>ملاحظة<input name="note"></label>
                        <button class="btn primary">إنشاء الفاتورة</button>
                    </form>
                </details>
                <details open>
                    <summary>تسجيل دفعة وإيصال</summary>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="payment_save">
                        <label>الفاتورة
                            <select name="invoice_id" required><option value="">اختر الفاتورة</option><?php foreach($pdo->query('SELECT i.*,s.full_name FROM invoices i JOIN students s ON s.id=i.student_id ORDER BY i.id DESC') as $row): ?>
                                <option value="<?= $row['id'] ?>"><?= e($row['invoice_number'].' - '.$row['full_name'].' - '.formatIQD(max(0,invoiceTotal($pdo,(int)$row['id'])-invoicePaid($pdo,(int)$row['id'])))) ?></option>
                            <?php endforeach ?></select>
                        </label>
                        <label>المبلغ المدفوع<input name="amount" type="number" min="1" step="250" required></label>
                        <label>طريقة الدفع<select name="method"><option>نقدي</option><option>تحويل بنكي</option><option>بطاقة</option></select></label>
                        <label>تاريخ الدفع<input name="paid_at" type="date" value="<?= date('Y-m-d') ?>"></label>
                        <label>ملاحظة<input name="note"></label>
                        <button class="btn primary">حفظ الدفعة</button>
                    </form>
                </details>
                <details open>
                    <summary>تسجيل مصروف</summary>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="expense_save">
                        <label>الفئة<input name="category" value="مشتريات مدرسية" required></label>
                        <label>الوصف<input name="description" required></label>
                        <label>المبلغ بالدينار العراقي<input name="amount" type="number" min="1" step="250" required></label>
                        <label>التاريخ<input name="expense_date" type="date" value="<?= date('Y-m-d') ?>"></label>
                        <label>رقم الإشعار أو الفاتورة<input name="receipt_ref"></label>
                        <button class="btn primary">حفظ المصروف</button>
                    </form>
                </details>
            </div>
            <div class="panel">
                <h2>الفواتير والإيصالات</h2>
                <div class="table-wrap"><table><thead><tr><th>رقم الفاتورة</th><th>الطالب</th><th>الإجمالي</th><th>مدفوع</th><th>المتبقي</th><th>الحالة</th></tr></thead><tbody>
                <?php foreach($pdo->query('SELECT i.*,s.full_name FROM invoices i JOIN students s ON s.id=i.student_id ORDER BY i.id DESC') as $row):
                    $total=invoiceTotal($pdo,(int)$row['id']); $paid=invoicePaid($pdo,(int)$row['id']); $remaining=max(0,$total-$paid);
                ?>
                    <tr><td><?= e($row['invoice_number']) ?></td><td><?= e($row['full_name']) ?></td><td><?= formatIQD($total) ?></td><td><?= formatIQD($paid) ?></td><td><?= formatIQD($remaining) ?></td><td><span class="badge <?= $remaining<=0?'success':'warning' ?>"><?= e($row['status']) ?></span></td></tr>
                <?php endforeach ?>
                </tbody></table></div>
            </div>
            <div class="panel">
                <h2>آخر الدفعات والمصروفات</h2>
                <div class="table-wrap"><table><thead><tr><th>النوع</th><th>المرجع</th><th>الوصف</th><th>المبلغ</th><th>التاريخ</th></tr></thead><tbody>
                <?php
                foreach($pdo->query('SELECT p.*,s.full_name FROM payments p JOIN students s ON s.id=p.student_id ORDER BY p.id DESC LIMIT 20') as $row):
                    ?><tr><td>قبض</td><td><?= e($row['receipt_number']) ?></td><td><?= e($row['full_name'].' - '.$row['method']) ?></td><td><?= formatIQD((float)$row['amount']) ?></td><td><?= e($row['paid_at']) ?></td></tr><?php
                endforeach;
                foreach($pdo->query('SELECT * FROM expenses ORDER BY expense_date DESC,id DESC LIMIT 20') as $row):
                    ?><tr><td>مصروف</td><td><?= e((string)$row['receipt_ref']) ?></td><td><?= e($row['category'].' - '.$row['description']) ?></td><td><?= formatIQD((float)$row['amount']) ?></td><td><?= e($row['expense_date']) ?></td></tr><?php
                endforeach;
                ?>
                </tbody></table></div>
            </div>
        <?php elseif ($page === 'parents' && canManage($user)): ?>
            <div class="panel">
                <details open>
                    <summary>ربط ولي الأمر بالطالب</summary>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="action" value="parent_link_save">
                        <label>ولي الأمر
                            <select name="parent_user_id" required><option value="">اختر ولي الأمر</option>
                            <?php foreach($pdo->query("SELECT id,name,username FROM users WHERE role='ولي أمر' AND active=1 ORDER BY name") as $row): ?>
                                <option value="<?= $row['id'] ?>"><?= e($row['name'].' ('.$row['username'].')') ?></option>
                            <?php endforeach ?>
                            </select>
                        </label>
                        <label>الطالب
                            <select name="student_id" required><option value="">اختر الطالب</option>
                            <?php foreach($pdo->query("SELECT id,full_name FROM students WHERE active=1 ORDER BY full_name") as $row): ?>
                                <option value="<?= $row['id'] ?>"><?= e($row['full_name']) ?></option>
                            <?php endforeach ?>
                            </select>
                        </label>
                        <label>صلة القرابة<input name="relationship" value="ولي أمر"></label>
                        <button class="btn primary">ربط الحساب</button>
                    </form>
                </details>
                <details open>
                    <summary>إرسال إشعار للبوابة</summary>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="action" value="notification_save">
                        <label>عنوان الإشعار<input name="title" required></label>
                        <label>الاستهداف
                            <select name="audience" id="notificationAudience">
                                <option value="ولي أمر">جميع أولياء الأمور</option>
                                <option value="ولي أمر محدد">ولي أمر محدد</option>
                                <option value="طالب محدد">طالب محدد</option>
                            </select>
                        </label>
                        <label>ولي الأمر المحدد
                            <select name="parent_user_id"><option value="">لا ينطبق</option>
                            <?php foreach($pdo->query("SELECT id,name FROM users WHERE role='ولي أمر' AND active=1 ORDER BY name") as $row): ?>
                                <option value="<?= $row['id'] ?>"><?= e($row['name']) ?></option>
                            <?php endforeach ?>
                            </select>
                        </label>
                        <label>الطالب المحدد
                            <select name="student_id"><option value="">لا ينطبق</option>
                            <?php foreach($pdo->query("SELECT id,full_name FROM students WHERE active=1 ORDER BY full_name") as $row): ?>
                                <option value="<?= $row['id'] ?>"><?= e($row['full_name']) ?></option>
                            <?php endforeach ?>
                            </select>
                        </label>
                        <label>نص الإشعار<textarea name="body" rows="4" required></textarea></label>
                        <button class="btn primary">إرسال الإشعار</button>
                    </form>
                </details>
            </div>
            <div class="panel">
                <h2>الربطات الحالية والإشعارات</h2>
                <div class="table-wrap"><table><thead><tr><th>ولي الأمر</th><th>الطالب</th><th>صلة القرابة</th></tr></thead><tbody>
                <?php foreach($pdo->query("SELECT l.relationship,u.name parent_name,s.full_name student_name FROM parent_student_links l JOIN users u ON u.id=l.parent_user_id JOIN students s ON s.id=l.student_id ORDER BY l.id DESC") as $row): ?>
                    <tr><td><?= e($row['parent_name']) ?></td><td><?= e($row['student_name']) ?></td><td><?= e($row['relationship']) ?></td></tr>
                <?php endforeach ?>
                </tbody></table></div>
                <div class="table-wrap"><table><thead><tr><th>الإشعار</th><th>الاستهداف</th><th>التاريخ</th></tr></thead><tbody>
                <?php foreach($pdo->query("SELECT * FROM notifications ORDER BY id DESC LIMIT 20") as $row): ?>
                    <tr><td><strong><?= e($row['title']) ?></strong><br><?= e($row['body']) ?></td><td><?= e($row['audience']) ?></td><td><?= e(substr($row['created_at'],0,16)) ?></td></tr>
                <?php endforeach ?>
                </tbody></table></div>
            </div>
        <?php elseif ($page === 'parent_portal' && $user['role'] === 'ولي أمر'): ?>
            <?php
            $linkedIds = [];
            $linkedStudents = [];
            $linkStmt = $pdo->prepare("SELECT s.* FROM parent_student_links l JOIN students s ON s.id=l.student_id WHERE l.parent_user_id=? AND s.active=1");
            $linkStmt->execute([(int)$user['id']]);
            $linkedStudents = $linkStmt->fetchAll();
            foreach ($linkedStudents as $linked) { $linkedIds[]=(int)$linked['id']; }
            ?>
            <div class="stat-grid">
                <article class="stat-card"><span>الطلاب المرتبطون</span><strong><?= count($linkedStudents) ?></strong></article>
                <article class="stat-card"><span>أيام الحضور المسجلة</span><strong><?php
                    $attendanceCount=0;
                    foreach($linkedIds as $studentId){$attendanceCount += (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE student_id=$studentId")->fetchColumn();}
                    echo $attendanceCount;
                ?></strong></article>
                <article class="stat-card"><span>سجل الدرجات</span><strong><?php
                    $gradeCount=0;
                    foreach($linkedIds as $studentId){$gradeCount += (int)$pdo->query("SELECT COUNT(*) FROM grades WHERE student_id=$studentId")->fetchColumn();}
                    echo $gradeCount;
                ?></strong></article>
            </div>
            <?php foreach($linkedStudents as $student): ?>
                <div class="panel">
                    <h2><?= e($student['full_name']) ?></h2>
                    <div class="stat-grid compact">
                        <?php
                        $studentId=(int)$student['id'];
                        $present=(int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE student_id=$studentId AND status='حاضر'")->fetchColumn();
                        $absent=(int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE student_id=$studentId AND status LIKE 'غي%'")->fetchColumn();
                        $late=(int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE student_id=$studentId AND status='متأخر'")->fetchColumn();
                        ?>
                        <article class="stat-card"><span>حضور</span><strong><?= $present ?></strong></article>
                        <article class="stat-card"><span>غياب</span><strong><?= $absent ?></strong></article>
                        <article class="stat-card"><span>تأخر</span><strong><?= $late ?></strong></article>
                    </div>
                    <div class="table-wrap"><table><thead><tr><th>التاريخ</th><th>الحالة</th><th>ملاحظة</th></tr></thead><tbody>
                    <?php foreach($pdo->query("SELECT date,status,note FROM attendance WHERE student_id=$studentId ORDER BY date DESC LIMIT 15") as $row): ?>
                        <tr><td><?= e($row['date']) ?></td><td><?= e($row['status']) ?></td><td><?= e((string)$row['note']) ?></td></tr>
                    <?php endforeach ?>
                    </tbody></table></div>
                    <div class="table-wrap"><table><thead><tr><th>المادة</th><th>التقييم</th><th>الدرجة</th><th>التاريخ</th></tr></thead><tbody>
                    <?php foreach($pdo->query("SELECT g.*,j.name subject_name FROM grades g JOIN subjects j ON j.id=g.subject_id WHERE g.student_id=$studentId ORDER BY g.date DESC,g.id DESC LIMIT 15") as $row): ?>
                        <tr><td><?= e($row['subject_name']) ?></td><td><?= e($row['type']) ?></td><td><?= e((string)$row['score'].' / '.(string)$row['max_score']) ?></td><td><?= e($row['date']) ?></td></tr>
                    <?php endforeach ?>
                    </tbody></table></div>
                </div>
            <?php endforeach ?>
            <?php if (!$linkedStudents): ?><div class="panel"><h2>لا توجد روابط طلابية</h2><p>اطلب من إدارة المدرسة ربط حساب ولي الأمر بالطالب.</p></div><?php endif ?>
        <?php elseif ($page === 'notifications' && $user['role'] === 'ولي أمر'): ?>
            <?php
            $linkedIds=[];
            $linkStmt=$pdo->prepare("SELECT student_id FROM parent_student_links WHERE parent_user_id=?");
            $linkStmt->execute([(int)$user['id']]);
            foreach($linkStmt->fetchAll() as $link){$linkedIds[]=(int)$link['student_id'];}
            ?>
            <div class="panel">
                <h2>الإشعارات الواردة</h2>
                <?php $found=false; foreach($pdo->query("SELECT * FROM notifications ORDER BY id DESC") as $notification):
                    $visible = $notification['audience'] === 'ولي أمر'
                        || ($notification['audience'] === 'ولي أمر محدد' && (int)$notification['parent_user_id'] === (int)$user['id'])
                        || ($notification['audience'] === 'طالب محدد' && in_array((int)$notification['student_id'],$linkedIds,true));
                    if (!$visible) continue; $found=true;
                    $readStmt=$pdo->prepare("SELECT 1 FROM notification_reads WHERE notification_id=? AND user_id=?");
                    $readStmt->execute([(int)$notification['id'],(int)$user['id']]);
                    $read=(bool)$readStmt->fetchColumn();
                ?>
                    <article class="notification-card <?= $read?'read':'' ?>">
                        <div><strong><?= e($notification['title']) ?></strong><time><?= e(substr($notification['created_at'],0,16)) ?></time></div>
                        <p><?= e($notification['body']) ?></p>
                        <?php if (!$read): ?>
                            <form method="post"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="notification_read"><input type="hidden" name="notification_id" value="<?= $notification['id'] ?>"><button class="link-button">تعليم كمقروء</button></form>
                        <?php else:?><span class="badge success">تمت القراءة</span><?php endif ?>
                    </article>
                <?php endforeach ?>
                <?php if (!$found): ?><p class="muted">لا توجد إشعارات جديدة.</p><?php endif ?>
            </div>
        <?php elseif ($page === 'backup' && canManage($user)): ?>
            <div class="backup-grid">
                <div class="panel"><h2>تصدير نسخة مشفرة</h2><form method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="backup_export">
                    <label>كلمة مرور النسخة<input type="password" name="backup_password" minlength="8" required></label><button class="btn primary">تنزيل النسخة المشفرة</button>
                </form></div>
                <div class="panel"><h2>استيراد نسخة مشفرة</h2><form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="backup_import">
                    <label>ملف النسخة<input type="file" name="backup_file" accept=".kinan,application/json" required></label>
                    <label>كلمة مرور النسخة<input type="password" name="backup_password" required></label><button class="btn warning">استعادة النسخة</button>
                </form></div>
                <div class="panel"><h2>ربط Google Drive</h2>
                    <?php $drive=getDriveConfig(); ?>
                    <p><?= ($drive['configured']??false) ? 'تم حفظ إعداد محلي للمستخدم؛ اختبارات OAuth والرفع مطلوبة.' : 'لم يتم ربط حساب Google Drive بعد.' ?></p>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="drive_save">
                        <label>Client ID<input name="client_id" value="<?= e((string)($drive['client_id']??'')) ?>"></label>
                        <label>Client Secret<input type="password" name="client_secret" value="<?= e((string)($drive['client_secret']??'')) ?>"></label>
                        <label>Refresh Token<input type="password" name="refresh_token" value="<?= e((string)($drive['refresh_token']??'')) ?>"></label>
                        <label>معرّف مجلد Drive<input name="folder_id" value="<?= e((string)($drive['folder_id']??'')) ?>"></label>
                        <button class="btn primary">حفظ إعدادات Google Drive</button>
                    </form>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="drive_backup_now">
                        <label>كلمة مرور النسخة المشفرة للدفع الآن<input type="password" name="backup_password" minlength="8" required></label>
                        <button class="btn primary">إنشاء ورفع نسخة مشفرة الآن</button>
                    </form>
                </div>
            </div>
        <?php endif ?>
        </section>
    </main>
<?php endif ?>
<script src="assets/app.js"></script>
</body>
</html>
