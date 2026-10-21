<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Creates the first administrator (employee self-registration is disabled, so someone has to exist).
 *   php spark db:seed AdminSeeder
 * Optional .env:  admin.email = you@example.com   admin.password = a-long-temporary-password
 * The account must change its password at first sign-in.
 */
class AdminSeeder extends Seeder
{
    public function run()
    {
        if ($this->db->table('users')->whereIn('role', ['administrator', 'admin'])->countAllResults() > 0) {
            echo "An administrator already exists. Nothing to do.\n";
            return;
        }

        $email    = strtolower((string) env('admin.email', 'admin@sunson.local'));
        $password = (string) env('admin.password', '');
        if (strlen($password) < 12) {
            $password = bin2hex(random_bytes(8)); // 16 characters
        }

        $this->db->table('users')->insert([
            'first_name' => 'Site', 'last_name' => 'Administrator', 'birthday' => '1990-01-01', 'gender' => 'prefer-not-to-say',
            'email' => $email, 'phone' => '', 'address' => '', 'username' => 'admin',
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'administrator', 'department' => 'Administration', 'account_status' => 'active', 'must_change_password' => 1,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        echo "Administrator created.\n  Email:    {$email}\n  Password: {$password}  (temporary - you will be asked to change it)\n";
    }
}
