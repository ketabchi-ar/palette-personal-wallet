<?php
// ============================================
// api.php — API اصلی برنامه مالی
// نسخه ۷: SQLite + چندکاربره + هوش مصنوعی
// ============================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors','0');

// محدود کردن CORS به دامنه خود
$allowedOrigins = [
    'https://' . ($_SERVER['HTTP_HOST'] ?? ''),
    'http://' . ($_SERVER['HTTP_HOST'] ?? ''),
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token, X-Data-Version');
header('Access-Control-Allow-Credentials: true');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

// پاسخ به preflight
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$pdo = getDB();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!function_exists('jsonResponse')) { function jsonResponse($data, $status=200){ http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); } }

// ============================================
// مسیریابی API
// ============================================
try {
    switch ($action) {
        // ---------- احراز هویت ----------
        case 'register':
            handleRegister();
            break;
        case 'login':
            handleLogin();
            break;
        case 'logout':
            handleLogout();
            break;
        case 'me':
            handleMe();
            break;

        // ---------- داده ----------
        case 'state':
            handleGetState();
            break;
        case 'save':
            handleSaveState();
            break;
        case 'migrate':
            handleMigrate();
            break;

        // ---------- تراکنش‌ها (incremental) ----------
        case 'tx_add':
            handleAddTransaction();
            break;
        case 'tx_update':
            handleUpdateTransaction();
            break;
        case 'tx_delete':
            handleDeleteTransaction();
            break;

        // ---------- آپلود فایل ----------
        case 'upload':
            handleUpload();
            break;
        case 'deleteFile':
            handleDeleteFile();
            break;

        // ---------- هوش مصنوعی ----------
        case 'ai_chat':
            handleAIChat();
            break;
        case 'ai_parse':
            handleAIParseTransaction();
            break;
        case 'ai_ocr_receipt':
            handleAIOcrReceipt();
            break;
        case 'ai_insights':
            handleAIInsights();
            break;
        case 'ai_test':
            handleAITest();
            break;
        case 'ai_settings':
            handleUserAISettings();
            break;
        case 'ai_models':
            handleAIModels();
            break;
        case 'messages':
            handleUserMessages();
            break;

        // ---------- تنظیمات اعلان‌ها ----------
        case 'notification_prefs':
            handleNotificationPrefs();
            break;

        // ---------- پنل ادمین ----------
        case 'admin_stats':
            handleAdminStats();
            break;
        case 'admin_users':
            handleAdminUsers();
            break;
        case 'admin_toggle_user':
            handleAdminToggleUser();
            break;
        case 'admin_delete_user':
            handleAdminDeleteUser();
            break;
        case 'admin_ai_settings':
            handleAdminAISettings();
            break;
        case 'admin_backup_db':
            handleAdminBackupDB();
            break;
        case 'admin_restore_db':
            handleAdminRestoreDB();
            break;
        case 'admin_reset_password':
            handleAdminResetPassword();
            break;
        case 'admin_broadcast':
            handleAdminBroadcast();
            break;
        case 'admin_logs':
            handleAdminLogs();
            break;

        default:
            // backward compatibility: اگر action مشخص نشده و POST بود، save
            if ($method === 'POST' && empty($_GET['deleteFile'])) {
                handleSaveState();
            } else {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Unknown action: ' . $action]);
            }
    }
} catch (Exception $e) {
    writeLog('API ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
}

writeLog('API END — action=' . $action);
exit;

// ============================================
// توابع احراز هویت
// ============================================
function requireAuth() {
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
        exit;
    }
    return getCurrentUserId();
}

function handleRegister() {
    global $pdo;
    $input = json_decode(file_get_contents('php://input'), true);
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $displayName = trim($input['displayName'] ?? '');

    if (strlen($username) < 3 || strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'نام کاربری حداقل ۳ و رمز حداقل ۶ کاراکتر']);
        return;
    }

    $userId = createUser($pdo, $username, $password, $displayName);
    if ($userId === false) {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'این نام کاربری قبلاً ثبت شده']);
        return;
    }

    // ورود خودکار پس از ثبت‌نام
    startSession();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    recordSuccessfulLogin($pdo, $userId);

    echo json_encode([
        'status' => 'success',
        'message' => 'ثبت‌نام موفق',
        'user' => ['id' => $userId, 'username' => $username, 'displayName' => $displayName]
    ]);
}

function handleLogin() {
    global $pdo;
    $input = json_decode(file_get_contents('php://input'), true);
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($username) || empty($password)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'نام کاربری و رمز الزامی است']);
        return;
    }

    // بررسی قفل بودن حساب
    if (isAccountLocked($pdo, $username)) {
        http_response_code(423);
        echo json_encode(['status' => 'error', 'message' => 'حساب به دلیل تلاش‌های ناموفق متعدد قفل شده. بعداً تلاش کنید.']);
        return;
    }

    $stmt = $pdo->prepare('SELECT id, username, password_hash, display_name, is_admin, is_active FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        recordFailedLogin($pdo, $username);
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'نام کاربری یا رمز اشتباه است']);
        return;
    }

    // ارتقای خودکار کاربر admin به ادمین (برای دیتابیس‌های موجود)
    if (strtolower($username) === 'admin' && (int)$user['is_admin'] !== 1) {
        $pdo->prepare('UPDATE users SET is_admin = 1 WHERE id = ?')->execute([$user['id']]);
        $user['is_admin'] = 1;
        writeLog('AUTO-PROMOTED admin user to admin: ' . $username);
    }

    // بررسی فعال بودن حساب
    if (isset($user['is_active']) && (int)$user['is_active'] === 0) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'حساب شما غیرفعال شده است. با مدیر تماس بگیرید.']);
        return;
    }

    recordSuccessfulLogin($pdo, $user['id']);
    startSession();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];

    echo json_encode([
        'status' => 'success',
        'message' => 'ورود موفق',
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'displayName' => $user['display_name'],
            'isAdmin' => (int)($user['is_admin'] ?? 0) === 1
        ]
    ]);
}

function handleLogout() {
    startSession();
    session_destroy();
    echo json_encode(['status' => 'success', 'message' => 'خروج موفق']);
}

