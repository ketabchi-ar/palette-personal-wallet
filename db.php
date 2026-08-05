<?php
// ============================================
// db.php — مدیریت دیتابیس SQLite
// ============================================
// این فایل ساختار دیتابیس را ایجاد می‌کند و توابع کمکی را فراهم می‌سازد.
// SQLite برای هاست اشتراکی ایده‌آل است: بدون نیاز به سرور دیتابیس،
// فقط یک فایل است و با PDO کار می‌کند.

require_once __DIR__ . '/config.php';

if (defined('MALI_DB')) return;
define('MALI_DB', true);

// ============================================
// اتصال به دیتابیس و ایجاد ساختار
// ============================================
function getDB() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dbPath = DB_PATH;
    $dbDir = dirname($dbPath);

    // ایجاد پوشه data اگر وجود ندارد
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true);
    }

    // ایجاد فایل index.html خالی در پوشه data برای جلوگیری از directory listing
    $indexGuard = $dbDir . '/index.html';
    if (!file_exists($indexGuard)) {
        file_put_contents($indexGuard, 'Access denied.');
    }

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // فعال‌سازی foreign keys
        $pdo->exec('PRAGMA foreign_keys = ON');
        // بهینه‌سازی SQLite
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
    } catch (PDOException $e) {
        writeLog('DB CONNECTION FAILED: ' . $e->getMessage());
        http_response_code(500);
        die(json_encode(['status' => 'error', 'message' => 'Database connection failed']));
    }

    initSchema($pdo);
    return $pdo;
}

