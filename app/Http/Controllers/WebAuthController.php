<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class WebAuthController extends Controller
{
    /**
     * Store the API token in session and log the user into the web guard.
     */
    public function storeSession(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        // Find the token
        $token = PersonalAccessToken::findToken($request->token);

        if (!$token || !$token->tokenable) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $user = $token->tokenable;

        // Log the user into the web guard
        auth()->login($user);

        // Store the token in session for frontend API calls
        session(['api_token' => $request->token]);

        return response()->json(['message' => 'Web session created successfully']);
    }

    /**
     * Log the user out of the web guard.
     */
    public function logout(Request $request)
    {
        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
