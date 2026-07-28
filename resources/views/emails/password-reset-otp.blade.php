<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>کد بازیابی رمز عبور</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f7;font-family:Tahoma,Arial,sans-serif;direction:rtl;text-align:right;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f7;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#ffffff;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td style="background:#4f46e5;padding:20px 24px;text-align:center;">
                            <span style="color:#ffffff;font-size:20px;font-weight:bold;letter-spacing:1px;">ZED PROXY</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 24px 8px;color:#111827;">
                            <p style="margin:0 0 12px;font-size:15px;">سلام،</p>
                            <p style="margin:0 0 20px;font-size:14px;line-height:1.9;color:#374151;">
                                برای بازیابی رمز عبور حساب ZED PROXY خود کد زیر را وارد کنید:
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 24px;" align="center">
                            <div style="background:#eef2ff;border:1px dashed #6366f1;border-radius:10px;padding:16px 12px;text-align:center;direction:ltr;">
                                <span style="font-size:32px;font-weight:bold;letter-spacing:10px;color:#312e81;font-family:'Courier New',monospace;">{{ $code }}</span>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px 8px;color:#374151;font-size:13px;line-height:1.9;">
                            <p style="margin:0 0 8px;">این کد تا <strong>{{ $ttlMinutes }} دقیقه</strong> دیگر معتبر است و فقط یک بار قابل استفاده است.</p>
                            <p style="margin:0 0 8px;color:#6b7280;">اگر شما درخواست بازیابی رمز عبور نداده‌اید، این ایمیل را نادیده بگیرید — رمز عبور شما بدون این کد تغییر نمی‌کند.</p>
                            <p style="margin:0;color:#6b7280;">این کد را با هیچ‌کس به اشتراک نگذارید؛ تیم پشتیبانی هرگز آن را از شما نمی‌پرسد.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px 24px;border-top:1px solid #e5e7eb;color:#9ca3af;font-size:11px;text-align:center;">
                            © {{ date('Y') }} ZED PROXY
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