function handleMe() {
    $user = getCurrentUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
        return;
    }
    echo json_encode([
        'status' => 'success',
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'displayName' => $user['display_name'],
            'isAdmin' => (int)($user['is_admin'] ?? 0) === 1
        ]
    ]);
}

// ============================================
// توابع داده
// ============================================
function handleGetState() {
    global $pdo;
    $userId = requireAuth();
    $state = getUserState($pdo, $userId);
    echo json_encode(['status' => 'success', 'data' => $state]);
}

function handleSaveState() {
    global $pdo;
    $userId = requireAuth();

    $raw = file_get_contents('php://input');
    if (strlen($raw) > 10 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['status' => 'error', 'message' => 'Payload too large']);
        return;
    }

    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
        return;
    }
    if (!is_array($data) || !isset($data['transactions']) || !is_array($data['transactions'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid data structure']);
        return;
    }
    if (count($data['transactions']) > 10000) {
        http_response_code(413);
        echo json_encode(['status' => 'error', 'message' => 'Too many transactions']);
        return;
    }

    // اعتبارسنجی تراکنش‌ها
    foreach ($data['transactions'] as $idx => $tx) {
        if (!is_array($tx) || !isset($tx['id'], $tx['type'], $tx['amount'], $tx['date']) || !is_string($tx['id']) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/', $tx['id']) || (isset($tx['description']) && (!is_string($tx['description']) || strlen($tx['description']) > 500))) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => "Invalid transaction at index $idx"]);
            return;
        }
        if (!in_array($tx['type'], ['expense', 'income'], true)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => "Invalid type at index $idx"]);
            return;
        }
        if (!is_numeric($tx['amount']) || $tx['amount'] < 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => "Invalid amount at index $idx"]);
            return;
        }
    }

    // بک‌آپ خودکار
    $backupDir = __DIR__ . '/backups/';
    if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
    $backupName = 'user_' . $userId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.json';
    file_put_contents($backupDir . $backupName, $raw);
    // نگهداری ۱۴ بک‌آپ اخیر
    $backups = glob($backupDir . 'user_' . $userId . '_*.json');
    if ($backups && count($backups) > 14) {
        usort($backups, fn($a, $b) => filemtime($a) - filemtime($b));
        foreach (array_slice($backups, 0, count($backups) - 14) as $old) unlink($old);
    }

    if (saveUserState($pdo, $userId, $data)) {
        writeLog('SAVE OK — user ' . $userId . ' — ' . count($data['transactions']) . ' txs');
        echo json_encode(['status' => 'success', 'message' => 'Data saved']);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Save failed']);
    }
}

function handleMigrate() {
    global $pdo;
    $userId = requireAuth();
    $result = migrateJsonToSqlite($pdo, $userId);
    echo json_encode(['status' => $result ? 'success' : 'info', 'message' => $result ? 'مهاجرت انجام شد' : 'داده‌ای برای مهاجرت نیست یا قبلاً انجام شده']);
}

// ============================================
// تراکنش‌های تدریجی (Incremental)
// ============================================
function handleAddTransaction() {
    global $pdo;
    $userId = requireAuth();
    $input = json_decode(file_get_contents('php://input'), true);

    $id = $input['id'] ?? generateUUID();
    $type = $input['type'] ?? 'expense';
    $amount = (float)($input['amount'] ?? 0);
    $desc = $input['description'] ?? '';
    $category = $input['category'] ?? 'other_expense';
    $walletId = $input['walletId'] ?? 'w1';
    $date = $input['date'] ?? '';
    $receiptUrl = $input['receiptUrl'] ?? null;

    if (!in_array($type, ['expense', 'income'], true) || $amount <= 0 || empty($date)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid transaction data']);
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO transactions (id, user_id, type, amount, description, category, wallet_id, date, receipt_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$id, $userId, $type, $amount, $desc, $category, $walletId, $date, $receiptUrl]);

    echo json_encode(['status' => 'success', 'id' => $id]);
}

function handleUpdateTransaction() {
    global $pdo;
    $userId = requireAuth();
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';

    if (empty($id)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'ID required']);
        return;
    }

    $fields = [];
    $values = [];
    foreach (['type', 'amount', 'description', 'category', 'walletId', 'date', 'receiptUrl'] as $f) {
        if (isset($input[$f])) {
            $col = $f === 'walletId' ? 'wallet_id' : ($f === 'receiptUrl' ? 'receipt_url' : $f);
            $fields[] = "$col = ?";
            $values[] = $input[$f];
        }
    }
    $fields[] = "updated_at = datetime('now')";

    if (empty($fields)) {
        echo json_encode(['status' => 'success', 'message' => 'Nothing to update']);
        return;
    }

    $values[] = $id;
    $values[] = $userId;
    $sql = 'UPDATE transactions SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?';
    $pdo->prepare($sql)->execute($values);

    echo json_encode(['status' => 'success']);
}

function handleDeleteTransaction() {
    global $pdo;
    $userId = requireAuth();
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';

    // حذف فاکتور مرتبط
    $stmt = $pdo->prepare('SELECT receipt_url FROM transactions WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch();
    if ($row && $row['receipt_url']) {
        $realPath = realpath(__DIR__ . '/uploads/' . basename($row['receipt_url']));
        if ($realPath && strpos($realPath, realpath(__DIR__ . '/uploads/')) === 0) {
            @unlink($realPath);
        }
    }

    $pdo->prepare('DELETE FROM transactions WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
    echo json_encode(['status' => 'success']);
}

// ============================================
// آپلود و حذف فایل
// ============================================
function handleUpload() {
    $userId = requireAuth();
    if (empty($_FILES['receipt'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'No file']);
        return;
    }
    $file = $_FILES['receipt'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Upload error']);
        return;
    }
    // بررسی نوع فایل
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($mime, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Only images allowed']);
        return;
    }
    // محدودیت حجم ۵MB
    if ($file['size'] > 5 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['status' => 'error', 'message' => 'File too large (max 5MB)']);
        return;
    }

    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime];
    $name = 'receipt_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = __DIR__ . '/uploads/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to save file']);
        return;
    }
    echo json_encode(['status' => 'success', 'url' => 'uploads/' . $name]);
}

