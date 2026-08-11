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

    const stepPhone    = document.getElementById('step-phone');
    const stepOtp      = document.getElementById('step-otp');
    const stepRegister = document.getElementById('step-register');

    const btnRequestOtp = document.getElementById('btn-request-otp');
    const btnVerifyOtp  = document.getElementById('btn-verify-otp');
    const btnRegister   = document.getElementById('btn-register');

    const phoneInput   = document.getElementById('phone_number');
    const otpInput     = document.getElementById('otp_code');
    const regPhoneInput = document.getElementById('reg_phone');

    const errorPhone    = document.getElementById('error-phone');
    const errorOtp      = document.getElementById('error-otp');
    const errorRegister = document.getElementById('error-register');
    const otpHintText   = document.getElementById('otp-hint-text');
    const linkResendOtp = document.getElementById('link-resend-otp');
    const resendCountdown = document.getElementById('otp-resend-countdown');

    // Initialize intlTelInput for Login
    const itiLogin = window.intlTelInput(phoneInput, {
        utilsScript: "https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/18.2.1/js/utils.js",
        initialCountry: "auto",
        geoIpLookup: function(callback) {
            fetch("https://ipapi.co/json")
                .then(res => res.json())
                .then(data => callback(data.country_code))
                .catch(() => callback("zm"));
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
                .catch(() => callback("zm"));
        },
    });

    let currentPhone = '';
    let resendTimer  = null;

    // -------------------------------------------------------------------------
    // Step transitions
    // -------------------------------------------------------------------------
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
        e.preventDefault();
        clearResendTimer();
        showStep(stepPhone);
    });

    // -------------------------------------------------------------------------
    // OTP hint + resend countdown helpers
    // -------------------------------------------------------------------------
    /**
     * Build and display the OTP delivery hint.
     * @param {string}      phone     E.164 phone number
     * @param {boolean}     emailSent Whether an email OTP was also sent
     * @param {string|null} emailHint Masked email address (e.g. "j***@gmail.com")
     */
    function setOtpHint(phone, emailSent, emailHint) {
        let hint = `We sent a 6-digit code to <strong>${phone}</strong> via SMS.`;
        if (emailSent && emailHint) {
            hint += ` A copy was also sent to <strong>${emailHint}</strong>.`;
        }
        otpHintText.innerHTML = hint;
    }

    /** Start (or restart) the 60-second resend cooldown UI. */
    function startResendCountdown() {
        let seconds = 60;
        linkResendOtp.style.display = 'none';
        resendCountdown.textContent = `Resend in ${seconds}s`;

        clearResendTimer();
        resendTimer = setInterval(() => {
            seconds--;
            if (seconds <= 0) {
                clearResendTimer();
                resendCountdown.textContent = '';
                linkResendOtp.style.display = 'inline';
            } else {
                resendCountdown.textContent = `Resend in ${seconds}s`;
            }
        }, 1000);
    }

    function clearResendTimer() {
        if (resendTimer) { clearInterval(resendTimer); resendTimer = null; }
        resendCountdown.textContent = '';
        linkResendOtp.style.display = 'none';
    }

    // -------------------------------------------------------------------------
    // Core API helpers
    // -------------------------------------------------------------------------
    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    }

    /**
     * POST /api/auth/request-otp and update OTP hint text.
     * Returns the parsed JSON on success, throws on error.
     */
    async function requestOtpFlow(phoneStr) {
        const response = await fetch('/api/auth/request-otp', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ phone_number: phoneStr }),
        });

        const data = await response.json();

        if (response.ok) {
            // Update the hint text with channel information from the response.
            setOtpHint(phoneStr, data.email_sent ?? false, data.email_hint ?? null);
        }

        return { response, data };
    }

    // -------------------------------------------------------------------------
    // Login: Step 1 → request OTP
    // -------------------------------------------------------------------------
    btnRequestOtp.addEventListener('click', async () => {
        const phone = itiLogin.getNumber(
            window.intlTelInputUtils ? window.intlTelInputUtils.numberFormat.E164 : undefined
        );

        if (!phone || phone.length < 5) {
            errorPhone.innerText = "Please enter a valid phone number.";
            errorPhone.style.display = "block";
            return;
        }

        btnRequestOtp.disabled = true;
        btnRequestOtp.innerText = "Requesting…";
        errorPhone.style.display = "none";

        try {
            const { response, data } = await requestOtpFlow(phone);

            if (response.ok) {
                currentPhone = phone;
                otpInput.value = '';
                errorOtp.style.display = 'none';
                startResendCountdown();
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

    // -------------------------------------------------------------------------
    // Resend OTP link
    // -------------------------------------------------------------------------
    linkResendOtp.addEventListener('click', async (e) => {
        e.preventDefault();
        if (!currentPhone) return;

        linkResendOtp.style.display = 'none';
        resendCountdown.textContent = 'Sending…';
        errorOtp.style.display = 'none';

        try {
            const { response, data } = await requestOtpFlow(currentPhone);
            if (response.ok) {
                startResendCountdown();
            } else {
                resendCountdown.textContent = '';
                linkResendOtp.style.display = 'inline';
                errorOtp.innerText = data.message || 'Failed to resend OTP.';
                errorOtp.style.display = 'block';
            }
        } catch (e) {
            resendCountdown.textContent = '';
            linkResendOtp.style.display = 'inline';
            errorOtp.innerText = 'Network error occurred.';
            errorOtp.style.display = 'block';
        }
    });

    // -------------------------------------------------------------------------
    // Registration: Step 3 → register then request OTP
    // -------------------------------------------------------------------------
    btnRegister.addEventListener('click', async () => {
        const phone      = itiReg.getNumber(
            window.intlTelInputUtils ? window.intlTelInputUtils.numberFormat.E164 : undefined
        );
        const firstName  = document.getElementById('reg_first_name').value.trim();
        const middleName = document.getElementById('reg_middle_name').value.trim();
        const lastName   = document.getElementById('reg_last_name').value.trim();
        const username   = document.getElementById('reg_username').value.trim();
        const email      = document.getElementById('reg_email').value.trim();

        if (!phone || !firstName || !lastName || !username) {
            errorRegister.innerText = "Please fill in all required fields.";
            errorRegister.style.display = "block";
            return;
        }

        btnRegister.disabled = true;
        btnRegister.innerText = "Creating…";
        errorRegister.style.display = "none";

        try {
            const res = await fetch('/api/auth/register', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    first_name:   firstName,
                    middle_name:  middleName,
                    last_name:    lastName,
                    username:     username,
                    phone_number: phone,
                    email:        email || undefined,
                }),
            });
            const data = await res.json();

            if (res.ok) {
                // Registration succeeded — request OTP (which also sends email OTP
                // if the user provided an email and the backend found one on the
                // newly-created account).
                currentPhone = phone;
                const { response: otpRes, data: otpData } = await requestOtpFlow(phone);

                if (otpRes.ok) {
                    otpInput.value = '';
                    errorOtp.style.display = 'none';
                    startResendCountdown();
                    showStep(stepOtp);
                } else {
                    errorRegister.innerText = "Registered successfully, but failed to send OTP.";
                    errorRegister.style.display = "block";
                }
            } else {
                // Surface field-level validation errors if the backend returns them.
                if (data.errors) {
                    const messages = Object.values(data.errors).flat().join(' ');
                    errorRegister.innerText = messages;
                } else {
                    errorRegister.innerText = data.message || "Registration failed.";
                }
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

    // -------------------------------------------------------------------------
    // Step 2: Verify OTP
    // -------------------------------------------------------------------------
    btnVerifyOtp.addEventListener('click', async () => {
        const otp = otpInput.value.trim();
        if (!otp) {
            errorOtp.innerText = "OTP is required.";
            errorOtp.style.display = "block";
            return;
        }

        btnVerifyOtp.disabled = true;
        btnVerifyOtp.innerText = "Verifying…";
        errorOtp.style.display = "none";

        try {
            const response = await fetch('/api/auth/verify-otp', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ phone_number: currentPhone, otp: otp }),
            });

            const data = await response.json();

            if (response.ok && data.token) {
                // Exchange the Sanctum token for a web session.
                const webAuthResponse = await fetch('/login', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({ token: data.token }),
                });

                if (webAuthResponse.ok) {
                    clearResendTimer();
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

    // Allow pressing Enter in the OTP field to submit.
    otpInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') btnVerifyOtp.click();
    });
