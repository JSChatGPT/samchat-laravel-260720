<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Chat;
use App\Models\Message;

class AdminController extends Controller
{
    public function index()
    {
        $stats = [
            'total_users' => User::count(),
            'total_chats' => Chat::count(),
            'total_messages' => Message::count(),
        ];
        return view('admin.dashboard', compact('stats'));
    }

    public function users()
    {
        $users = User::orderBy('created_at', 'desc')->paginate(50);
        return view('admin.users', compact('users'));
    }

    public function toggleAdmin(Request $request, User $user)
    {
        // Prevent removing self as admin accidentally
        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot change your own admin status.');
        }

        $user->is_admin = !$user->is_admin;
        $user->save();

        return back()->with('success', 'User admin status updated successfully.');
    }

    public function tokens()
    {
        $tokens = auth()->user()->tokens;
        return view('admin.tokens', compact('tokens'));
    }

    public function generateToken(Request $request)
    {
        $request->validate([
            'token_name' => 'required|string|max:255',
        ]);

        $token = auth()->user()->createToken($request->token_name);

        return back()->with('tokenValue', $token->plainTextToken)
                     ->with('success', 'Token generated successfully. Please copy it now as it will not be shown again.');
    }

    public function revokeToken($tokenId)
    {
        auth()->user()->tokens()->where('id', $tokenId)->delete();
        return back()->with('success', 'Token revoked successfully.');
    }
}