function handleDeleteFile() {
    $userId = requireAuth();
    $fn = $_GET['deleteFile'] ?? '';
    if (empty($fn)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Filename required']);
        return;
    }
    $realPath = realpath(__DIR__ . '/uploads/' . basename($fn));
    $uploadsReal = realpath(__DIR__ . '/uploads/');
    if (!$realPath || !$uploadsReal || strpos($realPath, $uploadsReal) !== 0) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Access denied']);
        return;
    }
    @unlink($realPath);
    echo json_encode(['status' => 'success', 'message' => 'File deleted']);
}

// ============================================
// هوش مصنوعی (OpenAI Compatible API)
// ============================================
function buildAIContext($userId) {
    global $pdo;
    $state = getUserState($pdo, $userId);
    $txs = $state['transactions'];

    // محاسبه خلاصه مالی
    $totalIncome = 0;
    $totalExpense = 0;
    $catTotals = [];
    $categoryLabels = [
        'supermarket'=>'سوپری و مواد غذایی','transport'=>'حمل‌ونقل','bills'=>'قبوض و اقساط',
        'other_expense'=>'سایر هزینه‌ها','salary'=>'حقوق','other_income'=>'سایر درآمدها'
    ];
    foreach (($state['categories'] ?? []) as $customCat) {
        if (!empty($customCat['id']) && !empty($customCat['name'])) $categoryLabels[$customCat['id']] = $customCat['name'];
    }
    $monthlyData = [];

    foreach ($txs as $tx) {
        $month = substr($tx['date'], 0, 7); // YYYY/MM
        if (!isset($monthlyData[$month])) $monthlyData[$month] = ['inc' => 0, 'exp' => 0];
        if ($tx['type'] === 'income') {
            $totalIncome += $tx['amount'];
            $monthlyData[$month]['inc'] += $tx['amount'];
        } else {
            $totalExpense += $tx['amount'];
            $monthlyData[$month]['exp'] += $tx['amount'];
            $catTotals[$tx['category']] = ($catTotals[$tx['category']] ?? 0) + $tx['amount'];
        }
    }

    $balance = $totalIncome - $totalExpense;
    $txCount = count($txs);

    // تمام ماه‌ها با نام فارسی؛ مدل نباید از جمع کل به جای ماه انتخابی استفاده کند.
    $monthNames = [1=>'فروردین',2=>'اردیبهشت',3=>'خرداد',4=>'تیر',5=>'مرداد',6=>'شهریور',7=>'مهر',8=>'آبان',9=>'آذر',10=>'دی',11=>'بهمن',12=>'اسفند'];
    $recentMonths = array_keys($monthlyData);
    $recentSummary = [];
    foreach ($recentMonths as $m) {
        $parts = explode('/', $m); $label = (isset($parts[1]) && isset($monthNames[(int)$parts[1]])) ? $monthNames[(int)$parts[1]] . ' ' . ($parts[0] ?? '') : $m;
        $net = $monthlyData[$m]['inc'] - $monthlyData[$m]['exp'];
        $recentSummary[] = "$label (کلید $m): درآمد=" . number_format($monthlyData[$m]['inc']) . " تومان، هزینه=" . number_format($monthlyData[$m]['exp']) . " تومان، تراز=" . number_format($net) . " تومان";
    }

    // دسته‌بندی‌های پرخرج
    arsort($catTotals);
    $topCats = array_slice($catTotals, 0, 5, true);
    $catSummary = [];
    foreach ($topCats as $cat => $amt) {
        $catSummary[] = ($categoryLabels[$cat] ?? 'دسته‌بندی نامشخص') . ": " . number_format($amt) . " تومان";
    }

    $budget = $state['monthlyBudget'] ?? 0;
    $currentMonth = substr(date('Y-m'), 0, 7);

    $context = "خلاصه مالی کاربر:\n";
    $context .= "- تعداد تراکنش‌ها: $txCount\n";
    $context .= "- موجودی کل: " . number_format($balance) . " تومان\n";
    $context .= "- کل درآمد: " . number_format($totalIncome) . " تومان\n";
    $context .= "- کل هزینه: " . number_format($totalExpense) . " تومان\n";
    if ($budget > 0) {
        $currentExp = $monthlyData[$currentMonth]['exp'] ?? 0;
        $context .= "- بودجه ماهانه: " . number_format($budget) . " تومان\n";
        $context .= "- هزینه ماه جاری: " . number_format($currentExp) . " تومان\n";
        $context .= "- درصد مصرف بودجه: " . round(($currentExp / $budget) * 100) . "%\n";
    }
    if (!empty($recentSummary)) {
        $context .= "- تفکیک کامل ماه‌ها (برای پاسخ به ماه مشخص فقط همان ردیف را استفاده کن):\n  " . implode("\n  ", $recentSummary) . "\n";
    }
    if (!empty($catSummary)) {
        $context .= "- دسته‌بندی‌های پرخرج:\n  " . implode("\n  ", $catSummary) . "\n";
    }
    if (!empty($state['debts'])) {
        $context .= "- تعداد بدهی‌ها/طلب‌ها: " . count($state['debts']) . "\n";
    }
    if (!empty($state['goals'])) {
        $context .= "- تعداد اهداف مالی: " . count($state['goals']) . "\n";
    }

    return $context;
}

