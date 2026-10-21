<?php

namespace App\Controllers;

use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Registration, sign-in and sign-out, backed by the `users` table.
 * Public registration always creates a CUSTOMER. Employees are created by HR/admin in the dashboard.
 */
class Account extends BaseController
{
    protected $helpers = ['form', 'url'];

    public function index()
    {
        if (session()->has('user')) {
            return redirect()->to(site_url('dashboard'));
        }

        return view('account', [
            'mode'   => $this->request->getGet('mode') === 'signin' ? 'signin' : 'signup',
            'status' => session()->getFlashdata('status'),
            'errors' => session()->getFlashdata('errors') ?? [],
        ]);
    }

    public function register()
    {
        $post = $this->request->getPost();
        foreach (['firstName', 'lastName', 'middleName', 'phone', 'address', 'username', 'email'] as $key) {
            $post[$key] = trim((string) ($post[$key] ?? ''));
        }
        $post['email'] = strtolower($post['email']);

        $rules = [
            'firstName'       => 'required|max_length[100]',
            'lastName'        => 'required|max_length[100]',
            'middleName'      => 'permit_empty|max_length[100]|regex_match[/^[A-Za-z .'-]+$/]',
            'birthday'        => 'required|valid_date[Y-m-d]',
            'gender'          => 'required|in_list[female,male,non-binary,prefer-not-to-say]',
            'email'           => 'required|valid_email|max_length[190]|is_unique[users.email]',
            'phone'           => 'required|max_length[32]|regex_match[/^[0-9+()\-\s]{7,}$/]',
            'address'         => 'required|max_length[255]',
            'username'        => 'required|min_length[3]|max_length[50]|regex_match[/^[A-Za-z0-9._-]+$/]|is_unique[users.username]',
            'password'        => 'required|min_length[8]|max_length[72]',
            'confirmPassword' => 'required|matches[password]',
        ];
        $messages = [
            'email'    => ['is_unique' => 'That email address is already registered.'],
            'username' => ['is_unique' => 'That username is taken.', 'regex_match' => 'Use letters, numbers, dots, dashes or underscores.'],
        ];

        $validation = service('validation')->setRules($rules, $messages);
        if (! $validation->run($post)) {
            return $this->back('Please fix the problems below.', array_values($validation->getErrors()), 'signup');
        }
        if (($post['birthday'] ?? '') > date('Y-m-d')) {
            return $this->back('Please fix the problems below.', ['Birthday cannot be in the future.'], 'signup');
        }

        try {
            db_connect()->table('users')->insert([
                'first_name'           => $post['firstName'],
                'last_name'            => $post['lastName'],
                'middle_name'          => $post['middleName'] !== '' ? $post['middleName'] : null,
                'birthday'             => $post['birthday'],
                'gender'               => $post['gender'],
                'email'                => $post['email'],
                'phone'                => $post['phone'],
                'address'              => $post['address'],
                'username'             => $post['username'],
                'password_hash'        => password_hash($post['password'], PASSWORD_DEFAULT),
                'role'                 => 'customer',
                'account_status'       => 'active',
                'must_change_password' => 0,
                'created_at'           => date('Y-m-d H:i:s'),
                'updated_at'           => date('Y-m-d H:i:s'),
            ]);
        } catch (DatabaseException $e) {
            log_message('error', 'Registration failed: ' . $e->getMessage());
            return $this->back('We could not create your account. The email or username may already be in use.', [], 'signup');
        }

        return redirect()->to(site_url('account?mode=signin'))->with('status', 'Your account has been created. Please sign in.');
    }

    public function login()
    {
        $email    = strtolower(trim((string) $this->request->getPost('email')));
        $password = (string) $this->request->getPost('password');

        if ($email === '' || $password === '') {
            return $this->back('Enter your email address and password.', [], 'signin');
        }

        $throttleKey = 'login_' . md5($this->request->getIPAddress() . '|' . $email);
        if (service('throttler')->check($throttleKey, 5, 60) === false) {
            return $this->back('Too many sign-in attempts. Please wait a minute and try again.', [], 'signin');
        }

        $db   = db_connect();
        $user = $db->table('users')->where('email', $email)->get()->getRowArray();

        // Always run one hash check so response time doesn't reveal whether the email exists.
        $hash  = $user['password_hash'] ?? password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
        $valid = password_verify($password, $hash) && $user !== null;

        if (! $valid) {
            return $this->back('Those details don\'t match our records.', [], 'signin');
        }
        if ($user['account_status'] !== 'active') {
            return $this->back('This account is inactive. Please contact Sun Son Solar support.', [], 'signin');
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $db->table('users')->where('id', $user['id'])->update(['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }

        session()->regenerate(true);
        session()->set('user', [
            'id'    => (int) $user['id'],
            'name'  => $user['first_name'],
            'email' => $user['email'],
            'role'  => $user['role'],
        ]);

        return redirect()->to(site_url('dashboard'));
    }

    public function logout()
    {
        session()->destroy();

        return redirect()->to(base_url('intro.html'));
    }

    private function back(string $status, array $errors, string $mode)
    {
        return redirect()->to(site_url('account?mode=' . $mode))->with('status', $status)->with('errors', $errors);
    }
}
