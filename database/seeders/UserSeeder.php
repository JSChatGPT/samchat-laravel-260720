<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [
            [
                'phone_number' => '+15551234567',
                'username' => 'Alice Smith',
                'about_status' => 'Available',
            ],
            [
                'phone_number' => '+442071234567',
                'username' => 'Bob Johnson',
                'about_status' => 'Busy working',
            ],
            [
                'phone_number' => '+61491570156',
                'username' => 'Charlie Brown',
                'about_status' => 'At the gym',
            ],
            [
                'phone_number' => '+19998887777',
                'username' => 'Test User',
                'about_status' => 'Testing the app!',
            ]
        ];

        foreach ($users as $user) {
            User::firstOrCreate(
                ['phone_number' => $user['phone_number']],
                [
                    'username' => $user['username'],
                    'about_status' => $user['about_status'],
                    'is_blocked' => false
                ]
            );
        }
    }
}