function callAI($systemPrompt, $userMessage, $context = '') {
    global $pdo;
    $userId = getCurrentUserId();
    $config = getUserAIConfig($pdo, $userId);

    if (empty($config['api_key'])) {
        return ['error' => 'کلید API هوش مصنوعی تنظیم نشده است. می‌توانید از تنظیمات AI، کلید اختصاصی خود را وارد کنید یا از مدیر بخواهید تنظیم کند.'];
    }

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
    ];
    if ($context) {
        $messages[] = ['role' => 'system', 'content' => "اطلاعات مالی کاربر:\n" . $context];
    }
    $messages[] = ['role' => 'user', 'content' => $userMessage];

    $payload = [
        'model' => $config['model'],
        'messages' => $messages,
    ];
    // فقط مقادیر معتبر را ارسال کن (برخی APIها max_tokens=0 یا temperature=0 را رد می‌کنند)
    if (!empty($config['max_tokens']) && (int)$config['max_tokens'] > 0) {
        $payload['max_tokens'] = (int)$config['max_tokens'];
    }
    if (isset($config['temperature']) && (float)$config['temperature'] >= 0 && (float)$config['temperature'] <= 2) {
        $payload['temperature'] = (float)$config['temperature'];
    }

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($payloadJson === false) {
        writeLog('AI PAYLOAD JSON ENCODE ERROR: ' . json_last_error_msg());
        return ['error' => 'ساخت درخواست AI ناموفق بود. متن یا داده‌های مالی نامعتبر است.'];
    }
    $ch = curl_init($config['api_base'] . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payloadJson,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payloadJson),
            'Authorization: Bearer ' . $config['api_key'],
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        writeLog('AI CURL ERROR: ' . $error);
        return ['error' => 'Connection error: ' . $error];
    }

    $data = json_decode($response, true);
    if ($httpCode !== 200) {
        writeLog('AI API ERROR (' . $httpCode . '): ' . substr($response, 0, 500));
        if ($data && isset($data['error']['message'])) {
            return ['error' => $data['error']['message']];
        }
        // پاسخ غیر JSON یا فرمت ناشناخته
        $rawMsg = substr($response, 0, 200);
        return ['error' => 'خطای API (HTTP ' . $httpCode . '): ' . $rawMsg];
    }

    // بررسی پاسخ معتبر JSON
    if (!$data) {
        writeLog('AI API INVALID JSON: ' . substr($response, 0, 500));
        return ['error' => 'پاسخ API معتبر نیست (Invalid JSON). ممکن است آدرس API Base یا کلید اشتباه باشد.'];
    }

    $content = $data['choices'][0]['message']['content'] ?? '';
    if (empty($content)) {
        writeLog('AI API EMPTY CONTENT: ' . substr($response, 0, 500));
        return ['error' => 'پاسخ خالی از API دریافت شد.'];
    }
    return ['content' => $content];
}

function handleAIChat() {
    $userId = requireAuth();
    $input = json_decode(file_get_contents('php://input'), true);
    $message = trim($input['message'] ?? '');

    if (empty($message)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'پیام خالی است']);
        return;
    }

    $context = buildAIContext($userId);

    // پاسخ قطعی برای سؤال‌های دخل‌وخرج ماهانه؛ این بخش به حدس مدل وابسته نیست.
    $monthMap = ['فروردین'=>1,'اردیبهشت'=>2,'خرداد'=>3,'تیر'=>4,'مرداد'=>5,'شهریور'=>6,'مهر'=>7,'آبان'=>8,'آذر'=>9,'دی'=>10,'بهمن'=>11,'اسفند'=>12];
    $askedMonth = null; foreach ($monthMap as $name=>$num) { if (mb_strpos($message,$name)!==false) { $askedMonth=$num; break; } }
    if ($askedMonth !== null) {
        global $pdo; $st=$pdo->prepare("SELECT date,type,amount FROM transactions WHERE user_id=?"); $st->execute([$userId]); $inc=0; $exp=0; $count=0; $year=null;
        while($t=$st->fetch(PDO::FETCH_ASSOC)){ $parts=explode('/',strtr($t['date'],['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'])); if(count($parts)>=2 && (int)$parts[1]===$askedMonth){ if($year===null)$year=$parts[0]; if($t['type']==='income')$inc+=(float)$t['amount']; else $exp+=(float)$t['amount']; $count++; } }
        $names=array_keys($monthMap); $label=$names[$askedMonth-1]; if($count===0){ echo json_encode(['status'=>'success','reply'=>"در ماه $label هیچ تراکنشی در داده‌های حساب شما پیدا نشد. اگر تراکنش دارید، تاریخ ذخیره‌شده را بررسی کنید.", 'source'=>'deterministic_monthly_report'],JSON_UNESCAPED_UNICODE); return; }
        $net=$inc-$exp; $tip=$net>=0?'دخل این ماه بیشتر از خرج بوده؛ بخشی از مازاد را برای پس‌انداز کنار بگذارید.':'خرج این ماه بیشتر از دخل بوده؛ دسته‌های پرهزینه را بررسی و برای ماه بعد سقف هزینه تعیین کنید.';
        echo json_encode(['status'=>'success','reply'=>"در ماه $label".($year?" $year":'')."، درآمد شما ".number_format($inc).' تومان و هزینه شما '.number_format($exp).' تومان بوده است. تراز این ماه '.number_format($net).' تومان است.\n\nنکته: '.$tip, 'source'=>'deterministic_monthly_report'],JSON_UNESCAPED_UNICODE); return;
    }

    $systemPrompt = "تو یک مشاور مالی هوشمند فارسی‌زبان هستی. کاربر از یک برنامه مدیریت مالی شخصی استفاده می‌کند. ";
    $systemPrompt .= "بر اساس اطلاعات مالی کاربر، به سوالاتش پاسخ بده، تحلیل بده و پیشنهاد بده. ";
    $systemPrompt .= "پاسخ‌ها را کوتاه، کاربردی و به زبان فارسی بده. ";
    $systemPrompt .= "مبالغ را به تومان و با اعداد فارسی بنویس. ";
    $systemPrompt .= "اگر کاربر نام ماه شمسی مثل فروردین یا اردیبهشت گفت، فقط ردیف همان ماه را از تفکیک کامل ماه‌ها استخراج و درآمد، هزینه و تراز همان ماه را محاسبه کن؛ هرگز از کل درآمد/هزینه استفاده نکن و اگر آن ماه داده‌ای ندارد صریحاً بگو داده‌ای ثبت نشده است. ";
    $systemPrompt .= "اگر کاربر درباره موضوعی غیر مالی سوال کرد، مودبانه راهنمایی‌اش کن که به موضوع مالی برگردد.";

    $result = callAI($systemPrompt, $message, $context);

    if (isset($result['error'])) {
        http_response_code(502);
        echo json_encode(['status' => 'error', 'message' => $result['error']]);
        return;
    }

    echo json_encode(['status' => 'success', 'reply' => $result['content']]);
}

