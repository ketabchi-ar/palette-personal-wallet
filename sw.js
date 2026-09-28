const CACHE_NAME = 'mali-app-v6';

self.addEventListener('install', (e) => {
    self.skipWaiting();
    e.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll([
                './',
                './index.html',
                './manifest.json',
                './icon.png'
            ]);
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
});

self.addEventListener('fetch', (e) => {
    // عدم کش کردن فایل‌های api و دیتابیس برای دریافت همیشه دیتای جدید
    if (e.request.method !== 'GET' || e.request.url.includes('api.php') || e.request.url.includes('database.json')) return;
    
    e.respondWith(
        fetch(e.request).catch(() => caches.match(e.request))
    );
});
