<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Samchat verification code</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f4f4f7;
            color: #333;
            padding: 40px 20px;
        }
        .wrapper {
            max-width: 520px;
            margin: 0 auto;
        }
        .card {
            background: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
        }
        .header {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            padding: 36px 40px;
            text-align: center;
        }
        .logo {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 60px;
            height: 60px;
            background: rgba(255,255,255,0.2);
            border-radius: 16px;
            margin-bottom: 16px;
        }
        .logo svg { width: 32px; height: 32px; }
        .header h1 {
            color: #ffffff;
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.3px;
        }
        .body {
            padding: 40px;
        }
        .body p {
            font-size: 15px;
            line-height: 1.6;
            color: #555;
            margin-bottom: 16px;
        }
        .code-block {
            background: #f0f0ff;
            border: 2px dashed #6366f1;
            border-radius: 12px;
            text-align: center;
            padding: 24px;
            margin: 28px 0;
        }
        .code-block .label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: #6366f1;
            margin-bottom: 8px;
        }
        .code-block .code {
            font-size: 42px;
            font-weight: 800;
            letter-spacing: 10px;
            color: #1a1a2e;
            font-family: 'Courier New', Courier, monospace;
        }
        .expiry {
            font-size: 13px;
            color: #888;
            text-align: center;
            margin-top: -12px;
            margin-bottom: 28px;
        }
        .divider {
            border: none;
            border-top: 1px solid #eee;
            margin: 24px 0;
        }
        .footer {
            padding: 24px 40px;
            background: #fafafa;
            border-top: 1px solid #eee;
            text-align: center;
        }
        .footer p {
            font-size: 12px;
            color: #aaa;
            line-height: 1.6;
        }
        .security-note {
            background: #fff8f0;
            border-left: 4px solid #f59e0b;
            border-radius: 4px;
            padding: 12px 16px;
            margin-top: 16px;
        }
        .security-note p {
            font-size: 13px;
            color: #78350f;
            margin: 0;
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
                    <path d="M18 20C18 18.8954 18.8954 18 20 18H36C37.1046 18 38 18.8954 38 20V32C38 33.1046 37.1046 34 36 34H26L22 38V34H20C18.8954 34 18 33.1046 18 32V20Z" fill="#ffffff"/>
                    <circle cx="25" cy="26" r="1.5" fill="#6366f1"/>
                    <circle cx="31" cy="26" r="1.5" fill="#6366f1"/>
                </svg>
            </div>
            <h1>Samchat Verification</h1>
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

            <p class="expiry">⏱ This code expires in {{ $ttlMinutes }} minutes.</p>

            <div class="security-note">
                <p>
                    🔒 <strong>Never share this code.</strong>
                    Samchat will never ask for it by phone or email.
                    If you didn't request this, you can safely ignore this message.
                </p>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>
                You received this email because a login or registration was attempted
                for your Samchat account. If this wasn't you, no action is needed —
                your account remains secure.
            </p>
            <p style="margin-top: 8px;">&copy; {{ date('Y') }} Samchat. All rights reserved.</p>
        </div>
    </div>
</div>
</body>
</html>
