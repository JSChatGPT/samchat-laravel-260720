<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Support\PhoneNumber;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        $contacts = $request->user()->savedContacts()->with('contactUser:id,first_name,last_name,username,phone_number,photo_url,about_status')->get();
        return response()->json(['contacts' => $contacts]);
    }

    public function sync(Request $request)
    {
        $request->validate([
            'contacts' => 'required|array',
            'contacts.*.phone_number' => 'required|string',
            'contacts.*.name' => 'required|string',
        ]);

        $user = $request->user();

        $phoneMap = [];
        foreach ($request->contacts as $contact) {
            // Normalize to E.164 (same canonical form users.phone_number is
            // stored in) so this becomes a plain normalized-vs-normalized match.
            $cleanPhone = PhoneNumber::toE164($contact['phone_number']);
            $phoneMap[$cleanPhone] = $contact['name'];
        }

        $matchingUsers = \App\Models\User::whereIn('phone_number', array_keys($phoneMap))
            ->where('id', '!=', $user->id)
            ->get();

        $syncedContacts = [];
        foreach ($matchingUsers as $matchedUser) {
            $customName = $phoneMap[$matchedUser->phone_number] ?? $matchedUser->first_name;
            
            $syncedContact = $user->savedContacts()->updateOrCreate(
                ['contact_user_id' => $matchedUser->id],
                ['custom_name' => $customName]
            );
            
            $syncedContacts[] = $syncedContact->load('contactUser:id,first_name,last_name,username,phone_number,photo_url,about_status');
        }

        return response()->json(['synced_count' => count($syncedContacts), 'contacts' => $syncedContacts]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'contact_user_id' => 'required|uuid|exists:users,id',
            'custom_name' => 'required|string|max:255',
        ]);

        if ($request->user()->id === $request->contact_user_id) {
            return response()->json(['error' => 'Cannot add yourself as a contact'], 400);
        }

        $contact = $request->user()->savedContacts()->updateOrCreate(
            ['contact_user_id' => $request->contact_user_id],
            ['custom_name' => $request->custom_name]
        );

        return response()->json(['contact' => $contact->load('contactUser:id,first_name,last_name,username,photo_url,about_status')], 201);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'custom_name' => 'required|string|max:255',
        ]);

        $contact = $request->user()->savedContacts()->findOrFail($id);
        $contact->update(['custom_name' => $request->custom_name]);

        return response()->json(['contact' => $contact->load('contactUser:id,first_name,last_name,username,photo_url,about_status')]);
    }

    public function destroy(Request $request, $id)
    {
        $contact = $request->user()->savedContacts()->findOrFail($id);
        $contact->delete();

        return response()->json(['status' => 'success', 'message' => 'Contact removed']);
    }
}
