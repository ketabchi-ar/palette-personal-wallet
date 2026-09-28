<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$uploadDir = __DIR__ . '/uploads/';
$allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

// ۱. بخش آپلود تصویر فاکتور با اعتبارسنجی کامل امنیتی
if (isset($_FILES['receipt'])) {
    if ($_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Upload failed with error code: " . $_FILES['receipt']['error']]);
        exit;
    }

    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $origExt = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
    if (!in_array($origExt, $allowedExtensions, true)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "فرمت فایل مجاز نیست"]);
        exit;
    }

    // بررسی واقعی تصویر بودن فایل
    $imageInfo = @getimagesize($_FILES['receipt']['tmp_name']);
    if ($imageInfo === false) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "فایل ارسال شده تصویر معتبر نیست"]);
        exit;
    }

    $fileName = time() . '_' . bin2hex(random_bytes(6)) . '.' . $origExt;
    $targetPath = $uploadDir . $fileName;
    $publicUrl = 'uploads/' . $fileName;

    if (move_uploaded_file($_FILES['receipt']['tmp_name'], $targetPath)) {
        echo json_encode(["status" => "success", "url" => $publicUrl]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "ذخیره فایل روی سرور با خطا مواجه شد"]);
    }
    exit;
}

// ۲. بخش حذف فایل پیوست با بررسی مجاز بودن
if (isset($_GET['deleteFile'])) {
    $file = basename($_GET['deleteFile']);
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExtensions, true)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "نوع فایل نامعتبر است"]);
        exit;
    }

    $path = $uploadDir . $file;
    if (file_exists($path) && is_file($path)) {
        unlink($path);
        echo json_encode(["status" => "success", "message" => "فایل حذف شد"]);
    } else {
        echo json_encode(["status" => "error", "message" => "فایل یافت نشد"]);
    }
    exit;
}

// ۳. دریافت دیتابیس (اختیاری جهت پشتیبانی از متد امن)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'load') {
    $dbFile = __DIR__ . '/database.json';
    if (file_exists($dbFile)) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        readfile($dbFile);
    } else {
        echo json_encode([
            "transactions" => [],
            "customCategories" => ["expense" => [], "income" => []],
            "wallets" => [["id" => "w1", "name" => "کیف پول اصلی"]],
            "debts" => [],
            "recurringTxs" => []
        ]);
    }
    exit;
}

// ۴. بخش همگام‌سازی دیتابیس جامع با بکاپ خودکار و ذخیره اتمیک
$data = file_get_contents('php://input');
if ($data) {
    $decoded = json_decode($data, true);
    if (!is_array($decoded) || !isset($decoded['transactions'])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "فرمت داده‌ها نامعتبر است"]);
        exit;
    }

    $dbFile = __DIR__ . '/database.json';
    $bakFile = __DIR__ . '/database.bak.json';
    $tmpFile = __DIR__ . '/database.tmp.json';

    // بکاپ چرخشی از نسخه قبلی
    if (file_exists($dbFile) && filesize($dbFile) > 0) {
        @copy($dbFile, $bakFile);
    }

    // نوشتن اتمیک
    $written = file_put_contents($tmpFile, $data, LOCK_EX);
    if ($written !== false && rename($tmpFile, $dbFile)) {
        echo json_encode(["status" => "success", "message" => "اطلاعات با موفقیت ذخیره شد"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "خطا در نوشتن فایل پایگاه داده"]);
    }
} else {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "داده‌ای ارسال نشده است"]);
}
?>