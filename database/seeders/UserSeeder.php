<?php

namespace Database\Seeders;

use App\Models\Tenants\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        User::create([
            'email' => 'superadmin@admin.com',
            'password' => bcrypt('password'),
        ]);
    }
}
