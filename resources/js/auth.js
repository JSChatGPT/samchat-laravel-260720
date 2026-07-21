    function updateThemeIcon(theme) {
        const svg = document.querySelector('#btn-theme-toggle svg');
        if (theme === 'dark') {
            svg.innerHTML = `<circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>`;
        } else {
            svg.innerHTML = `<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>`;
        }
    }

    const savedTheme = localStorage.getItem('theme') || 'light';
    if (savedTheme === 'dark') {
        document.body.classList.add('dark-theme');
        updateThemeIcon('dark');
    }
    
    document.getElementById('btn-theme-toggle').addEventListener('click', () => {
        const isDark = document.body.classList.toggle('dark-theme');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        updateThemeIcon(isDark ? 'dark' : 'light');
    });

    const stepPhone = document.getElementById('step-phone');
    const stepOtp = document.getElementById('step-otp');
    const stepRegister = document.getElementById('step-register');
    
    const btnRequestOtp = document.getElementById('btn-request-otp');
    const btnVerifyOtp = document.getElementById('btn-verify-otp');
    const btnRegister = document.getElementById('btn-register');
    
    const phoneInput = document.getElementById('phone_number');
    const otpInput = document.getElementById('otp_code');
    const regPhoneInput = document.getElementById('reg_phone');
    
    // Initialize intlTelInput for Login
    const itiLogin = window.intlTelInput(phoneInput, {
        utilsScript: "https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/18.2.1/js/utils.js",
        initialCountry: "auto",
        geoIpLookup: function(callback) {
            fetch("https://ipapi.co/json")
                .then(res => res.json())
                .then(data => callback(data.country_code))
                .catch(() => callback("us"));
        },
    });

    // Initialize intlTelInput for Register
    const itiReg = window.intlTelInput(regPhoneInput, {
        utilsScript: "https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/18.2.1/js/utils.js",
        initialCountry: "auto",
        geoIpLookup: function(callback) {
            fetch("https://ipapi.co/json")
                .then(res => res.json())
                .then(data => callback(data.country_code))
                .catch(() => callback("us"));
        },
    });
    
    const errorPhone = document.getElementById('error-phone');
    const errorOtp = document.getElementById('error-otp');
    const errorRegister = document.getElementById('error-register');

    let currentPhone = '';

    // Transitions
    function showStep(stepToShow) {
        [stepPhone, stepOtp, stepRegister].forEach(s => {
            if (s !== stepToShow) {
                s.classList.add('hidden');
                setTimeout(() => s.style.display = 'none', 300);
            }
        });
        setTimeout(() => {
            stepToShow.style.display = 'block';
            setTimeout(() => stepToShow.classList.remove('hidden'), 50);
        }, 300);
    }

    document.getElementById('link-show-register').addEventListener('click', (e) => {
        e.preventDefault(); showStep(stepRegister);
    });
    document.getElementById('link-show-login').addEventListener('click', (e) => {
        e.preventDefault(); showStep(stepPhone);
    });
    document.getElementById('link-back-login-otp').addEventListener('click', (e) => {
        e.preventDefault(); showStep(stepPhone);
    });

    async function requestOtpFlow(phoneStr) {
        const response = await fetch('/api/auth/request-otp', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({ phone_number: phoneStr })
        });
        return response;
    }

    btnRequestOtp.addEventListener('click', async () => {
        const phone = itiLogin.getNumber(window.intlTelInputUtils ? window.intlTelInputUtils.numberFormat.E164 : undefined);
        
        if (!phone || phone.length < 5) {
            errorPhone.innerText = "Please enter a valid phone number.";
            errorPhone.style.display = "block";
            return;
        }

        btnRequestOtp.disabled = true;
        btnRequestOtp.innerText = "Requesting...";
        errorPhone.style.display = "none";

        try {
            const response = await requestOtpFlow(phone);
            const data = await response.json();

            if (response.ok) {
                currentPhone = phone;
                showStep(stepOtp);
            } else {
                errorPhone.innerText = data.message || "Failed to request OTP.";
                errorPhone.style.display = "block";
            }
        } catch (e) {
            errorPhone.innerText = "Network error occurred.";
            errorPhone.style.display = "block";
        } finally {
            btnRequestOtp.disabled = false;
            btnRequestOtp.innerText = "Continue";
        }
    });

    btnRegister.addEventListener('click', async () => {
        const phone = itiReg.getNumber(window.intlTelInputUtils ? window.intlTelInputUtils.numberFormat.E164 : undefined);
        const firstName = document.getElementById('reg_first_name').value.trim();
        const middleName = document.getElementById('reg_middle_name').value.trim();
        const lastName = document.getElementById('reg_last_name').value.trim();
        const username = document.getElementById('reg_username').value.trim();
        const email = document.getElementById('reg_email').value.trim();

        if (!phone || !firstName || !lastName || !username) {
            errorRegister.innerText = "Please fill in all required fields.";
            errorRegister.style.display = "block";
            return;
        }

        btnRegister.disabled = true;
        btnRegister.innerText = "Creating...";
        errorRegister.style.display = "none";

        try {
            const res = await fetch('/api/auth/register', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                },
                body: JSON.stringify({
                    first_name: firstName,
                    middle_name: middleName,
                    last_name: lastName,
                    username: username,
                    phone_number: phone,
                    email: email
                })
            });
            const data = await res.json();

            if (res.ok) {
                // Now request OTP
                currentPhone = phone;
                const otpRes = await requestOtpFlow(phone);
                if (otpRes.ok) {
                    showStep(stepOtp);
                } else {
                    errorRegister.innerText = "Registered successfully, but failed to send OTP.";
                    errorRegister.style.display = "block";
                }
            } else {
                errorRegister.innerText = data.message || "Registration failed.";
                errorRegister.style.display = "block";
            }
        } catch (e) {
            errorRegister.innerText = "Network error occurred.";
            errorRegister.style.display = "block";
        } finally {
            btnRegister.disabled = false;
            btnRegister.innerText = "Create Account";
        }
    });

    btnVerifyOtp.addEventListener('click', async () => {
        const otp = otpInput.value.trim();
        if (!otp) {
            errorOtp.innerText = "OTP is required.";
            errorOtp.style.display = "block";
            return;
        }

        btnVerifyOtp.disabled = true;
        btnVerifyOtp.innerText = "Verifying...";
        errorOtp.style.display = "none";

        try {
            const response = await fetch('/api/auth/verify-otp', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                },
                body: JSON.stringify({ phone_number: currentPhone, otp: otp })
            });

            const data = await response.json();

            if (response.ok && data.token) {
                const webAuthResponse = await fetch('/login', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                    },
                    body: JSON.stringify({ token: data.token })
                });

                if (webAuthResponse.ok) {
                    window.location.href = '/app';
                } else {
                    errorOtp.innerText = "Web session creation failed.";
                    errorOtp.style.display = "block";
                    btnVerifyOtp.disabled = false;
                    btnVerifyOtp.innerText = "Verify & Login";
                }
            } else {
                errorOtp.innerText = data.message || "Invalid OTP.";
                errorOtp.style.display = "block";
                btnVerifyOtp.disabled = false;
                btnVerifyOtp.innerText = "Verify & Login";
            }
        } catch (e) {
            errorOtp.innerText = "Network error occurred.";
            errorOtp.style.display = "block";
            btnVerifyOtp.disabled = false;
            btnVerifyOtp.innerText = "Verify & Login";
        }
    });
