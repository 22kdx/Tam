# Tam Treasury

وحدة خزينة متعددة العملات لـ Invoice Ninja.

## الحالة

قيد التطوير — غير جاهزة للاستخدام المالي الإنتاجي.

- العملة الأساسية: YER
- العملة الثانوية: SAR
- السعر الابتدائي: 1 SAR = 140 YER
- تكامل n8n وTelegram متوقف.
- الحذف والإلغاء والعكس متوقف.

## المسارات الحالية

- `GET /api/treasury/v1/health`
- `GET|POST /api/treasury/v1/currencies`
- `GET|POST /api/treasury/v1/accounts`

المصادقة تستخدم `X-Api-Token` الخاص بـ Invoice Ninja.

## أمان

لا ترفع ملفات `.env` أو كلمات المرور أو مفاتيح API إلى المستودع.
