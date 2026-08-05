<?php
// ============================================
// admin.php — پنل مدیریت برنامه مالی
// ============================================
// این فایل فقط برای ادمین‌ها قابل دسترسی است.
// اولین کاربر ثبت‌نام شده به‌طور خودکار ادمین می‌شود.
// ============================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// بررسی ورود به سیستم و دسترسی ادمین
if (!isLoggedIn()) {
    header('Location: index.html');
    exit;
}
if (!isAdmin()) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>دسترسی غیرمجاز</title><link href="assets/Vazirmatn-font-face.css" rel="stylesheet"><style>body{font-family:Vazirmatn,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#0f172a;color:#fff;margin:0}.box{text-align:center;padding:2rem}.box i{font-size:4rem;color:#dc3545}</style></head><body><div class="box"><div>🔒</div><h2>دسترسی غیرمجاز</h2><p>شما دسترسی ادمین ندارید.</p><a href="index.html" style="color:#0d6efd">بازگشت به برنامه</a></div></body></html>';
    exit;
}

$currentUser = getCurrentUser();
$pdo = getDB();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f172a">
    <meta name="robots" content="noindex, nofollow">
    <title>پنل مدیریت — سیستم مالی</title>
    <link href="assets/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" />
    <link rel="stylesheet" href="assets/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="assets/bootstrap-icons.css">
    <style>
        body { font-family:'Vazirmatn',sans-serif; background:#f1f5f9; }
        .admin-header { background:linear-gradient(135deg,#1e293b,#0f172a); color:#fff; padding:1.5rem; border-radius:0 0 1.5rem 1.5rem; box-shadow:0 4px 15px rgba(0,0,0,.15); }
        .stat-card { background:#fff; border-radius:1rem; padding:1.25rem; box-shadow:0 1px 3px rgba(0,0,0,.08); text-align:center; transition:transform .2s; }
        .stat-card:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,.12); }
        .stat-card .stat-icon { width:48px; height:48px; border-radius:.75rem; display:flex; align-items:center; justify-content:center; font-size:1.5rem; margin:0 auto .5rem; }
        .stat-card .stat-val { font-size:1.5rem; font-weight:700; color:#1e293b; }
        .stat-card .stat-lbl { font-size:.8rem; color:#64748b; }
        .section-card { background:#fff; border-radius:1rem; padding:1.5rem; box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:1.5rem; }
        .user-row { transition:background .2s; }
        .user-row:hover { background:#f8fafc; }
        .badge-admin { background:#0d6efd; }
        .badge-active { background:#198754; }
        .badge-inactive { background:#dc3545; }
        .toast-container { position:fixed; top:1rem; left:50%; transform:translateX(-50%); z-index:9999; }
        .toast-admin { padding:.75rem 1.5rem; border-radius:.5rem; color:#fff; font-size:.875rem; font-weight:600; box-shadow:0 4px 15px rgba(0,0,0,.2); margin-bottom:.5rem; animation:slideIn .3s; }
        .toast-admin.success { background:#198754; }
        .toast-admin.error { background:#dc3545; }
        .toast-admin.info { background:#0d6efd; }
        @keyframes slideIn { from{opacity:0;transform:translateY(-20px)} to{opacity:1;transform:translateY(0)} }
        .nav-tab { cursor:pointer; padding:.75rem 1.25rem; border:none; background:none; font-weight:600; color:#64748b; border-bottom:3px solid transparent; transition:all .2s; }
        .nav-tab.active { color:#0d6efd; border-bottom-color:#0d6efd; }
        .nav-tab:hover { color:#0d6efd; }
        .ai-test-result { border-radius:.5rem; padding:1rem; margin-top:1rem; font-size:.875rem; }
        .ai-test-result.success { background:#d1fae5; border:1px solid #10b981; color:#065f46; }
        .ai-test-result.error { background:#fee2e2; border:1px solid #ef4444; color:#991b1b; }
        .spinner-border-sm { width:1rem; height:1rem; border-width:.15em; }
    </style>
</head>
<body>
    <div class="admin-header mb-4">
        <div class="container d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-0"><i class="bi bi-shield-lock me-2"></i>پنل مدیریت</h4>
                <small class="text-white-50">سیستم مدیریت مالی شخصی</small>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="text-white-50 small"><i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($currentUser['display_name'] ?: $currentUser['username']); ?></span>
                <a href="index.html" class="btn btn-sm btn-light rounded-pill"><i class="bi bi-arrow-left"></i> بازگشت به برنامه</a>
            </div>
        </div>
    </div>

    <div class="container pb-5">
        <!-- تب‌ها -->
        <div class="d-flex border-bottom mb-4 overflow-auto">
            <button class="nav-tab active" onclick="switchTab('dashboard')" id="tab-dashboard"><i class="bi bi-speedometer2"></i> داشبورد</button>
            <button class="nav-tab" onclick="switchTab('users')" id="tab-users"><i class="bi bi-people"></i> کاربران</button>
            <button class="nav-tab" onclick="switchTab('ai')" id="tab-ai"><i class="bi bi-robot"></i> تنظیمات AI</button>
            <button class="nav-tab" onclick="switchTab('backup')" id="tab-backup"><i class="bi bi-database-check"></i> پشتیبان‌گیری</button>
        </div>

        <!-- داشبورد -->
        <div id="panel-dashboard">
            <div class="row g-3 mb-4" id="statsGrid">
                <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-people"></i></div><div class="stat-val" id="stat-users">—</div><div class="stat-lbl">کل کاربران</div></div></div>
                <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon bg-success bg-opacity-10 text-success"><i class="bi bi-person-check"></i></div><div class="stat-val" id="stat-active">—</div><div class="stat-lbl">کاربران فعال</div></div></div>
                <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon bg-info bg-opacity-10 text-info"><i class="bi bi-clock-history"></i></div><div class="stat-val" id="stat-recent">—</div><div class="stat-lbl">آنلاین (۳۰ دقیقه)</div></div></div>
                <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-list-check"></i></div><div class="stat-val" id="stat-txs">—</div><div class="stat-lbl">کل تراکنش‌ها</div></div></div>
            </div>
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon bg-danger bg-opacity-10 text-danger"><i class="bi bi-people"></i></div><div class="stat-val" id="stat-debts">—</div><div class="stat-lbl">کل بدهی‌ها</div></div></div>
                <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon bg-success bg-opacity-10 text-success"><i class="bi bi-bullseye"></i></div><div class="stat-val" id="stat-goals">—</div><div class="stat-lbl">کل اهداف</div></div></div>
                <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-database"></i></div><div class="stat-val" id="stat-dbsize">—</div><div class="stat-lbl">حجم دیتابیس</div></div></div>
                <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon bg-info bg-opacity-10 text-info"><i class="bi bi-hard-drive"></i></div><div class="stat-val" id="stat-uploads">—</div><div class="stat-lbl">حجم آپلودها</div></div></div>
            </div>
        </div>

        <!-- کاربران -->
        <div id="panel-users" style="display:none">
            <div class="section-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0"><i class="bi bi-people me-2"></i>لیست کاربران</h5>
                    <button class="btn btn-sm btn-outline-primary rounded-pill" onclick="loadUsers()"><i class="bi bi-arrow-clockwise"></i> به‌روزرسانی</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>نام کاربری</th>
                                <th>نام نمایشی</th>
                                <th>تراکنش‌ها</th>
                                <th>تاریخ ثبت</th>
                                <th>آخرین ورود</th>
                                <th>وضعیت</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody">
                            <tr><td colspan="8" class="text-center text-muted py-4">در حال بارگذاری...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- تنظیمات AI -->
        <div id="panel-ai" style="display:none">
            <div class="section-card">
                <h5 class="fw-bold mb-3"><i class="bi bi-robot me-2 text-success"></i>تنظیمات هوش مصنوعی</h5>
                <p class="text-muted small mb-4">این تنظیمات با هر API سازگار با استاندارد OpenAI کار می‌کند (OpenAI، Groq، OpenRouter، و غیره).</p>

                <div class="mb-3">
                    <label class="form-label fw-bold small">آدرس API Base</label>
                    <input type="text" class="form-control" id="aiApiBase" placeholder="https://api.openai.com/v1" dir="ltr">
                    <small class="text-muted">مثال: https://api.openai.com/v1 یا https://api.groq.com/openai/v1</small>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold small">کلید API</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="aiApiKey" placeholder="sk-..." dir="ltr">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('aiApiKey')"><i class="bi bi-eye" id="aiApiKeyIcon"></i></button>
                    </div>
                    <small class="text-muted">کلید فعلی: <span id="aiKeyMasked" class="fw-bold">—</span></small>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold small">نام مدل</label>
                        <input type="text" class="form-control" id="aiModel" placeholder="gpt-4o-mini" dir="ltr">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold small">Max Tokens</label>
                        <input type="number" class="form-control" id="aiMaxTokens" value="1000" dir="ltr">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold small">Temperature</label>
                        <input type="number" class="form-control" id="aiTemperature" value="0.7" step="0.1" min="0" max="2" dir="ltr">
                    </div>
                </div>

                <div class="d-flex gap-2 mt-4">
                    <button class="btn btn-success px-4" onclick="saveAISettings()"><i class="bi bi-check-lg"></i> ذخیره تنظیمات</button>
                    <button class="btn btn-outline-primary px-4" onclick="testAIConnection()" id="testAIBtn"><i class="bi bi-plug"></i> تست اتصال</button>
                </div>

                <div id="aiTestResult"></div>
            </div>
        </div>

        <!-- پشتیبان‌گیری -->
        <div id="panel-broadcast" class="section-card">
            <h5 class="fw-bold mb-3"><i class="bi bi-megaphone me-2 text-warning"></i>پیام همگانی</h5>
            <div class="mb-2"><input id="broadcastTitle" class="form-control" placeholder="عنوان پیام"></div>
            <div class="mb-2"><textarea id="broadcastMessage" class="form-control" rows="4" placeholder="متن پیام برای همه کاربران"></textarea></div>
            <button class="btn btn-warning" onclick="sendBroadcast()">ارسال به همه کاربران</button>
        </div>
        <div id="panel-logs" class="section-card">
            <div class="d-flex justify-content-between align-items-center mb-3"><h5 class="fw-bold mb-0"><i class="bi bi-file-text me-2"></i>لاگ سیستم</h5><button class="btn btn-sm btn-outline-primary" onclick="loadLogs()">به‌روزرسانی</button></div>
            <pre id="adminLogView" class="bg-dark text-light p-3 rounded-3" style="max-height:360px;overflow:auto;direction:ltr;text-align:left">برای مشاهده، به‌روزرسانی را بزنید.</pre>
        </div>

        <!-- پشتیبان‌گیری -->
        <div id="panel-backup" style="display:none">
            <div class="section-card">
                <h5 class="fw-bold mb-3"><i class="bi bi-database-check me-2 text-primary"></i>پشتیبان‌گیری و بازیابی</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="border rounded-3 p-3 text-center h-100">
                            <i class="bi bi-download fs-1 text-primary d-block mb-2"></i>
                            <h6 class="fw-bold">پشتیبان‌گیری از دیتابیس</h6>
                            <p class="small text-muted">یک کپی از دیتابیس SQLite در پوشه backups ذخیره می‌شود.</p>
                            <button class="btn btn-primary w-100" onclick="backupDB()"><i class="bi bi-database-down"></i> ایجاد پشتیبان</button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded-3 p-3 text-center h-100">
                            <i class="bi bi-cloud-download fs-1 text-success d-block mb-2"></i>
                            <h6 class="fw-bold">دانلود دیتابیس</h6>
                            <p class="small text-muted">فایل دیتابیس فعلی را دانلود کنید.</p>
                            <a href="api.php?action=admin_backup_db" class="btn btn-success w-100"><i class="bi bi-file-earmark-binary"></i> دانلود</a>
                            <hr><label class="small text-muted">بازگردانی پشتیبان SQLite</label><input type="file" id="restoreDbFile" accept=".sqlite" class="form-control form-control-sm mb-2"><button class="btn btn-outline-danger w-100" onclick="restoreDB()"><i class="bi bi-arrow-counterclockwise"></i> بازگردانی</button>
                        </div>
                    </div>
                </div>
                <hr class="my-4">
                <div class="alert alert-info small">
                    <i class="bi bi-info-circle"></i> <strong>تعداد پشتیبان‌های موجود:</strong> <span id="backupCount">—</span>
                </div>
            </div>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <script>
        // ============================================
        // توابع کمکی
        // ============================================
        function showToast(msg, type='info') {
            const c = document.getElementById('toastContainer');
            const el = document.createElement('div');
            el.className = 'toast-admin ' + type;
            el.textContent = msg;
            c.appendChild(el);
            setTimeout(() => { el.style.opacity='0'; el.style.transition='opacity .3s'; setTimeout(()=>el.remove(),300); }, 3000);
        }

        async function sendBroadcast(){const title=document.getElementById('broadcastTitle').value.trim();const message=document.getElementById('broadcastMessage').value.trim();if(!message){showToast('متن پیام را وارد کنید','error');return;}if(!confirm('پیام برای همه کاربران ارسال شود؟'))return;const r=await fetch('api.php?action=admin_broadcast',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({title,message})});const d=await r.json();showToast(d.message||'خطا',d.status==='success'?'success':'error');if(d.status==='success'){document.getElementById('broadcastTitle').value='';document.getElementById('broadcastMessage').value='';}}
        async function loadLogs(){const el=document.getElementById('adminLogView');el.textContent='در حال بارگذاری...';try{const r=await fetch('api.php?action=admin_logs',{credentials:'same-origin'});const d=await r.json();el.textContent=(d.lines||[]).join('\n')||'لاگی ثبت نشده است.';el.scrollTop=el.scrollHeight;}catch(e){el.textContent='خطا در دریافت لاگ';}}

        function formatBytes(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024, sizes = ['B','KB','MB','GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function toPersianNum(s) {
            return s.toString().replace(/\d/g, x => '۰۱۲۳۴۵۶۷۸۹'[x]);
        }

        // ============================================
        // تب‌ها
        // ============================================
        function switchTab(tab) {
            ['dashboard','users','ai','backup'].forEach(t => {
                document.getElementById('panel-' + t).style.display = t === tab ? 'block' : 'none';
                document.getElementById('tab-' + t).classList.toggle('active', t === tab);
            });
            if (tab === 'dashboard') loadStats();
            if (tab === 'users') loadUsers();
            if (tab === 'ai') loadAISettings();
            if (tab === 'backup') loadBackupInfo();
        }

        // ============================================
        // داشبورد — آمار
        // ============================================
        async function loadStats() {
            try {
                const res = await fetch('api.php?action=admin_stats', {credentials:'same-origin'});
                const d = await res.json();
                if (d.status === 'success') {
                    const s = d.stats;
                    document.getElementById('stat-users').textContent = toPersianNum(s.users);
                    document.getElementById('stat-active').textContent = toPersianNum(s.activeUsers);
                    document.getElementById('stat-recent').textContent = toPersianNum(s.recentActive);
                    document.getElementById('stat-txs').textContent = toPersianNum(s.transactions);
                    document.getElementById('stat-debts').textContent = toPersianNum(s.debts);
                    document.getElementById('stat-goals').textContent = toPersianNum(s.goals);
                    document.getElementById('stat-dbsize').textContent = formatBytes(s.dbSize);
                    document.getElementById('stat-uploads').textContent = formatBytes(s.uploadSize);
                }
            } catch(e) { showToast('خطا در بارگذاری آمار','error'); }
        }

        // ============================================
        // کاربران
        // ============================================
        async function loadUsers() {
            const tbody = document.getElementById('usersTableBody');
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">در حال بارگذاری...</td></tr>';
            try {
                const res = await fetch('api.php?action=admin_users', {credentials:'same-origin'});
                const d = await res.json();
                if (d.status === 'success' && d.users) {
                    if (d.users.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">هیچ کاربری ثبت نشده.</td></tr>';
                        return;
                    }
                    tbody.innerHTML = d.users.map(u => {
                        const statusBadge = u.is_active == 1
                            ? '<span class="badge badge-active">فعال</span>'
                            : '<span class="badge badge-inactive">غیرفعال</span>';
                        const adminBadge = u.is_admin == 1 ? ' <span class="badge badge-admin">ادمین</span>' : '';
                        const toggleActiveBtn = u.is_active == 1
                            ? '<button class="btn btn-sm btn-outline-warning py-1 px-2" onclick="toggleUser('+u.id+',\'is_active\',0)" title="غیرفعال کردن"><i class="bi bi-pause-circle"></i></button>'
                            : '<button class="btn btn-sm btn-outline-success py-1 px-2" onclick="toggleUser('+u.id+',\'is_active\',1)" title="فعال کردن"><i class="bi bi-play-circle"></i></button>';
                        const toggleAdminBtn = u.is_admin == 1
                            ? '<button class="btn btn-sm btn-outline-secondary py-1 px-2" onclick="toggleUser('+u.id+',\'is_admin\',0)" title="گرفتن دسترسی ادمین"><i class="bi bi-shield-x"></i></button>'
                            : '<button class="btn btn-sm btn-outline-primary py-1 px-2" onclick="toggleUser('+u.id+',\'is_admin\',1)" title="تبدیل به ادمین"><i class="bi bi-shield-check"></i></button>';
                        const resetPwdBtn = '<button class="btn btn-sm btn-outline-warning py-1 px-2" onclick="resetPassword('+u.id+',\''+escapeHtml(u.username)+'\')" title="بازنشانی رمز"><i class="bi bi-key"></i></button>';
                        const deleteBtn = '<button class="btn btn-sm btn-outline-danger py-1 px-2" onclick="deleteUser('+u.id+',\''+escapeHtml(u.username)+'\')" title="حذف کاربر"><i class="bi bi-trash"></i></button>';
                        return '<tr class="user-row"><td>'+toPersianNum(u.id)+'</td><td class="fw-bold">'+escapeHtml(u.username)+'</td><td>'+escapeHtml(u.display_name||'—')+'</td><td>'+toPersianNum(u.tx_count)+'</td><td class="small text-muted">'+toPersianNum(u.created_at||'')+'</td><td class="small text-muted">'+toPersianNum(u.last_login||'—')+'</td><td>'+statusBadge+adminBadge+'</td><td><div class="d-flex gap-1">'+toggleActiveBtn+toggleAdminBtn+resetPwdBtn+deleteBtn+'</div></td></tr>';
                    }).join('');
                }
            } catch(e) { tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-4">خطا در بارگذاری</td></tr>'; }
        }

        function escapeHtml(s) { const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

        async function toggleUser(userId, field, value) {
            const action = field === 'is_active' ? (value ? 'فعال‌سازی' : 'غیرفعال‌سازی') : (value ? 'تبدیل به ادمین' : 'گرفتن دسترسی ادمین');
            if (!confirm('آیا از '+action+' این کاربر مطمئن هستید؟')) return;
            try {
                const res = await fetch('api.php?action=admin_toggle_user', {
                    method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin',
                    body:JSON.stringify({userId, field, value})
                });
                const d = await res.json();
                if (d.status === 'success') { showToast(action+' انجام شد','success'); loadUsers(); loadStats(); }
                else { showToast(d.message||'خطا','error'); }
            } catch(e) { showToast('خطا در ارتباط','error'); }
        }

        async function deleteUser(userId, username) {
            if (!confirm('آیا از حذف کاربر «'+username+'» مطمئن هستید؟ تمام داده‌های او حذف خواهد شد!')) return;
            try {
                const res = await fetch('api.php?action=admin_delete_user', {
                    method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin',
                    body:JSON.stringify({userId})
                });
                const d = await res.json();
                if (d.status === 'success') { showToast('کاربر حذف شد','success'); loadUsers(); loadStats(); }
                else { showToast(d.message||'خطا','error'); }
            } catch(e) { showToast('خطا در ارتباط','error'); }
        }

        // ============================================
        // بازنشانی رمز عبور
        // ============================================
        async function resetPassword(userId, username) {
            const newPwd = prompt('رمز عبور جدید برای کاربر «' + username + '» را وارد کنید (حداقل ۴ کاراکتر):');
            if (newPwd === null) return;
            if (newPwd.trim().length < 4) {
                showToast('رمز جدید حداقل باید ۴ کاراکتر باشد','error');
                return;
            }
            try {
                const res = await fetch('api.php?action=admin_reset_password', {
                    method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin',
                    body:JSON.stringify({userId: userId, newPassword: newPwd.trim()})
                });
                const d = await res.json();
                if (d.status === 'success') {
                    showToast(d.message || 'رمز عبور بازنشانی شد','success');
                } else {
                    showToast(d.message || 'خطا','error');
                }
            } catch(e) { showToast('خطا در ارتباط','error'); }
        }

        // ============================================
        // تنظیمات AI
        // ============================================
        async function loadAISettings() {
            try {
                const res = await fetch('api.php?action=admin_ai_settings', {credentials:'same-origin'});
                const d = await res.json();
                if (d.status === 'success' && d.settings) {
                    const s = d.settings;
                    document.getElementById('aiApiBase').value = s.api_base || '';
                    document.getElementById('aiApiKey').value = '';
                    document.getElementById('aiKeyMasked').textContent = s.api_key_masked || 'تنظیم نشده';
                    document.getElementById('aiModel').value = s.model || '';
                    document.getElementById('aiMaxTokens').value = s.max_tokens || 1000;
                    document.getElementById('aiTemperature').value = s.temperature || 0.7;
                }
            } catch(e) { showToast('خطا در بارگذاری تنظیمات','error'); }
        }

        async function saveAISettings() {
            const data = {
                ai_api_base: document.getElementById('aiApiBase').value.trim(),
                ai_model: document.getElementById('aiModel').value.trim(),
                ai_max_tokens: document.getElementById('aiMaxTokens').value,
                ai_temperature: document.getElementById('aiTemperature').value,
            };
            const keyVal = document.getElementById('aiApiKey').value.trim();
            if (keyVal) data.ai_api_key = keyVal;

            try {
                const res = await fetch('api.php?action=admin_ai_settings', {
                    method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin',
                    body:JSON.stringify(data)
                });
                const d = await res.json();
                if (d.status === 'success') { showToast('تنظیمات ذخیره شد','success'); loadAISettings(); }
                else { showToast(d.message||'خطا','error'); }
            } catch(e) { showToast('خطا در ارتباط','error'); }
        }

        async function testAIConnection() {
            const btn = document.getElementById('testAIBtn');
            const result = document.getElementById('aiTestResult');
            btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> در حال تست...';
            result.innerHTML = '';
            try {
                const res = await fetch('api.php?action=ai_test', {method:'POST', credentials:'same-origin'});
                const d = await res.json();
                if (d.status === 'success') {
                    const det = d.details;
                    result.innerHTML = '<div class="ai-test-result success"><i class="bi bi-check-circle-fill"></i> <strong>'+d.message+'</strong><br><small>مدل: '+escapeHtml(det.model)+' | زمان پاسخ: '+det.response_time+'</small><br><small>پاسخ: '+escapeHtml(det.reply)+'</small></div>';
                    showToast('اتصال AI موفق بود!','success');
                } else {
                    result.innerHTML = '<div class="ai-test-result error"><i class="bi bi-x-circle-fill"></i> <strong>خطا:</strong> '+escapeHtml(d.message)+(d.http_code?' (HTTP '+toPersianNum(d.http_code)+')':'')+'</div>';
                    showToast('تست اتصال ناموفق','error');
                }
            } catch(e) {
                result.innerHTML = '<div class="ai-test-result error"><i class="bi bi-x-circle-fill"></i> خطا در ارتباط با سرور</div>';
                showToast('خطا در ارتباط','error');
            }
            btn.disabled = false; btn.innerHTML = '<i class="bi bi-plug"></i> تست اتصال';
        }

        function togglePasswordVisibility(id) {
            const inp = document.getElementById(id);
            const icon = document.getElementById(id+'Icon');
            if (inp.type === 'password') { inp.type = 'text'; icon.className = 'bi bi-eye-slash'; }
            else { inp.type = 'password'; icon.className = 'bi bi-eye'; }
        }

        // ============================================
        // پشتیبان‌گیری
        // ============================================
        async function loadBackupInfo() {
            try {
                const res = await fetch('api.php?action=admin_stats', {credentials:'same-origin'});
                const d = await res.json();
                if (d.status === 'success') {
                    document.getElementById('backupCount').textContent = toPersianNum(d.stats.backupCount) + ' فایل';
                }
            } catch(e) {}
        }

        async function restoreDB(){const f=document.getElementById('restoreDbFile').files[0];if(!f)return alert('فایل پشتیبان را انتخاب کنید.');if(!confirm('بازگردانی، داده‌های فعلی را جایگزین می‌کند. ادامه می‌دهید؟'))return;const fd=new FormData();fd.append('backup',f);const r=await fetch('api.php?action=admin_restore_db',{method:'POST',body:fd,credentials:'same-origin'});const d=await r.json();alert(d.message||'انجام شد');}
        async function backupDB() {
            if (!confirm('پشتیبان جدید از دیتابیس ایجاد شود؟')) return;
            try {
                const res = await fetch('api.php?action=admin_backup_db', {method:'POST', credentials:'same-origin'});
                const d = await res.json();
                if (d.status === 'success') {
                    showToast('پشتیبان‌گیری انجام شد ('+formatBytes(d.size)+')','success');
                    loadBackupInfo();
                } else { showToast(d.message||'خطا','error'); }
            } catch(e) { showToast('خطا در ارتباط','error'); }
        }

        // بارگذاری اولیه
        loadStats();
    </script>
</body>
</html>