function handleAIOcrReceipt() {
    requireAuth();
    global $pdo;
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $image = (string)($input['image'] ?? '');
    if (!preg_match('#^data:image/(jpeg|jpg|png|webp);base64,#i', $image) || strlen($image) > 7500000) {
        jsonResponse(['status'=>'error','message'=>'تصویر نامعتبر یا بزرگ‌تر از حد مجاز است.'], 400); return;
    }
    $cfg = getUserAIConfig($pdo, $_SESSION['user_id']);
    if (empty($cfg['api_key'])) { jsonResponse(['status'=>'error','message'=>'کلید API هوش مصنوعی تنظیم نشده است.'], 400); return; }
    $payload = ['model'=>$cfg['model'], 'messages'=>[
        ['role'=>'system','content'=>'از تصویر فاکتور اطلاعات را استخراج کن و فقط JSON معتبر برگردان: {"amount":number,"description":string,"date":"YYYY/MM/DD","category":string}. اگر مقداری پیدا نشد null بگذار.'],
        ['role'=>'user','content'=>[['type'=>'text','text'=>'این فاکتور را بخوان و JSON برگردان.'],['type'=>'image_url','image_url'=>['url'=>$image]]]]
    ], 'max_tokens'=>500, 'temperature'=>0.1];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    $ch = curl_init(rtrim($cfg['api_base'],'/').'/chat/completions');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$json,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$cfg['api_key'],'Content-Length: '.strlen($json)],CURLOPT_TIMEOUT=>45]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $res = json_decode($raw,true); $content = $res['choices'][0]['message']['content'] ?? '';
    if (is_array($content)) { $parts=[]; foreach($content as $part){ if(is_string($part)) $parts[]=$part; elseif(isset($part['text'])) $parts[]=$part['text']; } $content=implode("\n",$parts); }
    $content = trim((string)$content); $content = preg_replace('/^```(?:json)?\s*|\s*```$/i','',$content);
    $data = json_decode($content,true);
    if (!is_array($data) && preg_match('/\{.*\}/s',$content,$m)) $data=json_decode($m[0],true);
    if ($code < 200 || $code >= 300 || !is_array($data)) { jsonResponse(['status'=>'error','message'=>'این مدل از خواندن تصویر پشتیبانی نمی‌کند یا پاسخ معتبر نبود.'], 502); return; }
    jsonResponse(['status'=>'success','data'=>$data]);
}

function handleAIParseTransaction() {
    requireAuth();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $text = trim((string)($input['text'] ?? ''));
    if ($text === '' || mb_strlen($text) > 500) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'متن نامعتبر است']); return; }
    $prompt = 'متن زیر را به تراکنش تبدیل کن. فقط JSON معتبر و بدون markdown برگردان. گفتار محاوره‌ای، اعداد فارسی/انگلیسی، جداکننده هزارگان، علامت مثبت، عبارت‌های «خریدم/پرداخت کردم/خرج کردم/واریز شد/دریافت کردم»، نام کالا یا فروشگاه و تاریخ‌هایی مثل امروز، دیروز، جمعه قبل را بفهم. نوع income یا expense، مبلغ تومان به عدد، توضیح کوتاه و یکی از دسته‌های supermarket, transport, bills, other_expense, salary, other_income را تعیین کن. قالب: {"type":"expense","amount":number,"description":"...","category":"..."}. متن: '.$text;
    $result = callAI('تو استخراج‌کننده دقیق تراکنش‌های مالی هستی.', $prompt, '');
    if (isset($result['error'])) {
        // اگر سرویس AI درخواست را مسدود کرد، برای پیام‌های ساده صوتی از استخراج محلی استفاده می‌کنیم.
        $normalized = strtr($text, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٬'=>'', '،'=>'', ','=>'', 'تومان'=>'']);
        preg_match('/(\d[\d\s]*)/u', $normalized, $mm); $fallbackAmount = isset($mm[1]) ? (float)preg_replace('/\s+/','',$mm[1]) : 0;
        if ($fallbackAmount > 0) { $fallbackType = preg_match('/حقوق|درآمد|دریافت|واریز|گرفتم|رسید/u',$text) ? 'income' : 'expense'; echo json_encode(['status'=>'success','data'=>['type'=>$fallbackType,'amount'=>$fallbackAmount,'description'=>$text,'category'=>$fallbackType==='income'?'other_income':'other_expense']], JSON_UNESCAPED_UNICODE); return; }
        http_response_code(502); echo json_encode(['status'=>'error','message'=>'سرویس AI درخواست را مسدود کرد؛ مدل یا سرویس‌دهنده را تغییر دهید.'], JSON_UNESCAPED_UNICODE); return;
    }
    $raw = trim($result['content']);
    $raw = preg_replace('/^```(?:json)?\s*|\s*```$/i','', $raw);
    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        $digits = preg_replace('/[^0-9]/','', str_replace(['٬','،',',','.'],'',$text));
        if ($digits !== '') $parsed = ['type'=>(preg_match('/حقوق|دریافت|درآمد|\+/u',$text)?'income':'expense'),'amount'=>$digits,'description'=>$text];
    }
    if (!is_array($parsed) || !in_array($parsed['type'] ?? '', ['income','expense'], true) || !is_numeric($parsed['amount'] ?? null)) { http_response_code(502); echo json_encode(['status'=>'error','message'=>'پاسخ AI قابل تبدیل به تراکنش نیست']); return; }
    $allowedCategories = ['supermarket','transport','bills','other_expense','salary','other_income'];
    $category = in_array(($parsed['category'] ?? ''), $allowedCategories, true) ? $parsed['category'] : (($parsed['type'] === 'income') ? 'other_income' : 'other_expense');
    echo json_encode(['status'=>'success','data'=>['type'=>$parsed['type'],'amount'=>(float)$parsed['amount'],'description'=>mb_substr((string)($parsed['description'] ?? $text),0,500),'category'=>$category]]);
}

