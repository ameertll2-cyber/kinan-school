<?php
declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbFile = __DIR__ . DIRECTORY_SEPARATOR . 'kinan_school.db';
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL CHECK(role IN ('مدير','معاون','مدرس','محاسب','ولي أمر')),
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS students (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            national_id TEXT,
            class_id INTEGER,
            guardian_name TEXT,
            guardian_phone TEXT,
            address TEXT,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS staff (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            job_title TEXT NOT NULL,
            phone TEXT,
            email TEXT,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS classes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            stage TEXT NOT NULL,
            section TEXT NOT NULL DEFAULT 'أ',
            academic_year TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            code TEXT,
            class_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS attendance (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            date TEXT NOT NULL,
            status TEXT NOT NULL CHECK(status IN ('حاضر','غائب','متأخر','غياب بعذر')),
            note TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(student_id, date)
        );
        CREATE TABLE IF NOT EXISTS grades (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            subject_id INTEGER NOT NULL,
            type TEXT NOT NULL,
            score REAL NOT NULL,
            max_score REAL NOT NULL DEFAULT 100,
            date TEXT NOT NULL,
            note TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS fee_types (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            amount REAL NOT NULL DEFAULT 0,
            academic_year TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_number TEXT NOT NULL UNIQUE,
            student_id INTEGER NOT NULL,
            academic_year TEXT NOT NULL,
            due_date TEXT,
            discount REAL NOT NULL DEFAULT 0,
            note TEXT,
            status TEXT NOT NULL DEFAULT 'غير مدفوع',
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS invoice_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL,
            fee_type_id INTEGER,
            description TEXT NOT NULL,
            amount REAL NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            receipt_number TEXT NOT NULL UNIQUE,
            invoice_id INTEGER NOT NULL,
            student_id INTEGER NOT NULL,
            amount REAL NOT NULL,
            method TEXT NOT NULL CHECK(method IN ('نقدي','تحويل بنكي','بطاقة')),
            paid_at TEXT NOT NULL,
            note TEXT,
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS expenses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category TEXT NOT NULL,
            description TEXT NOT NULL,
            amount REAL NOT NULL,
            expense_date TEXT NOT NULL,
            receipt_ref TEXT,
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS parent_student_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            parent_user_id INTEGER NOT NULL,
            student_id INTEGER NOT NULL,
            relationship TEXT NOT NULL DEFAULT 'ولي أمر',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(parent_user_id, student_id)
        );
        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            audience TEXT NOT NULL DEFAULT 'ولي أمر',
            parent_user_id INTEGER,
            student_id INTEGER,
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS notification_reads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            notification_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            read_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(notification_id, user_id)
        );
        CREATE TABLE IF NOT EXISTS curricula (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            stage TEXT NOT NULL,
            grade_name TEXT NOT NULL,
            academic_year TEXT NOT NULL,
            subject_id INTEGER,
            title TEXT NOT NULL,
            version_label TEXT,
            source_authority TEXT DEFAULT 'وزارة التربية العراقية',
            status TEXT NOT NULL DEFAULT 'معتمد',
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS learning_resources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            resource_type TEXT NOT NULL CHECK(resource_type IN ('كتاب رسمي','محاضرة','واجب','نموذج','مراسلة','ملف إداري')),
            stage TEXT,
            grade_name TEXT,
            subject_id INTEGER,
            academic_year TEXT,
            file_name TEXT,
            file_path TEXT,
            external_url TEXT,
            description TEXT,
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS assignments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            subject_id INTEGER NOT NULL,
            class_id INTEGER,
            description TEXT,
            due_date TEXT,
            attachment_path TEXT,
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS lectures (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            subject_id INTEGER,
            class_id INTEGER,
            video_url TEXT,
            attachment_path TEXT,
            description TEXT,
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS official_forms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            form_code TEXT,
            category TEXT NOT NULL,
            academic_year TEXT,
            file_path TEXT,
            description TEXT,
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS correspondence (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            correspondence_type TEXT NOT NULL CHECK(correspondence_type IN ('صادر','وارد')),
            reference_number TEXT NOT NULL UNIQUE,
            recipient TEXT,
            subject TEXT NOT NULL,
            body TEXT,
            correspondence_date TEXT NOT NULL,
            attachment_path TEXT,
            status TEXT NOT NULL DEFAULT 'مسودة',
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS counseling_cases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            category TEXT NOT NULL,
            priority TEXT NOT NULL DEFAULT 'متوسط',
            status TEXT NOT NULL DEFAULT 'مفتوحة',
            summary TEXT,
            opened_at TEXT NOT NULL,
            closed_at TEXT,
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS behavior_notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            incident_date TEXT NOT NULL,
            behavior_type TEXT NOT NULL CHECK(behavior_type IN ('إيجابي','سلبي','محايد')),
            category TEXT NOT NULL,
            description TEXT NOT NULL,
            action_taken TEXT,
            notify_parent INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS improvement_plans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            goals TEXT NOT NULL,
            interventions TEXT,
            responsible_person TEXT,
            start_date TEXT NOT NULL,
            review_date TEXT,
            status TEXT NOT NULL DEFAULT 'قيد التنفيذ',
            progress TEXT,
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS counseling_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            case_id INTEGER,
            session_date TEXT NOT NULL,
            session_type TEXT NOT NULL,
            summary TEXT,
            recommendations TEXT,
            follow_up_date TEXT,
            follow_up_status TEXT NOT NULL DEFAULT 'مطلوب',
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS exam_calendars (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            academic_year TEXT NOT NULL,
            stage TEXT NOT NULL,
            grade_name TEXT NOT NULL,
            period_name TEXT NOT NULL,
            exam_round TEXT NOT NULL DEFAULT 'الدور الأول',
            study_type TEXT NOT NULL DEFAULT 'صباحي نظامي',
            start_date TEXT,
            end_date TEXT,
            result_date TEXT,
            active INTEGER NOT NULL DEFAULT 1,
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(academic_year,stage,grade_name,period_name,exam_round,study_type)
        );
        CREATE TABLE IF NOT EXISTS grade_policies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            academic_year TEXT NOT NULL,
            stage TEXT NOT NULL,
            grade_name TEXT NOT NULL,
            period_name TEXT NOT NULL,
            daily_weight REAL NOT NULL DEFAULT 20,
            monthly_weight REAL NOT NULL DEFAULT 20,
            midterm_weight REAL NOT NULL DEFAULT 20,
            final_weight REAL NOT NULL DEFAULT 40,
            pass_score REAL NOT NULL DEFAULT 50,
            final_max_score REAL NOT NULL DEFAULT 100,
            active INTEGER NOT NULL DEFAULT 1,
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(academic_year,stage,grade_name,period_name)
        );
        CREATE TABLE IF NOT EXISTS final_results (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            subject_id INTEGER,
            academic_year TEXT NOT NULL,
            period_name TEXT NOT NULL,
            exam_round TEXT NOT NULL,
            study_type TEXT NOT NULL,
            daily_score REAL NOT NULL DEFAULT 0,
            monthly_score REAL NOT NULL DEFAULT 0,
            midterm_score REAL NOT NULL DEFAULT 0,
            final_score REAL NOT NULL DEFAULT 0,
            calculated_score REAL NOT NULL DEFAULT 0,
            result_status TEXT NOT NULL DEFAULT 'قيد الحساب',
            result_date TEXT,
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(student_id,subject_id,academic_year,period_name,exam_round)
        );
    ");

    foreach ([
        "ALTER TABLE grades ADD COLUMN term TEXT NOT NULL DEFAULT 'الفصل الأول'",
        "ALTER TABLE grades ADD COLUMN assessment_type TEXT NOT NULL DEFAULT 'شهري'",
        "ALTER TABLE grades ADD COLUMN created_by INTEGER",
        "ALTER TABLE students ADD COLUMN birth_date TEXT",
        "ALTER TABLE students ADD COLUMN gender TEXT",
        "ALTER TABLE students ADD COLUMN enrollment_number TEXT",
        "ALTER TABLE students ADD COLUMN study_type TEXT NOT NULL DEFAULT 'صباحي نظامي'",
        "ALTER TABLE grades ADD COLUMN academic_year TEXT NOT NULL DEFAULT '2026-2027'",
        "ALTER TABLE grades ADD COLUMN period_name TEXT NOT NULL DEFAULT 'الفصل الأول'",
        "ALTER TABLE grades ADD COLUMN exam_round TEXT NOT NULL DEFAULT 'الدور الأول'",
        "ALTER TABLE grades ADD COLUMN study_type TEXT NOT NULL DEFAULT 'صباحي نظامي'",
        "ALTER TABLE staff ADD COLUMN specialization TEXT",
        "ALTER TABLE staff ADD COLUMN staff_number TEXT",
    ] as $migration) {
        $columnNames = [
            'term','assessment_type','created_by','birth_date','gender','enrollment_number',
            'study_type','academic_year','period_name','exam_round','specialization','staff_number'
        ];
        $columns = '';
        foreach ($columnNames as $candidate) {
            if (str_contains($migration, ' ADD COLUMN ' . $candidate . ' ')) {
                $columns = $candidate;
                break;
            }
        }
        $table = str_contains($migration, 'ALTER TABLE grades') ? 'grades'
            : (str_contains($migration, 'ALTER TABLE students') ? 'students' : 'staff');
        $existingColumns = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        if (!in_array($columns, $existingColumns, true)) {
            $pdo->exec($migration);
        }
    }

    seedDemoData($pdo);
    return $pdo;
}

function seedDemoData(PDO $pdo): void {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count === 0) {
        $users = [
            ['مدير النظام', 'admin', '123456', 'مدير'],
            ['نورا المعاون', 'assistant', '123456', 'معاون'],
            ['أحمد المدرس', 'teacher', '123456', 'مدرس'],
            ['سارة المحاسبة', 'accountant', '123456', 'محاسب'],
            ['ولي الطالب', 'parent', '123456', 'ولي أمر'],
        ];
        $stmt = $pdo->prepare('INSERT INTO users (name, username, password_hash, role) VALUES (?, ?, ?, ?)');
        foreach ($users as $user) {
            $stmt->execute([$user[0], $user[1], password_hash($user[2], PASSWORD_DEFAULT), $user[3]]);
        }
    }

    $count = (int)$pdo->query("SELECT COUNT(*) FROM classes")->fetchColumn();
    if ($count === 0) {
        $pdo->exec("INSERT INTO classes (name, stage, section, academic_year) VALUES
            ('الصف الخامس', 'ابتدائي', 'أ', '2026-2027'),
            ('الصف السادس', 'ابتدائي', 'ب', '2026-2027'),
            ('الصف الأول متوسط', 'متوسط', 'أ', '2026-2027')");
    }

    $count = (int)$pdo->query("SELECT COUNT(*) FROM subjects")->fetchColumn();
    if ($count === 0) {
        $pdo->exec("INSERT INTO subjects (name, code, class_id) VALUES
            ('اللغة العربية', 'AR101', 1),
            ('الرياضيات', 'MA101', 1),
            ('العلوم', 'SC101', 1),
            ('التربية الإسلامية', 'IS101', 1)");
    }

    $count = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
    if ($count === 0) {
        $pdo->exec("INSERT INTO students (full_name, national_id, class_id, guardian_name, guardian_phone, address) VALUES
            ('يوسف محمد علي', '1001001001', 1, 'محمد علي', '07700000001', 'بغداد'),
            ('مريم أحمد حسن', '1001001002', 1, 'أحمد حسن', '07700000002', 'بغداد'),
            ('علي خالد سالم', '1001001003', 2, 'خالد سالم', '07700000003', 'النجف')");
    }

    $count = (int)$pdo->query("SELECT COUNT(*) FROM staff")->fetchColumn();
    if ($count === 0) {
        $pdo->exec("INSERT INTO staff (full_name, job_title, phone, email) VALUES
            ('أحمد محمود', 'مدرس رياضيات', '07700000101', 'ahmad@example.edu.iq'),
            ('نورا سامي', 'مديرة شعبة', '07700000102', 'nora@example.edu.iq')");
    }

    $count = (int)$pdo->query("SELECT COUNT(*) FROM fee_types")->fetchColumn();
    if ($count === 0) {
        $pdo->exec("INSERT INTO fee_types (name, amount, academic_year) VALUES
            ('الرسوم الدراسية', 750000, '2026-2027'),
            ('رسوم النقل', 150000, '2026-2027'),
            ('الأنشطة والرياضة', 50000, '2026-2027')");
    }

    $count = (int)$pdo->query("SELECT COUNT(*) FROM parent_student_links")->fetchColumn();
    if ($count === 0) {
        $parentId = (int)$pdo->query("SELECT id FROM users WHERE username='parent'")->fetchColumn();
        if ($parentId) {
            $pdo->prepare('INSERT OR IGNORE INTO parent_student_links (parent_user_id,student_id,relationship) VALUES (?,?,?)')
                ->execute([$parentId, 1, 'ولي أمر']);
        }
    }

    $count = (int)$pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
    if ($count === 0) {
        $pdo->exec("INSERT INTO notifications (title,body,audience,created_by) VALUES
            ('مرحبا بكم في بوابة ولي الأمر','يمكنكم متابعة الحضور والغياب والإشعارات الخاصة بالطالب المرتبط بحسابكم.','ولي أمر',1)");
    }

    $count = (int)$pdo->query("SELECT COUNT(*) FROM curricula")->fetchColumn();
    if ($count === 0) {
        $pdo->exec("INSERT INTO curricula (stage,grade_name,academic_year,subject_id,title,version_label,source_authority,status,created_by) VALUES
            ('ابتدائي','الصف الخامس','2026-2027',1,'المنهج الرسمي للغة العربية','نسخة إلكترونية مرجعية','وزارة التربية العراقية','معتمد',1),
            ('ابتدائي','الصف الخامس','2026-2027',2,'المنهج الرسمي للرياضيات','نسخة إلكترونية مرجعية','وزارة التربية العراقية','معتمد',1),
            ('متوسط','الصف الأول متوسط','2026-2027',3,'المنهج الرسمي للعلوم','نسخة إلكترونية مرجعية','وزارة التربية العراقية','معتمد',1)");
    }

    $count = (int)$pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn();
    if ($count === 0) {
        $pdo->exec("INSERT INTO invoices (invoice_number, student_id, academic_year, due_date, discount, note, status, created_by)
            VALUES ('INV-2026-0001', 1, '2026-2027', '2026-12-01', 0, 'رسوم تجريبية', 'غير مدفوع', 1)");
        $pdo->exec("INSERT INTO invoice_items (invoice_id, fee_type_id, description, amount)
            VALUES (1, 1, 'الرسوم الدراسية', 750000), (1, 3, 'الأنشطة والرياضة', 50000)");
    }
}
