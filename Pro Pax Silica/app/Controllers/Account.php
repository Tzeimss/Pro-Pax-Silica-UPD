<?php

namespace App\Controllers;

use App\Models\UserModel;
use CodeIgniter\HTTP\RedirectResponse;

class Account extends BaseController
{
    public function index(): string
    {
        return view('account', [
            'mode' => $this->request->getGet('mode') === 'signin' ? 'signin' : 'signup',
            'errors' => session()->getFlashdata('errors') ?? [],
            'old' => session()->getFlashdata('old') ?? [],
            'status' => session()->getFlashdata('status'),
        ]);
    }

    public function register(): RedirectResponse
    {
        $rules = [
            'firstName' => 'required|max_length[100]',
            'lastName' => 'required|max_length[100]',
            'middleName' => 'permit_empty|max_length[100]',
            'birthday' => 'required|valid_date[Y-m-d]',
            'gender' => 'required|in_list[female,male,non-binary,prefer-not-to-say]',
            'email' => 'required|valid_email|max_length[190]|is_unique[users.email]',
            'phone' => 'required|regex_match[/^[0-9+()\-\s]{7,}$/]|max_length[32]',
            'address' => 'required|max_length[255]',
            'username' => 'required|min_length[3]|max_length[50]|alpha_numeric_punct|is_unique[users.username]',
            'password' => 'required|min_length[8]|max_length[72]',
            'confirmPassword' => 'required|matches[password]',
        ];
        $data = $this->request->getPost();
        if (! $this->validateData($data, $rules)) {
            return redirect()->to(site_url('account?mode=signup'))->withInput()->with('errors', $this->validator->getErrors());
        }

        (new UserModel())->insert([
            'first_name' => trim($data['firstName']),
            'last_name' => trim($data['lastName']),
            'middle_name' => trim($data['middleName'] ?? ''),
            'birthday' => $data['birthday'],
            'gender' => $data['gender'],
            'email' => strtolower(trim($data['email'])),
            'phone' => trim($data['phone']),
            'address' => trim($data['address']),
            'username' => trim($data['username']),
            'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
        ]);

        return redirect()->to(site_url('account?mode=signin'))->with('status', 'Account created. You can now sign in.');
    }

    public function login(): RedirectResponse
    {
        $rules = ['email' => 'required|valid_email', 'password' => 'required'];
        $data = $this->request->getPost();
        if (! $this->validateData($data, $rules)) {
            return redirect()->to(site_url('account?mode=signin'))->withInput()->with('errors', $this->validator->getErrors());
        }

        $user = (new UserModel())->where('email', strtolower(trim($data['email'])))->first();
        if (! $user || ($user['account_status'] ?? 'active') !== 'active' || ! password_verify($data['password'], $user['password_hash'])) {
            return redirect()->to(site_url('account?mode=signin'))->withInput()->with('status', 'Email or password is incorrect.');
        }

        session()->regenerate();
        session()->set('user', ['id' => $user['id'], 'name' => $user['first_name'], 'role' => $user['role'] ?? 'customer', 'department' => $user['department'] ?? null, 'must_change_password' => (int)($user['must_change_password'] ?? 0)]);
        return redirect()->to(site_url('dashboard'))->with('status', 'You are signed in.');
    }

    public function logout(): RedirectResponse
    {
        session()->remove('user');
        session()->regenerate(true);
        return redirect()->to(site_url('/'));
    }
}