function handleAIInsights() {
    $userId = requireAuth();
    $context = buildAIContext($userId);

    $systemPrompt = "تو یک تحلیلگر مالی هوشمند فارسی‌زبان هستی. ";
    $systemPrompt .= "بر اساس اطلاعات مالی کاربر، ۳ بینش مهم و قابل‌اقدام ارائه بده؛ شامل پیش‌بینی هزینه و موجودی ماه آینده، پیشنهاد بودجه برای دسته‌های اصلی، و هشدار درباره رفتارهای غیرعادی یا افزایش ناگهانی هزینه. ";
    $systemPrompt .= "هر بینش را با یک عنوان کوتاه و توضیح ۱-۲ جمله‌ای بنویس. ";
    $systemPrompt .= "فرمت خروجی به این شکل باشد:\n";
    $systemPrompt .= "1. **عنوان**\n   توضیح\n\n2. **عنوان**\n   توضیح\n\n3. **عنوان**\n   توضیح";

    $result = callAI($systemPrompt, 'لطفاً ۳ بینش مالی مهم بر اساس داده‌های من ارائه بده.', $context);

    if (isset($result['error'])) {
        http_response_code(502);
        echo json_encode(['status' => 'error', 'message' => $result['error']]);
        return;
    }

    echo json_encode(['status' => 'success', 'insights' => $result['content']]);
}

// ============================================
// تست اتصال AI
// ============================================
function handleAITest() {
    $userId = requireAuth();
    global $pdo;
    // استفاده از تنظیمات اختصاصی کاربر (با fallback به سراسری)
    $config = getUserAIConfig($pdo, $userId);

    if (empty($config['api_key'])) {
        echo json_encode(['status' => 'error', 'message' => 'کلید API تنظیم نشده است. می‌توانید از تنظیمات AI، کلید اختصاصی خود را وارد کنید.']);
        return;
    }

    $payload = [
        'model' => $config['model'],
        'messages' => [
            ['role' => 'user', 'content' => 'سلام! فقط بگو "اتصال موفق" تا تست تمام شود.']
        ],
        'max_tokens' => 20,
        'temperature' => 0.3,
    ];

    $ch = curl_init($config['api_base'] . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $config['api_key'],
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    curl_close($ch);

    if ($error) {
        writeLog('AI TEST CURL ERROR: ' . $error);
        echo json_encode(['status' => 'error', 'message' => 'خطای اتصال: ' . $error]);
        return;
    }

    $data = json_decode($response, true);
    if ($httpCode !== 200) {
        $msg = $data['error']['message'] ?? 'خطای ناشناخته (HTTP ' . $httpCode . ')';
        writeLog('AI TEST API ERROR (' . $httpCode . '): ' . substr($response, 0, 300));
        echo json_encode(['status' => 'error', 'message' => $msg, 'http_code' => $httpCode]);
        return;
    }

    $reply = $data['choices'][0]['message']['content'] ?? '';
    $model = $data['model'] ?? $config['model'];

    echo json_encode([
        'status' => 'success',
        'message' => 'اتصال موفق!',
        'model' => $model,
        'reply' => $reply,
        'response_time_ms' => round($totalTime * 1000),
        'api_base' => $config['api_base'],
        'source' => $config['source'] ?? 'global',
    ]);
}

// ============================================
// مدیریت تنظیمات AI اختصاصی کاربر
// GET: دریافت تنظیمات فعلی کاربر (با کلید ماسک‌شده)
// POST: ذخیره تنظیمات اختصاصی کاربر
// ============================================
function handleUserAISettings() {
    $userId = requireAuth();
    global $pdo;

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $config = getUserAIConfig($pdo, $userId);

        // ماسک کردن کلید API برای امنیت
        $maskedKey = '';
        if (!empty($config['api_key'])) {
            $keyLen = strlen($config['api_key']);
            if ($keyLen > 8) {
                $maskedKey = substr($config['api_key'], 0, 4) . '...' . substr($config['api_key'], -4);
            } else {
                $maskedKey = '****';
            }
        }

        // دریافت تنظیمات خام کاربر (اگر وجود دارد)
        $stmt = $pdo->prepare('SELECT api_base, model, max_tokens, temperature, use_custom FROM user_ai_settings WHERE user_id = ?');
        $stmt->execute([$userId]);
        $raw = $stmt->fetch();

        echo json_encode([
            'status' => 'success',
            'config' => [
                'api_base' => $raw ? $raw['api_base'] : '',
                'api_key_masked' => $maskedKey,
                'has_key' => !empty($config['api_key']),
                'model' => $raw ? $raw['model'] : '',
                'max_tokens' => $raw ? (int)$raw['max_tokens'] : 0,
                'temperature' => $raw ? (float)$raw['temperature'] : 0,
                'use_custom' => $raw ? (bool)$raw['use_custom'] : false,
                'source' => $config['source'] ?? 'global',
            ],
            'global_config' => [
                'api_base' => $config['api_base'],
                'model' => $config['model'],
                'max_tokens' => $config['max_tokens'],
                'temperature' => $config['temperature'],
            ]
        ]);
        return;
    }

    // POST: ذخیره تنظیمات
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;

    $data = [
        'api_base' => trim($input['api_base'] ?? ''),
        'api_key' => trim($input['api_key'] ?? ''),
        'model' => trim($input['model'] ?? ''),
        'max_tokens' => (int)($input['max_tokens'] ?? 0),
        'temperature' => (float)($input['temperature'] ?? 0),
        'use_custom' => !empty($input['use_custom']) ? 1 : 0,
    ];

    // اگر api_key خالی است، یعنی کاربر نمی‌خواهد کلید را تغییر دهد → کلید قبلی را نگه دار
    if (empty($data['api_key'])) {
        $stmt = $pdo->prepare('SELECT api_key FROM user_ai_settings WHERE user_id = ?');
        $stmt->execute([$userId]);
        $existing = $stmt->fetch();
        if ($existing && !empty($existing['api_key'])) {
            $data['api_key'] = $existing['api_key'];
        }
    }

    saveUserAIConfig($pdo, $userId, $data);

    writeLog('USER AI SETTINGS SAVED: user_id=' . $userId . ', use_custom=' . $data['use_custom']);

    echo json_encode([
        'status' => 'success',
        'message' => 'تنظیمات AI شما با موفقیت ذخیره شد.',
    ]);
}

