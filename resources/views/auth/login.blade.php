<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Log in to Samchat Web — fast, secure messaging from your browser.">
    <title>Samchat Web - Login</title>
    
    @vite(['resources/css/login.css', 'resources/js/auth.js'])
    
    <!-- intl-tel-input -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/18.2.1/css/intlTelInput.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/18.2.1/js/intlTelInput.min.js"></script>
    <style>
        .iti { width: 100%; }
    </style>
</head>
<body>

<div class="theme-toggle-wrapper">
    <div class="icon-action" id="btn-theme-toggle" title="Toggle Theme">
        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
    </div>
</div>

<div class="login-container">
    
    <!-- Logo -->
    <div class="login-logo">
        <svg class="logo-icon" viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect width="56" height="56" rx="16" fill="#6366f1"/>
            <path d="M18 20C18 18.8954 18.8954 18 20 18H36C37.1046 18 38 18.8954 38 20V32C38 33.1046 37.1046 34 36 34H26L22 38V34H20C18.8954 34 18 33.1046 18 32V20Z" fill="#ffffff"/>
            <circle cx="25" cy="26" r="1.5" fill="#6366f1"/>
            <circle cx="31" cy="26" r="1.5" fill="#6366f1"/>
        </svg>
    </div>

    <div class="login-header">
        <h2>Welcome to Samchat</h2>
        <p>Log in to access your chats on the web.</p>
    </div>

    <!-- Step 1: Phone Number -->
    <div id="step-phone" class="step">
        <div class="form-group">
            <label for="phone_number">Phone Number</label>
            <input type="text" id="phone_number" class="form-input" placeholder="+1234567890">
            <div id="error-phone" class="error-msg"></div>
        </div>
        <button id="btn-request-otp" class="btn-primary">Continue</button>
        <p class="auth-switch">Don't have an account? <a href="#" id="link-show-register">Create Account</a></p>
    </div>

    <!-- Step 2: OTP Verification -->
    <div id="step-otp" class="step hidden" style="display: none;">
        <div class="form-group">
            <label for="otp_code">Enter OTP</label>
            <input type="text" id="otp_code" class="form-input" placeholder="Enter code" maxlength="6">
            <div id="error-otp" class="error-msg"></div>
            <p class="otp-hint">We sent a 6-digit code to your phone via SMS.</p>
        </div>
        <button id="btn-verify-otp" class="btn-primary">Verify & Login</button>
        <p class="auth-switch"><a href="#" id="link-back-login-otp">Back to Login</a></p>
    </div>

    <!-- Step 3: Registration -->
    <div id="step-register" class="step hidden" style="display: none;">
        <div class="form-row">
            <div class="form-group half">
                <label for="reg_first_name">First Name *</label>
                <input type="text" id="reg_first_name" class="form-input" placeholder="John">
            </div>
            <div class="form-group half">
                <label for="reg_middle_name">Middle Name</label>
                <input type="text" id="reg_middle_name" class="form-input" placeholder="Doe">
            </div>
        </div>
        
        <div class="form-group">
            <label for="reg_last_name">Last Name *</label>
            <input type="text" id="reg_last_name" class="form-input" placeholder="Smith">
        </div>
        
        <div class="form-group">
            <label for="reg_username">Username *</label>
            <input type="text" id="reg_username" class="form-input" placeholder="johnsmith" oninput="this.value = this.value.replace(/\s/g, '')">
        </div>
        
        <div class="form-group">
            <label for="reg_phone">Phone Number *</label>
            <input type="text" id="reg_phone" class="form-input" placeholder="+1234567890">
        </div>
        
        <div class="form-group">
            <label for="reg_email">Email</label>
            <input type="email" id="reg_email" class="form-input" placeholder="john@example.com">
        </div>

        <div id="error-register" class="error-msg"></div>

        <button id="btn-register" class="btn-primary">Create Account</button>
        <p class="auth-switch">Already have an account? <a href="#" id="link-show-login">Log In</a></p>
    </div>

</div>

</body>
</html>
