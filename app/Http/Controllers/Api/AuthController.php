<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Services\OtpService;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function __construct(private OtpService $otp)
    {
    }

    public function register(Request $request)
    {
        // Normalize before the uniqueness check so two differently-formatted
        // inputs that resolve to the same E.164 number can't both pass
        // validation and then collide on save.
        $request->merge(['phone_number' => PhoneNumber::toE164($request->input('phone_number', ''))]);

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required|string|max:255',
            'username' => 'required|string|alpha_dash|unique:users,username|max:255',
            'phone_number' => 'required|string|unique:users,phone_number|max:255',
            'email' => 'nullable|email|unique:users,email|max:255',
        ]);

        $user = User::create($validated);

        Log::info("New user registered: " . $user->phone_number);

        // Optionally send OTP right after registration if desired,
        // or just return success and let the client request OTP.
        // For simplicity, we just return success so the client can move to OTP step.
        return response()->json([
            'message' => 'Registration successful',
            'user' => $user
        ], 201);
    }
    public function requestOtp(Request $request)
    {
        $request->validate([
            'phone_number' => 'required|string',
        ]);

        $phone = PhoneNumber::toE164($request->input('phone_number'));

        // Check if user exists before sending OTP
        if (!User::where('phone_number', $phone)->exists()) {
            return response()->json(['message' => 'Unauthorized. Number not registered.'], 403);
        }

        if ($this->otp->request($phone) === 'cooldown') {
            return response()->json(['message' => 'Please wait before requesting another code.'], 429);
        }

        Log::info("OTP requested for: " . $phone);

        return response()->json([
            'message' => 'OTP sent successfully',
            'phone_number' => $phone
        ]);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'phone_number' => 'required|string',
            'otp' => 'required|string',
        ]);

        $phone = PhoneNumber::toE164($request->input('phone_number'));

        if (!$this->otp->verify($phone, $request->input('otp'))) {
            return response()->json(['message' => 'Invalid OTP'], 401);
        }

        $user = User::where('phone_number', $phone)->first();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Number not registered.'], 403);
        }

        if ($user->is_blocked) {
            return response()->json(['message' => 'Account is blocked.'], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Authenticated successfully',
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function updateProfile(Request $request)
    {
        $request->validate([
            'first_name' => 'sometimes|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'email' => 'nullable|email|max:255',
            'username' => 'sometimes|string|alpha_dash|max:255|unique:users,username,' . $request->user()->id,
            'about_status' => 'nullable|string|max:255',
            'photo' => 'nullable|image|max:10240', // 10MB max for photo
        ], [
            'username.alpha_dash' => 'The username must not contain spaces.'
        ]);

        $user = $request->user();
        
        $updateData = $request->only([
            'first_name', 'middle_name', 'last_name', 
            'email', 'username', 'about_status'
        ]);

        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('profile_photos', 'public');
            $updateData['photo_url'] = asset('storage/' . $path);
        }

        $user->update($updateData);

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }
}
