// نکته ۸ — بروزرسانی نسخه کش
const CACHE_NAME = 'mali-app-v12';

const STATIC_ASSETS = [
    './',
    './index.html',
    './manifest.json',
    './icon.png',
    './assets/icons/icon-192.png',
    './assets/icons/icon-512.png',
    './assets/Vazirmatn-font-face.css',
    './assets/bootstrap.rtl.min.css',
    './assets/bootstrap-icons.css',
    './assets/html2canvas.min.js'
];

self.addEventListener('install', (e) => {
    self.skipWaiting();
    e.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(STATIC_ASSETS);
        })
    );
});

self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys().then((keyList) => {
            return Promise.all(keyList.map((key) => {
                if (key !== CACHE_NAME) return caches.delete(key);
            }));
        })
    );
    // نکته — کنترل سریع‌تر کلاینت‌ها پس از فعال‌سازی نسخه جدید
    self.clients.claim();
});

// نکته ۷ — cache-first برای فایل‌های استاتیک، network-only برای API
self.addEventListener('fetch', (e) => {
    const url = new URL(e.request.url);

    // نادیده گرفتن درخواست‌های غیر HTTP/HTTPS (مثل chrome-extension://)
    if (url.protocol !== 'http:' && url.protocol !== 'https:') return;

    // عدم کش کردن API، دیتابیس و فایل‌های PHP
    if (url.pathname.includes('api.php') || url.pathname.includes('database.json') || url.pathname.endsWith('.php')) return;

    // بررسی فایل استاتیک
    const isStatic = e.request.method === 'GET' && (
        url.pathname.match(/\.(html|css|js|png|jpg|jpeg|webp|svg|woff2|woff|ttf|json)$/) ||
        url.pathname === './' ||
        url.pathname === '/'
    );

    if (isStatic) {
        // نکته ۷ — cache-first برای فایل‌های استاتیک
        e.respondWith(
            caches.match(e.request).then((cached) => {
                if (cached) return cached;
                return fetch(e.request).then((response) => {
                    if (response.ok && response.type === 'basic') {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(e.request, clone));
                    }
                    return response;
                });
            })
        );
    } else {
        // برای سایر درخواست‌ها: network-first
        e.respondWith(
            fetch(e.request).catch(() => caches.match(e.request))
        );
    }
});