// ============================================
// ایجاد جداول دیتابیس
// ============================================
function initSchema($pdo) {
    // جدول کاربران
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        display_name TEXT DEFAULT '',
        created_at TEXT DEFAULT (datetime('now')),
        last_login TEXT DEFAULT NULL,
        login_attempts INTEGER DEFAULT 0,
        locked_until TEXT DEFAULT NULL,
        is_admin INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1
    )");

    // مهاجرت: اضافه کردن ستون‌های جدید اگر وجود ندارند
    $cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('is_admin', $cols)) $pdo->exec("ALTER TABLE users ADD COLUMN is_admin INTEGER DEFAULT 0");
    if (!in_array('is_active', $cols)) $pdo->exec("ALTER TABLE users ADD COLUMN is_active INTEGER DEFAULT 1");

    // جدول تنظیمات برنامه (برای ذخیره تنظیمات AI و غیره از طریق پنل ادمین)
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
        key TEXT PRIMARY KEY,
        value TEXT DEFAULT '',
        updated_at TEXT DEFAULT (datetime('now'))
    )");

    // جدول تنظیمات AI اختصاصی هر کاربر (هر کاربر کلید API خودش را دارد)
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_ai_settings (
        user_id INTEGER PRIMARY KEY,
        api_base TEXT DEFAULT '',
        api_key TEXT DEFAULT '',
        model TEXT DEFAULT '',
        max_tokens INTEGER DEFAULT 0,
        temperature REAL DEFAULT 0,
        use_custom INTEGER DEFAULT 0,
        updated_at TEXT DEFAULT (datetime('now')),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // جدول تراکنش‌ها
    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id TEXT PRIMARY KEY,
        user_id INTEGER NOT NULL,
        type TEXT NOT NULL DEFAULT 'expense',
        amount REAL NOT NULL DEFAULT 0,
        description TEXT DEFAULT '',
        category TEXT DEFAULT 'other_expense',
        wallet_id TEXT DEFAULT 'w1',
        date TEXT NOT NULL,
        receipt_url TEXT DEFAULT NULL,
        created_at TEXT DEFAULT (datetime('now')),
        updated_at TEXT DEFAULT (datetime('now')),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // ایندکس‌ها برای سرعت بالاتر
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tx_user_date ON transactions(user_id, date)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tx_user_category ON transactions(user_id, category)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tx_user_type ON transactions(user_id, type)");

    // جدول کیف پول‌ها (کلید ترکیبی: هر کاربر کیف پول‌های خودش را دارد)
    $pdo->exec("CREATE TABLE IF NOT EXISTS wallets (
        id TEXT NOT NULL,
        user_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        sort_order INTEGER DEFAULT 0,
        PRIMARY KEY (user_id, id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // مهاجرت: اگر wallets کلید اصلی قدیمی (فقط id) دارد، به کلید ترکیبی تبدیل کن
    $walletSchema = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='wallets'")->fetchColumn();
    if ($walletSchema && strpos($walletSchema, 'PRIMARY KEY (user_id, id)') === false) {
        // بازسازی جدول wallets با کلید ترکیبی
        $pdo->exec("CREATE TABLE wallets_new (
            id TEXT NOT NULL,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            sort_order INTEGER DEFAULT 0,
            PRIMARY KEY (user_id, id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        $pdo->exec("INSERT INTO wallets_new (id, user_id, name, sort_order) SELECT id, user_id, name, sort_order FROM wallets");
        $pdo->exec("DROP TABLE wallets");
        $pdo->exec("ALTER TABLE wallets_new RENAME TO wallets");
        writeLog('MIGRATION: wallets table converted to composite primary key (user_id, id)');
    }

    // جدول بدهی‌ها
    $pdo->exec("CREATE TABLE IF NOT EXISTS debts (
        id TEXT PRIMARY KEY,
        user_id INTEGER NOT NULL,
        person TEXT NOT NULL,
        type TEXT NOT NULL DEFAULT 'owe_me',
        amount REAL NOT NULL DEFAULT 0,
        settled INTEGER DEFAULT 0,
        created_at TEXT DEFAULT (datetime('now')),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    $debtCols = $pdo->query("PRAGMA table_info(debts)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('paid', $debtCols, true)) $pdo->exec("ALTER TABLE debts ADD COLUMN paid REAL DEFAULT 0");
    if (!in_array('due_date', $debtCols, true)) $pdo->exec("ALTER TABLE debts ADD COLUMN due_date TEXT DEFAULT ''");

    // جدول اقساط ثابت
    $pdo->exec("CREATE TABLE IF NOT EXISTS recurring_txs (
        id TEXT PRIMARY KEY,
        user_id INTEGER NOT NULL,
        description TEXT NOT NULL,
        amount REAL NOT NULL DEFAULT 0,
        category TEXT DEFAULT 'bills',
        type TEXT DEFAULT 'expense',
        due_day INTEGER DEFAULT 1,
        last_applied TEXT DEFAULT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // جدول اهداف مالی
    $pdo->exec("CREATE TABLE IF NOT EXISTS goals (
        id TEXT PRIMARY KEY,
        user_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        target REAL NOT NULL DEFAULT 0,
        saved REAL NOT NULL DEFAULT 0,
        created_at TEXT DEFAULT (datetime('now')),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // جدول دسته‌بندی‌های سفارشی
    $pdo->exec("CREATE TABLE IF NOT EXISTS custom_categories (
        id TEXT PRIMARY KEY,
        user_id INTEGER NOT NULL,
        label TEXT NOT NULL,
        icon TEXT DEFAULT 'bi-tag',
        bg TEXT DEFAULT 'bg-secondary',
        type TEXT NOT NULL DEFAULT 'expense',
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // جدول تنظیمات کاربر (بودجه و غیره)
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_settings (
        user_id INTEGER PRIMARY KEY,
        monthly_budget REAL DEFAULT 0,
        theme TEXT DEFAULT 'light',
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // جدول تنظیمات اعلان‌ها (هر کاربر مشخص می‌کند کدام اعلان‌ها را دریافت کند)
    $pdo->exec("CREATE TABLE IF NOT EXISTS notification_prefs (
        user_id INTEGER PRIMARY KEY,
        budget_warning INTEGER DEFAULT 1,
        recurring_reminder INTEGER DEFAULT 1,
        goal_progress INTEGER DEFAULT 1,
        new_user_notify INTEGER DEFAULT 1,
        user_deactivated INTEGER DEFAULT 1,
        system_error INTEGER DEFAULT 1,
        backup_reminder INTEGER DEFAULT 1,
        updated_at TEXT DEFAULT (datetime('now')),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // جدول مهاجرت داده قدیمی
    $pdo->exec("CREATE TABLE IF NOT EXISTS migration_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        action TEXT NOT NULL,
        detail TEXT DEFAULT '',
        created_at TEXT DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS broadcast_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        message TEXT NOT NULL,
        created_at TEXT DEFAULT (datetime('now')),
        created_by INTEGER,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
}

// ============================================
// توابع کمکی احراز هویت
// ============================================

// شروع session امن
function startSession() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    // جلوگیری از پاک شدن نشست PWA توسط garbage collector پیش‌فرض PHP (معمولاً ۲۴ دقیقه)
    ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

// بررسی ورود به سیستم
function isLoggedIn() {
    startSession();
    return isset($_SESSION['user_id']);
}

// دریافت ID کاربر فعلی
function getCurrentUserId() {
    startSession();
    return $_SESSION['user_id'] ?? null;
}

// دریافت کاربر فعلی
function getCurrentUser() {
    $uid = getCurrentUserId();
    if (!$uid) return null;
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT id, username, display_name, is_admin, is_active FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    return $stmt->fetch();
}

// بررسی قفل‌شدن حساب
function isAccountLocked($pdo, $username) {
    $stmt = $pdo->prepare('SELECT locked_until FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row || !$row['locked_until']) return false;
    return strtotime($row['locked_until']) > time();
}

// ثبت تلاش ناموفق ورود
function recordFailedLogin($pdo, $username) {
    $stmt = $pdo->prepare('SELECT id, login_attempts FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row) return;

    $attempts = $row['login_attempts'] + 1;
    if ($attempts >= MAX_LOGIN_ATTEMPTS) {
        $lockedUntil = date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_MINUTES * 60);
        $pdo->prepare('UPDATE users SET login_attempts = 0, locked_until = ? WHERE id = ?')
            ->execute([$lockedUntil, $row['id']]);
    } else {
        $pdo->prepare('UPDATE users SET login_attempts = ? WHERE id = ?')
            ->execute([$attempts, $row['id']]);
    }
}

// ورود موفق: ریست تلاش‌ها
function recordSuccessfulLogin($pdo, $userId) {
    $pdo->prepare("UPDATE users SET login_attempts = 0, locked_until = NULL, last_login = datetime('now') WHERE id = ?")
        ->execute([$userId]);
}

// ایجاد کاربر جدید
function createUser($pdo, $username, $password, $displayName = '') {
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => PASSWORD_HASH_COST]);
    try {
        // اولین کاربر ادمین می‌شود، یا هر کاربری که نام کاربری‌اش admin باشد
        $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $isAdmin = ($userCount === 0 || strtolower($username) === 'admin') ? 1 : 0;

        $pdo->prepare('INSERT INTO users (username, password_hash, display_name, is_admin) VALUES (?, ?, ?, ?)')
            ->execute([$username, $hash, $displayName, $isAdmin]);
        $userId = $pdo->lastInsertId();

        // ایجاد کیف پول پیش‌فرض
        $pdo->prepare("INSERT INTO wallets (id, user_id, name, sort_order) VALUES ('w1', ?, 'کیف پول اصلی', 0)")
            ->execute([$userId]);

        // ایجاد تنظیمات پیش‌فرض
        $pdo->prepare('INSERT INTO user_settings (user_id, monthly_budget, theme) VALUES (?, 0, ?)')
            ->execute([$userId, 'light']);

        if ($isAdmin) {
            writeLog('FIRST USER registered as ADMIN: ' . $username);
        }

        return $userId;
    } catch (PDOException $e) {
        return false;
    }
}

// بررسی ادمین بودن کاربر فعلی
function isAdmin() {
    $uid = getCurrentUserId();
    if (!$uid) return false;
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    return $row && (int)$row['is_admin'] === 1;
}

// دریافت تنظیمات برنامه
function getAppSetting($pdo, $key, $default = '') {
    $stmt = $pdo->prepare('SELECT value FROM app_settings WHERE key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['value'] : $default;
}

// ذخیره تنظیمات برنامه
function setAppSetting($pdo, $key, $value) {
    $pdo->prepare('INSERT INTO app_settings (key, value, updated_at) VALUES (?, ?, datetime(\'now\')) ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime(\'now\')')
        ->execute([$key, $value]);
}

// دریافت تنظیمات AI سراسری (با fallback به config.php) — تنظیمات پیش‌فرض ادمین
function getAIConfig($pdo) {
    return [
        'api_base' => getAppSetting($pdo, 'ai_api_base', defined('AI_API_BASE') ? AI_API_BASE : 'https://api.openai.com/v1'),
        'api_key' => getAppSetting($pdo, 'ai_api_key', defined('AI_API_KEY') ? AI_API_KEY : ''),
        'model' => getAppSetting($pdo, 'ai_model', defined('AI_MODEL') ? AI_MODEL : 'gpt-4o-mini'),
        'max_tokens' => (int)getAppSetting($pdo, 'ai_max_tokens', defined('AI_MAX_TOKENS') ? AI_MAX_TOKENS : 1000),
        'temperature' => (float)getAppSetting($pdo, 'ai_temperature', defined('AI_TEMPERATURE') ? AI_TEMPERATURE : 0.7),
    ];
}

// دریافت تنظیمات AI اختصاصی کاربر (با fallback به تنظیمات سراسری ادمین)
// اگر کاربر use_custom=1 باشد و کلید API خودش را داشته باشد، از آن استفاده می‌کند
// در غیر این صورت به تنظیمات سراسری (getAIConfig) برمی‌گردد
function getUserAIConfig($pdo, $userId) {
    $stmt = $pdo->prepare('SELECT * FROM user_ai_settings WHERE user_id = ?');
    $stmt->execute([$userId]);
    $userSettings = $stmt->fetch();

    // اگر کاربر تنظیمات اختصاصی ندارد یا use_custom فعال نیست → fallback به سراسری
    if (!$userSettings || (int)$userSettings['use_custom'] !== 1 || empty($userSettings['api_key'])) {
        $global = getAIConfig($pdo);
        $global['source'] = 'global';
        $global['use_custom'] = false;
        return $global;
    }

    // کاربر تنظیمات اختصاصی دارد — اما فیلدهای خالی را با سراسری پر کن
    $global = getAIConfig($pdo);
    return [
        'api_base' => !empty($userSettings['api_base']) ? $userSettings['api_base'] : $global['api_base'],
        'api_key' => $userSettings['api_key'],
        'model' => !empty($userSettings['model']) ? $userSettings['model'] : $global['model'],
        'max_tokens' => (int)$userSettings['max_tokens'] > 0 ? (int)$userSettings['max_tokens'] : $global['max_tokens'],
        'temperature' => (float)$userSettings['temperature'] >= 0 ? (float)$userSettings['temperature'] : $global['temperature'],
        'source' => 'user',
        'use_custom' => true,
    ];
}

// ذخیره تنظیمات AI اختصاصی کاربر
function saveUserAIConfig($pdo, $userId, $data) {
    $pdo->prepare('INSERT INTO user_ai_settings (user_id, api_base, api_key, model, max_tokens, temperature, use_custom, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, datetime(\'now\'))
        ON CONFLICT(user_id) DO UPDATE SET
            api_base = excluded.api_base,
            api_key = excluded.api_key,
            model = excluded.model,
            max_tokens = excluded.max_tokens,
            temperature = excluded.temperature,
            use_custom = excluded.use_custom,
            updated_at = datetime(\'now\')')
        ->execute([
            $userId,
            $data['api_base'] ?? '',
            $data['api_key'] ?? '',
            $data['model'] ?? '',
            (int)($data['max_tokens'] ?? 0),
            (float)($data['temperature'] ?? 0),
            !empty($data['use_custom']) ? 1 : 0,
        ]);
}

// ============================================
// توابع تنظیمات اعلان‌ها
// ============================================

// دریافت تنظیمات اعلان‌های کاربر (اگر رکورد نبود، مقادیر پیش‌فرض برمی‌گرداند)
function getNotificationPrefs($pdo, $userId) {
    $stmt = $pdo->prepare('SELECT budget_warning, recurring_reminder, goal_progress, new_user_notify, user_deactivated, system_error, backup_reminder FROM notification_prefs WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        // مقادیر پیش‌فرض: همه فعال
        return [
            'budget_warning' => 1,
            'recurring_reminder' => 1,
            'goal_progress' => 1,
            'new_user_notify' => 1,
            'user_deactivated' => 1,
            'system_error' => 1,
            'backup_reminder' => 1,
        ];
    }
    return array_map('intval', $row);
}

// ذخیره تنظیمات اعلان‌های کاربر
function saveNotificationPrefs($pdo, $userId, $data) {
    $fields = ['budget_warning', 'recurring_reminder', 'goal_progress', 'new_user_notify', 'user_deactivated', 'system_error', 'backup_reminder'];
    $values = [];
    foreach ($fields as $f) {
        $values[$f] = !empty($data[$f]) ? 1 : 0;
    }
    $pdo->prepare('INSERT INTO notification_prefs (user_id, budget_warning, recurring_reminder, goal_progress, new_user_notify, user_deactivated, system_error, backup_reminder, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime(\'now\'))
        ON CONFLICT(user_id) DO UPDATE SET
            budget_warning = excluded.budget_warning,
            recurring_reminder = excluded.recurring_reminder,
            goal_progress = excluded.goal_progress,
            new_user_notify = excluded.new_user_notify,
            user_deactivated = excluded.user_deactivated,
            system_error = excluded.system_error,
            backup_reminder = excluded.backup_reminder,
            updated_at = datetime(\'now\')')
        ->execute([
            $userId,
            $values['budget_warning'],
            $values['recurring_reminder'],
            $values['goal_progress'],
            $values['new_user_notify'],
            $values['user_deactivated'],
            $values['system_error'],
            $values['backup_reminder'],
        ]);
}

// ============================================
// توابع کمکی داده
// ============================================

// دریافت کل state کاربر (برای backward compatibility با فرانت‌اند)
function getUserState($pdo, $userId) {
    // تراکنش‌ها
    $txs = $pdo->prepare('SELECT id, type, amount, description, category, wallet_id AS walletId, date, receipt_url AS receiptUrl FROM transactions WHERE user_id = ? ORDER BY date DESC');
    $txs->execute([$userId]);
    $transactions = $txs->fetchAll();

    // کیف پول‌ها
    $ws = $pdo->prepare('SELECT id, name FROM wallets WHERE user_id = ? ORDER BY sort_order');
    $ws->execute([$userId]);
    $wallets = $ws->fetchAll();

    // بدهی‌ها
    $ds = $pdo->prepare('SELECT id, person, type, amount, settled, paid, due_date AS dueDate FROM debts WHERE user_id = ? AND settled = 0');
    $ds->execute([$userId]);
    $debts = $ds->fetchAll();

    // اقساط ثابت
    $rs = $pdo->prepare('SELECT id, description, amount, category, type, due_day AS dueDay, last_applied AS lastApplied FROM recurring_txs WHERE user_id = ?');
    $rs->execute([$userId]);
    $recurring = $rs->fetchAll();

    // اهداف
    $gs = $pdo->prepare('SELECT id, name, target, saved FROM goals WHERE user_id = ?');
    $gs->execute([$userId]);
    $goals = $gs->fetchAll();

    // دسته‌بندی‌های سفارشی
    $cs = $pdo->prepare('SELECT id, label, icon, bg, type FROM custom_categories WHERE user_id = ?');
    $cs->execute([$userId]);
    $customCats = $cs->fetchAll();
    $catsByType = ['expense' => [], 'income' => []];
    foreach ($customCats as $c) {
        $catsByType[$c['type']][] = $c;
    }

    // تنظیمات
    $ss = $pdo->prepare('SELECT monthly_budget FROM user_settings WHERE user_id = ?');
    $ss->execute([$userId]);
    $settings = $ss->fetch();
    $budget = $settings ? (float)$settings['monthly_budget'] : 0;

    return [
        'transactions' => $transactions,
        'wallets' => $wallets,
        'debts' => $debts,
        'recurringTxs' => $recurring,
        'goals' => $goals,
        'customCategories' => $catsByType,
        'monthlyBudget' => $budget,
    ];
}

// ذخیره کل state کاربر (full sync)
function saveUserState($pdo, $userId, $data) {
    $pdo->beginTransaction();
    try {
        // پاکسازی داده‌های قدیمی
        $pdo->prepare('DELETE FROM transactions WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM wallets WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM debts WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM recurring_txs WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM goals WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM custom_categories WHERE user_id = ?')->execute([$userId]);

        // درج تراکنش‌ها
        if (!empty($data['transactions'])) {
            $stmt = $pdo->prepare('INSERT INTO transactions (id, user_id, type, amount, description, category, wallet_id, date, receipt_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($data['transactions'] as $tx) {
                $stmt->execute([
                    $tx['id'], $userId, $tx['type'], (float)$tx['amount'],
                    $tx['description'] ?? '', $tx['category'] ?? 'other_expense',
                    $tx['walletId'] ?? 'w1', $tx['date'], $tx['receiptUrl'] ?? null
                ]);
            }
        }

        // درج کیف پول‌ها
        if (!empty($data['wallets'])) {
            $stmt = $pdo->prepare('INSERT INTO wallets (id, user_id, name, sort_order) VALUES (?, ?, ?, ?)');
            $order = 0;
            foreach ($data['wallets'] as $w) {
                $stmt->execute([$w['id'], $userId, $w['name'], $order++]);
            }
        }

        // درج بدهی‌ها
        if (!empty($data['debts'])) {
            $stmt = $pdo->prepare('INSERT INTO debts (id, user_id, person, type, amount, paid, due_date) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($data['debts'] as $d) {
                $stmt->execute([$d['id'], $userId, $d['person'], $d['type'] ?? 'owe_me', (float)$d['amount'], (float)($d['paid'] ?? 0), $d['dueDate'] ?? '']);
            }
        }

        // درج اقساط ثابت
        if (!empty($data['recurringTxs'])) {
            $stmt = $pdo->prepare('INSERT INTO recurring_txs (id, user_id, description, amount, category, type, due_day) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($data['recurringTxs'] as $r) {
                $stmt->execute([
                    $r['id'], $userId, $r['description'], (float)$r['amount'],
                    $r['category'] ?? 'bills', $r['type'] ?? 'expense',
                    $r['dueDay'] ?? 1
                ]);
            }
        }

        // درج اهداف
        if (!empty($data['goals'])) {
            $stmt = $pdo->prepare('INSERT INTO goals (id, user_id, name, target, saved) VALUES (?, ?, ?, ?, ?)');
            foreach ($data['goals'] as $g) {
                $stmt->execute([$g['id'], $userId, $g['name'], (float)$g['target'], (float)($g['saved'] ?? 0)]);
            }
        }

        // درج دسته‌بندی‌های سفارشی
        $allCats = array_merge(
            ($data['customCategories']['expense'] ?? []),
            ($data['customCategories']['income'] ?? [])
        );
        if (!empty($allCats)) {
            $stmt = $pdo->prepare('INSERT INTO custom_categories (id, user_id, label, icon, bg, type) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($allCats as $c) {
                $stmt->execute([
                    $c['id'], $userId, $c['label'],
                    $c['icon'] ?? 'bi-tag', $c['bg'] ?? 'bg-secondary', $c['type'] ?? 'expense'
                ]);
            }
        }

        // به‌روزرسانی بودجه
        $budget = (float)($data['monthlyBudget'] ?? 0);
        $pdo->prepare('INSERT INTO user_settings (user_id, monthly_budget) VALUES (?, ?) ON CONFLICT(user_id) DO UPDATE SET monthly_budget = excluded.monthly_budget')
            ->execute([$userId, $budget]);

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        writeLog('SAVE STATE FAILED (user ' . $userId . '): ' . $e->getMessage());
        return false;
    }
}

// مهاجرت داده قدیمی JSON به SQLite
function migrateJsonToSqlite($pdo, $userId) {
    $jsonPath = __DIR__ . '/database.json';
    if (!file_exists($jsonPath)) return false;

    $raw = file_get_contents($jsonPath);
    $data = json_decode($raw, true);
    if (!$data || !isset($data['transactions'])) return false;

    // بررسی اینکه قبلاً مهاجرت نشده
    $check = $pdo->prepare('SELECT COUNT(*) as cnt FROM migration_log WHERE action = ? AND detail = ?');
    $check->execute(['json_migrate', (string)$userId]);
    if ($check->fetch()['cnt'] > 0) return false;

    saveUserState($pdo, $userId, $data);

    $pdo->prepare('INSERT INTO migration_log (action, detail) VALUES (?, ?)')
        ->execute(['json_migrate', (string)$userId]);

    // بک‌آپ از فایل قدیمی
    $backupDir = __DIR__ . '/backups/';
    if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
    copy($jsonPath, $backupDir . 'database_pre_sqlite_' . date('Ymd_Hi') . '.json');

    writeLog('JSON MIGRATED to SQLite for user ' . $userId);
    return true;
}

// تولید ID یکتا
function generateUUID() {
    return bin2hex(random_bytes(10));
}
?>