// ============================================
// دریافت لیست مدل‌های موجود از API کاربر
// با استفاده از GET {api_base}/models
// ============================================
function handleAIModels() {
    $userId = requireAuth();
    global $pdo;
    $config = getUserAIConfig($pdo, $userId);

    if (empty($config['api_key'])) {
        echo json_encode(['status' => 'error', 'message' => 'کلید API تنظیم نشده است. ابتدا کلید خود را وارد و ذخیره کنید.']);
        return;
    }

    $url = rtrim($config['api_base'], '/') . '/models';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $config['api_key'],
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        writeLog('AI MODELS CURL ERROR: ' . $error);
        echo json_encode(['status' => 'error', 'message' => 'خطای اتصال: ' . $error]);
        return;
    }

    if ($httpCode !== 200) {
        writeLog('AI MODELS API ERROR (' . $httpCode . '): ' . substr($response, 0, 300));
        $data = json_decode($response, true);
        $msg = $data['error']['message'] ?? 'خطای ناشناخته (HTTP ' . $httpCode . ')';
        echo json_encode(['status' => 'error', 'message' => $msg, 'http_code' => $httpCode]);
        return;
    }

    $data = json_decode($response, true);
    $models = [];

    // فرمت استاندارد OpenAI: {"data": [{"id": "model-name", ...}, ...]}
    if (isset($data['data']) && is_array($data['data'])) {
        foreach ($data['data'] as $model) {
            if (isset($model['id'])) {
                $models[] = ['id' => $model['id']];
            }
        }
    } elseif (is_array($data)) {
        // برخی APIها ممکن است فرمت متفاوتی داشته باشند
        foreach ($data as $model) {
            if (is_string($model)) {
                $models[] = ['id' => $model];
            } elseif (is_array($model) && isset($model['id'])) {
                $models[] = ['id' => $model['id']];
            } elseif (is_array($model) && isset($model['name'])) {
                $models[] = ['id' => $model['name']];
            }
        }
    }

    // مرتب‌سازی مدل‌ها بر اساس نام
    usort($models, function($a, $b) {
        return strcmp($a['id'], $b['id']);
    });

    echo json_encode([
        'status' => 'success',
        'models' => $models,
        'count' => count($models),
    ]);
}

// ============================================
// توابع تنظیمات اعلان‌ها
// ============================================
function handleNotificationPrefs() {
    global $pdo;
    $userId = requireAuth();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $prefs = getNotificationPrefs($pdo, $userId);
        echo json_encode(['status' => 'success', 'prefs' => $prefs]);
        return;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'داده نامعتبر']);
            return;
        }
        saveNotificationPrefs($pdo, $userId, $input);
        $prefs = getNotificationPrefs($pdo, $userId);
        echo json_encode(['status' => 'success', 'message' => 'تنظیمات اعلان‌ها ذخیره شد', 'prefs' => $prefs]);
        return;
    }

    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'متد مجاز نیست']);
}

function handleUserMessages() {
    global $pdo;
    $userId = requireAuth();
    $since = (string)($_GET['since'] ?? '');
    $sql = 'SELECT id, title, message, created_at FROM broadcast_messages';
    $params = [];
    if ($since !== '') { $sql .= ' WHERE created_at > ?'; $params[] = $since; }
    $sql .= ' ORDER BY id DESC LIMIT 20';
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    echo json_encode(['status'=>'success','messages'=>$stmt->fetchAll()]);
}

// ============================================
// توابع پنل ادمین
// ============================================
function requireAdmin() {
    $userId = requireAuth();
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'دسترسی ادمین لازم است']);
        exit;
    }
    return $userId;
}

function handleAdminStats() {
    global $pdo;
    requireAdmin();

    $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $activeUsers = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
    $adminCount = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
    $txCount = (int)$pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
    $goalCount = (int)$pdo->query('SELECT COUNT(*) FROM goals')->fetchColumn();
    $debtCount = (int)$pdo->query('SELECT COUNT(*) FROM debts')->fetchColumn();

    $dbSize = file_exists(DB_PATH) ? filesize(DB_PATH) : 0;
    $uploadSize = 0;
    $uploadDir = __DIR__ . '/uploads/';
    if (is_dir($uploadDir)) {
        foreach (glob($uploadDir . '*') as $f) $uploadSize += filesize($f);
    }
    $backupCount = 0;
    $backupDir = __DIR__ . '/backups/';
    if (is_dir($backupDir)) {
        $backupCount = count(glob($backupDir . '*'));
    }

    $recentActive = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE last_login > datetime('now', '-30 minutes')")->fetchColumn();

    echo json_encode([
        'status' => 'success',
        'stats' => [
            'users' => $userCount,
            'activeUsers' => $activeUsers,
            'admins' => $adminCount,
            'recentActive' => $recentActive,
            'transactions' => $txCount,
            'goals' => $goalCount,
            'debts' => $debtCount,
            'dbSize' => $dbSize,
            'uploadSize' => $uploadSize,
            'backupCount' => $backupCount,
        ]
    ]);
}

