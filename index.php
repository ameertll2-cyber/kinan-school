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

        if (!canManage($user) && $action !== 'attendance_save' && $action !== 'grade_save') {
            $error = 'ليست لديك صلاحية تنفيذ هذا الإجراء';
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
                    'grades'=>'سجل الدرجات','backup'=>'النسخ الاحتياطية'
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
