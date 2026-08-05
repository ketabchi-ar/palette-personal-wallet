<?php
// این فایل را با نام config.php کپی کنید و مقادیر محیط سرور را وارد کنید.
if (defined('MALI_CONFIG')) return;
define('MALI_CONFIG', true);

define('DB_PATH', __DIR__ . '/data/mali.db');
define('SESSION_NAME', 'MALI_SESSION');
define('SESSION_LIFETIME', 60 * 60 * 24 * 30);

// سرویس سازگار با OpenAI (کلید واقعی را هرگز در GitHub قرار ندهید)
define('AI_API_BASE', 'https://api.openai.com/v1');
define('AI_API_KEY', 'YOUR_API_KEY_HERE');
define('AI_MODEL', 'gpt-4o-mini');
define('AI_MAX_TOKENS', 1000);
define('AI_TEMPERATURE', 0.7);

define('PASSWORD_HASH_COST', 10);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);

function writeLog($msg) {
    $logPath = __DIR__ . '/error.log';
    @file_put_contents($logPath, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}
