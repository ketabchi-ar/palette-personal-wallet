# Palette Personal Wallet

[![DevSponsors](https://devsponsors.github.io/assets/badges/sponsor.svg)](https://devsponsors.github.io)
[![DevSponsors](https://img.shields.io/badge/DevSponsors-Verified_OSS-6366f1?style=for-the-badge&logo=github)](https://devsponsors.github.io)
[![Sponsor](https://img.shields.io/badge/Sponsor-DevSponsors_Hub-emerald?style=for-the-badge&logo=github-sponsors)](https://devsponsors.github.io)
[![Cloud](https://img.shields.io/badge/Infrastructure-DevSponsors_Cloud-ec4899?style=for-the-badge&logo=server)](https://devsponsors.github.io/mediakit.html)
یک وب‌اپلیکیشن PWA سبک، سریع و کاملاً فارسی/راست‌چین برای مدیریت دخل‌وخرج شخصی (نسخه اصلی فعال روی سرور).

## قابلیت‌های کلیدی

- **طراحی بهینه موبایل و PWA**: قابلیت نصب روی گوشی (Add to Home Screen) و کش آفلاین با Service Worker.
- **ثبت سریع دخل و خرج**: انتخاب دسته‌بندی، کیف‌پول‌های مختلف، ثبت تگ افراد با `@` در توضیحات.
- **تقویم و ارقام کاملاً شمسی**: فیلتر بر اساس ماه‌های سال شمسی و تبدیل ارقام به فارسی.
- **پیوست تصویر فاکتور**: فشرده‌سازی خودکار تصویر در سمت کلاینت و آپلود امن در هاست.
- **تولید رسید تصویری**: ساخت خروجی تصویری تمیز از هر تراکنش با `html2canvas` جهت اشتراک‌گذاری.
- **محاسبه دُنگ و دفترچه بدهی/طلبکاری**: محاسبه سریع دُنگ دورهمی‌ها و انتقال مستقیم به دفترچه بدهی.
- **تراکنش‌های تکرارشونده و اقساط**: یادآوری پرداخت‌های دوره‌ای و اعمال سریع به ماه جاری.
- **حالت شب / روز (Dark Mode)**: سازگار با سلیقه کاربر و ذخیره تم در LocalStorage.
- **همگام‌سازی ابری و آفلاین**: ذخیره دوطرفه روی سرور (`database.json`) و مرورگر (`localStorage`).

## ساختار فایل‌ها

```text
├── index.html              # رابط کاربری اصلی PWA تک‌صفحه‌ای
├── api.php                 # اندپوینت ذخیره دیتابیس و مدیریت آپلود/حذف فاکتورها
├── database.sample.json    # ساختار خام پایگاه داده JSON
├── manifest.json           # مانیفست PWA
├── sw.js                   # Service Worker برای عملکرد PWA
├── icon.png                # آیکون اپلیکیشن
├── assets/                 # فونت وزیرمتن، بوت‌استرپ و آیکون‌ها
└── uploads/                # محل ذخیره تصاویر فاکتور (نادیده گرفته شده در گیت)
```

## راه‌اندازی

1. فایل‌ها را در یک پوشه یا زیردامنه روی هاست دارای پشتیبانی از PHP آپلود کنید.
2. از فایل `database.sample.json` یک کپی با نام `database.json` بسازید و دسترسی نوشتن (chmod 664 یا 666) به آن و پوشه `uploads/` بدهید.
3. برنامه آماده استفاده است.

> **نکته:** شاخه قدیمی و تجربی مبتنی بر SQLite و پنل چندکاربره در شاخه `v7-sqlite-multiuser` در گیت نگهداری می‌شود.
