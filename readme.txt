=== Didar CRM Complete User Sync ===
Contributors: m4tinbeigi-official
Tags: crm, sync, didar, woocommerce, users
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

پلاگین کامل برای سینک دوطرفه کاربران وردپرس و ووکامرس با CRM دیدار. شامل تنظیمات پیشرفته، cron job، و رابط کاربری حرفه‌ای.

== Description ==

این پلاگین کاربران وردپرس (و مشتریان ووکامرس) را با مخاطبین CRM دیدار سینک می‌کند. ویژگی‌ها:
- سینک دوطرفه با گزینه‌های قابل تنظیم.
- پشتیبانی از ووکامرس (فیلدهای billing).
- Cron job برای سینک خودکار.
- Mapping فیلدها سفارشی.
- عملیات دستی با AJAX.
- لاگ پیشرفته و UI زیبا (تب‌ها، کارت‌ها).

برای جزئیات بیشتر، به [وبسایت پلاگین](https://example.com/didar-sync) مراجعه کنید.

== Installation ==

1. فایل `didar-complete-sync.php` را در پوشه `/wp-content/plugins/didar-complete-sync/` آپلود کنید.
2. پلاگین را از پنل وردپرس فعال کنید.
3. به **تنظیمات > دیدار CRM** بروید و API Key دیدار را وارد کنید.
4. تنظیمات را ذخیره و تست کنید.

== Frequently Asked Questions ==

= API Key از کجا بگیرم؟ =
از پنل دیدار: تنظیمات > اتصال به سرورهای دیگر > API Key.

= سینک دوطرفه چطور کار می‌کند؟ =
- از WP به دیدار: خودکار هنگام ثبت/ویرایش کاربر.
- از دیدار به WP: با cron job (روزانه پیش‌فرض).

= ووکامرس لازم است؟ =
خیر، اما اگر نصب باشد، مشتریان را سینک می‌کند.

== Screenshots ==

1. صفحه تنظیمات با تب‌ها.
2. تب سینک با دکمه‌های AJAX.
3. صفحه لاگ‌ها.

== Changelog ==

= 2.2 =
* اضافه کردن UI حرفه‌ای با تب‌ها و AJAX.

= 2.1 =
* بهینه‌سازی امنیت و pagination.

= 2.0 =
* ویژگی‌های پایه دوطرفه.

== Upgrade Notice ==

= 2.2 =
به‌روزرسانی برای UI بهتر؛ تنظیمات قبلی حفظ می‌شود.

== Other Notes ==

داکیومنت API: [Postman Docs](https://documenter.getpostman.com/view/2819885/UV5RnLgn)