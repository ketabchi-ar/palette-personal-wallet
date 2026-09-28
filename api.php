<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// ۱. بخش آپلود تصویر فشرده شده
if (isset($_FILES['receipt'])) {
    $uploadDir = 'uploads/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $fileName = time() . '_' . rand(1000,9999) . '.' . pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION);
    $targetPath = $uploadDir . $fileName;
    
    if (move_uploaded_file($_FILES['receipt']['tmp_name'], $targetPath)) {
        echo json_encode(["status" => "success", "url" => $targetPath]);
    } else {
        echo json_encode(["status" => "error", "message" => "Upload failed"]);
    }
    exit;
}

// ۲. بخش حذف فایل از سرور
if (isset($_GET['deleteFile'])) {
    $file = basename($_GET['deleteFile']); // جلوگیری از دسترسی به پوشه‌های بالاتر
    $path = 'uploads/' . $file;
    if (file_exists($path) && !is_dir($path)) {
        unlink($path);
        echo json_encode(["status" => "success", "message" => "File deleted"]);
    } else {
        echo json_encode(["status" => "error", "message" => "File not found"]);
    }
    exit;
}

// ۳. بخش همگام‌سازی دیتابیس جامع
$data = file_get_contents('php://input');
if ($data) {
    $result = file_put_contents('database.json', $data);
    if ($result !== false) {
        echo json_encode(["status" => "success", "message" => "Data saved successfully"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to write database file."]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "No data received"]);
}
?>