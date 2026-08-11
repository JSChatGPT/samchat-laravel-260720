<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Samchat verification code</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            width: 100% !important;
            font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #F0F2F8;
            color: #0F172A;
            padding: 40px 20px;
            -webkit-text-size-adjust: 100%;
        }
        .wrapper {
            max-width: 520px;
            margin: 0 auto;
        }
        .card {
            background: #FFFFFF;
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
        }
        .header {
            padding: 36px 40px 10px;
            text-align: center;
        }
        .logo {
            display: inline-block;
            align-items: center;
            justify-content: center;
            width: 56px;
            height: 56px;
            margin-bottom: 16px;
            filter: drop-shadow(0 4px 16px rgba(255, 87, 34, 0.25));
        }
        .logo svg { width: 56px; height: 56px; display: block; }
        .header h1 {
            color: #0F172A;
            font-size: 26px;
            font-weight: 700;
            line-height: 1.25;
            letter-spacing: 0;
            margin-bottom: 8px;
        }
        .header p {
            color: #94A3B8;
            font-size: 15px;
            line-height: 1.5;
        }
        .body {
            padding: 28px 40px 40px;
        }
        .body p {
            font-size: 15px;
            line-height: 1.6;
            color: #475569;
            margin-bottom: 16px;
        }
        .code-block {
            background: rgba(255, 87, 34, 0.08);
            border: 1px solid rgba(255, 87, 34, 0.22);
            border-radius: 14px;
            text-align: center;
            padding: 24px 20px;
            margin: 28px 0 14px;
        }
        .code-block .label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #FF5722;
            margin-bottom: 8px;
        }
        .code-block .code {
            font-size: 40px;
            font-weight: 800;
            letter-spacing: 8px;
            color: #0F172A;
            font-family: 'Courier New', Courier, monospace;
        }
        .expiry {
            font-size: 13px;
            color: #64748B;
            text-align: center;
            margin-bottom: 28px;
        }
        .footer {
            padding: 24px 40px;
            background: #F8FAFC;
            border-top: 1px solid rgba(0, 0, 0, 0.06);
            text-align: center;
        }
        .footer p {
            font-size: 12px;
            color: #94A3B8;
            line-height: 1.6;
        }
        .security-note {
            background: #FFFFFF;
            border: 1px solid rgba(255, 87, 34, 0.18);
            border-left: 4px solid #FF5722;
            border-radius: 12px;
            padding: 14px 16px;
            margin-top: 16px;
        }
        .security-note p {
            font-size: 13px;
            color: #475569;
            margin: 0;
        }
        .security-note strong {
            color: #0F172A;
        }
        @media only screen and (max-width: 520px) {
            body { padding: 20px 12px; }
            .header { padding: 30px 24px 8px; }
            .body { padding: 24px 24px 32px; }
            .footer { padding: 22px 24px; }
            .header h1 { font-size: 23px; }
            .code-block .code {
                font-size: 34px;
                letter-spacing: 5px;
            }
        }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="card">
        <!-- Header -->
        <div class="header">
            <div class="logo">
                <svg viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect width="56" height="56" rx="16" fill="#6366f1"/>
                    <path d="M18 20C18 18.8954 18.8954 18 20 18H36C37.1046 18 38 18.8954 38 20V32C38 33.1046 37.1046 34 36 34H26L22 38V34H20C18.8954 34 18 33.1046 18 32V20Z" fill="#ffffff"/>
                    <circle cx="25" cy="26" r="1.5" fill="#6366f1"/>
                    <circle cx="31" cy="26" r="1.5" fill="#6366f1"/>
                </svg>
            </div>
            <h1>Welcome to Samchat</h1>
            <p>Use this code to continue signing in.</p>
        </div>

        <!-- Body -->
        <div class="body">
            <p>Hi there,</p>
            <p>
                Use the code below to verify your identity on Samchat.
                Enter it in the app or on the website when prompted.
            </p>

            <div class="code-block">
                <div class="label">Your verification code</div>
                <div class="code">{{ $code }}</div>
            </div>

            <p class="expiry">This code expires in {{ $ttlMinutes }} minutes.</p>

            <div class="security-note">
                <p>
                    <strong>Never share this code.</strong>
                    Samchat will never ask for it by phone or email.
                    If you didn't request this, you can safely ignore this message.
                </p>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>
                You received this email because a login or registration was attempted
                for your Samchat account. If this wasn't you, no action is needed;
                your account remains secure.
            </p>
            <p style="margin-top: 8px;">&copy; {{ date('Y') }} Samchat. All rights reserved.</p>
        </div>
    </div>
</div>
</body>
</html>
