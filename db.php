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
    ");

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

    $count = (int)$pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn();
    if ($count === 0) {
        $pdo->exec("INSERT INTO invoices (invoice_number, student_id, academic_year, due_date, discount, note, status, created_by)
            VALUES ('INV-2026-0001', 1, '2026-2027', '2026-12-01', 0, 'رسوم تجريبية', 'غير مدفوع', 1)");
        $pdo->exec("INSERT INTO invoice_items (invoice_id, fee_type_id, description, amount)
            VALUES (1, 1, 'الرسوم الدراسية', 750000), (1, 3, 'الأنشطة والرياضة', 50000)");
    }
}