function handleAdminUsers() {
    global $pdo;
    requireAdmin();

    $stmt = $pdo->query("SELECT u.id, u.username, u.display_name, u.is_admin, u.is_active, u.created_at, u.last_login,
        (SELECT COUNT(*) FROM transactions WHERE user_id = u.id) as tx_count
        FROM users u ORDER BY u.created_at DESC");
    $users = $stmt->fetchAll();

    echo json_encode(['status' => 'success', 'users' => $users]);
}

function handleAdminToggleUser() {
    global $pdo;
    requireAdmin();
    $input = json_decode(file_get_contents('php://input'), true);
    $userId = (int)($input['userId'] ?? 0);
    $field = $input['field'] ?? '';
    $value = (int)($input['value'] ?? 0);

    if (!$userId || !in_array($field, ['is_active', 'is_admin'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'پارامتر نامعتبر']);
        return;
    }

    $currentUid = getCurrentUserId();
    if ($userId === (int)$currentUid) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'نمی‌توانید حساب خود را تغییر دهید']);
        return;
    }

    $pdo->prepare("UPDATE users SET $field = ? WHERE id = ?")->execute([$value, $userId]);
    writeLog("ADMIN toggled user #$userId $field=" . ($value ? 1 : 0));

    echo json_encode(['status' => 'success', 'message' => 'به‌روزرسانی شد']);
}

function handleAdminDeleteUser() {
    global $pdo;
    requireAdmin();
    $input = json_decode(file_get_contents('php://input'), true);
    $userId = (int)($input['userId'] ?? 0);

    if (!$userId) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'ID کاربر الزامی است']);
        return;
    }

    $currentUid = getCurrentUserId();
    if ($userId === (int)$currentUid) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'نمی‌توانید حساب خود را حذف کنید']);
        return;
    }

    $uploadDir = __DIR__ . '/uploads/';
    foreach (glob($uploadDir . 'receipt_' . $userId . '_*') as $f) {
        @unlink($f);
    }

    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    writeLog("ADMIN deleted user #$userId");

    echo json_encode(['status' => 'success', 'message' => 'کاربر حذف شد']);
}

function handleAdminAISettings() {
    global $pdo;
    requireAdmin();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $config = getAIConfig($pdo);
        $key = $config['api_key'];
        $config['api_key_masked'] = $key ? substr($key, 0, 6) . '...' . substr($key, -4) : '';
        echo json_encode(['status' => 'success', 'settings' => $config]);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    $fields = ['ai_api_base', 'ai_api_key', 'ai_model', 'ai_max_tokens', 'ai_temperature'];
    foreach ($fields as $f) {
        if (isset($input[$f])) {
            $value = trim($input[$f]);
            if ($f === 'ai_api_key' && $value === '') continue;
            setAppSetting($pdo, $f, $value);
        }
    }

    writeLog('ADMIN updated AI settings');
    echo json_encode(['status' => 'success', 'message' => 'تنظیمات AI ذخیره شد']);
}

function handleAdminBackupDB() {
    requireAdmin();

    $backupDir = __DIR__ . '/backups/';
    if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

    $backupName = 'db_backup_' . date('Ymd_His') . '.sqlite';
    $backupPath = $backupDir . $backupName;

    $pdo = getDB();
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');

    if (copy(DB_PATH, $backupPath)) {
        writeLog('ADMIN created DB backup: ' . $backupName);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') { header('Content-Type: application/octet-stream'); header('Content-Disposition: attachment; filename="'.$backupName.'"'); header('Content-Length: '.filesize($backupPath)); readfile($backupPath); exit; }
        echo json_encode([
            'status' => 'success',
            'message' => 'پشتیبان‌گیری انجام شد',
            'file' => $backupName,
            'size' => filesize($backupPath)
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'پشتیبان‌گیری ناموفق']);
    }
}

function handleAdminRestoreDB() {
    requireAdmin();
    if (empty($_FILES['backup']['tmp_name']) || strtolower(pathinfo($_FILES['backup']['name'], PATHINFO_EXTENSION)) !== 'sqlite') { jsonResponse(['status'=>'error','message'=>'فایل SQLite معتبر انتخاب کنید.'],400); return; }
    $backupDir=__DIR__.'/backups/'; if(!is_dir($backupDir)) mkdir($backupDir,0755,true);
    copy(DB_PATH,$backupDir.'before_restore_'.date('Ymd_His').'.sqlite');
    if (!copy($_FILES['backup']['tmp_name'], DB_PATH)) { jsonResponse(['status'=>'error','message'=>'بازگردانی ناموفق بود.'],500); return; }
    writeLog('ADMIN restored database from uploaded backup'); jsonResponse(['status'=>'success','message'=>'پایگاه داده با موفقیت بازگردانی شد.']);
}

// بازنشانی رمز عبور کاربر توسط ادمین
function handleAdminResetPassword() {
    global $pdo;
    requireAdmin();

    $input = json_decode(file_get_contents('php://input'), true);
    $userId = (int)($input['userId'] ?? 0);
    $newPassword = $input['newPassword'] ?? '';

    if ($userId <= 0 || strlen($newPassword) < 4) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'شناسه کاربر یا رمز جدید نامعتبر است (حداقل ۴ کاراکتر)']);
        return;
    }

    // بررسی وجود کاربر
    $stmt = $pdo->prepare('SELECT id, username FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'کاربر یافت نشد']);
        return;
    }

    // به‌روزرسانی رمز عبور
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);

    writeLog('ADMIN reset password for user: ' . $user['username'] . ' (id=' . $userId . ')');

    echo json_encode([
        'status' => 'success',
        'message' => 'رمز عبور کاربر «' . $user['username'] . '» با موفقیت بازنشانی شد',
    ]);
}

function handleAdminBroadcast() {
    global $pdo;
    $adminId = requireAdmin();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $title = trim((string)($input['title'] ?? 'اعلان مدیریت'));
    $message = trim((string)($input['message'] ?? ''));
    if ($message === '' || mb_strlen($message) > 2000 || mb_strlen($title) > 120) {
        http_response_code(400); echo json_encode(['status'=>'error','message'=>'عنوان یا پیام نامعتبر است']); return;
    }
    $pdo->prepare('INSERT INTO broadcast_messages(title,message,created_by) VALUES(?,?,?)')->execute([$title,$message,$adminId]);
    writeLog('ADMIN broadcast message sent by user '.$adminId);
    echo json_encode(['status'=>'success','message'=>'پیام همگانی ارسال شد']);
}

function handleAdminLogs() {
    requireAdmin();
    $path = __DIR__ . '/error.log';
    $lines = is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [];
    echo json_encode(['status'=>'success','lines'=>array_slice($lines,-300)]);
}
?>
