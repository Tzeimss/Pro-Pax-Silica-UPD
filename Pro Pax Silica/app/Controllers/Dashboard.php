<?php

namespace App\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Database\BaseConnection;

/**
 * Feeds app/Views/dashboard.php and receipt.php from the database and
 * handles every form the dashboard posts to /dashboard/action.
 * Permissions are enforced HERE (the view only hides things).
 */
class Dashboard extends BaseController
{
    protected $helpers = ['form', 'url'];

    private BaseConnection $db;
    private array $me = [];

    private const ADMIN    = ['administrator', 'admin'];
    private const STAFF    = ['administrator', 'admin', 'hr', 'dispatch', 'accounting', 'customer_service', 'it', 'marketing', 'sales'];
    private const EMP_ROLES = ['administrator', 'technician', 'dispatch', 'hr', 'accounting', 'customer_service', 'sales', 'marketing', 'it'];
    private const BOOKING_STATUSES = ['Pending', 'Approved', 'Declined', 'Scheduled', 'In Progress', 'Completed', 'Cancelled'];
    private const BACKUP_TABLES = [
        'departments', 'users', 'services', 'products', 'bookings', 'technician_assignments', 'attendance_checkins',
        'service_reports', 'notifications', 'support_tickets', 'payments', 'receipts', 'quotations', 'announcements',
        'testimonials', 'blog_posts', 'audit_logs', 'system_settings',
    ];

    public function __construct()
    {
        $this->db = db_connect();
    }

    /* ------------------------------------------------------------------ page */

    public function index()
    {
        if (! $this->loadUser()) {
            return $this->toSignIn();
        }
        $user = $this->me;
        unset($user['password_hash']); // never hand the hash to a view
        $role = $user['role'];
        $id   = (int) $user['id'];
        $db   = $this->db;

        $isAdmin = $this->has(self::ADMIN);
        $isStaff = $this->has(self::STAFF);

        $data = [
            'user' => $user, 'role' => $role, 'profile' => $user,
            'status' => session()->getFlashdata('status'), 'errors' => session()->getFlashdata('errors') ?? [],
            'notifications' => [], 'announcements' => [], 'services' => [], 'products' => [], 'bookings' => [], 'tickets' => [],
            'payments' => [], 'receipts' => [], 'quotes' => [], 'customers' => [], 'employees' => [], 'technicians' => [],
            'departments' => [], 'checkins' => [], 'reports' => [], 'posts' => [], 'testimonials' => [], 'audit' => [],
            'serviceMetrics' => [], 'customerMetrics' => [],
            'metrics' => ['customers' => 0, 'employees' => 0, 'pending' => 0, 'upcoming' => 0, 'completed' => 0, 'technicians' => 0, 'revenue' => 0],
        ];

        // Forced password change: show only the password form.
        if (! empty($user['must_change_password'])) {
            return view('dashboard', $data);
        }

        $data['notifications'] = $db->table('notifications')->where('user_id', $id)->orderBy('id', 'DESC')->limit(50)->get()->getResultArray();
        $audience = $role === 'customer' ? ['customers', 'everyone'] : ['employees', 'everyone'];
        $data['announcements'] = $db->table('announcements')->whereIn('audience', $audience)->orderBy('id', 'DESC')->limit(20)->get()->getResultArray();

        $bookingBase = $db->table('bookings b')->join('services s', 's.id = b.service_id');

        if ($role === 'customer') {
            $data['services'] = $db->table('services')->where('available', 1)->orderBy('name')->get()->getResultArray();
            $data['bookings'] = $bookingBase->select('b.*, s.name AS service_name')->where('b.customer_id', $id)->orderBy('b.id', 'DESC')->get()->getResultArray();
            $data['tickets']  = $db->table('support_tickets')->where('customer_id', $id)->orderBy('id', 'DESC')->get()->getResultArray();
            $data['payments'] = $db->table('payments')->where('customer_id', $id)->orderBy('id', 'DESC')->get()->getResultArray();
            $data['receipts'] = $db->table('receipts r')->select('r.*, p.booking_id, p.amount')->join('payments p', 'p.id = r.payment_id')
                ->where('p.customer_id', $id)->orderBy('r.id', 'DESC')->get()->getResultArray();
            $data['quotes']   = $db->table('quotations')->where('customer_id', $id)->where('status !=', 'Draft')->orderBy('id', 'DESC')->get()->getResultArray();
        } elseif ($role === 'technician') {
            // Technicians only receive what the view shows: customer name, site, service, schedule.
            $data['bookings'] = $bookingBase->select('b.id, b.status, b.scheduled_at, b.address, s.name AS service_name, c.first_name, c.last_name')
                ->join('technician_assignments ta', 'ta.booking_id = b.id')->join('users c', 'c.id = b.customer_id')
                ->where('ta.technician_id', $id)->orderBy('b.scheduled_at', 'ASC')->get()->getResultArray();
        } elseif ($isStaff) {
            $data['bookings'] = $db->table('bookings b')->select('b.*, s.name AS service_name, c.first_name, c.last_name, c.phone AS customer_phone')
                ->join('services s', 's.id = b.service_id')->join('users c', 'c.id = b.customer_id')->orderBy('b.id', 'DESC')->get()->getResultArray();
        }

        if ($isStaff) {
            $data['departments'] = $db->table('departments')->orderBy('name')->get()->getResultArray();
            $canSell = $this->has(array_merge(self::ADMIN, ['sales']));

            if ($canSell) {
                $data['services'] = $db->table('services')->orderBy('name')->get()->getResultArray();
                $data['products'] = $db->table('products')->orderBy('name')->get()->getResultArray();
                $data['quotes']   = $db->table('quotations q')->select('q.*, c.first_name, c.last_name')->join('users c', 'c.id = q.customer_id')->orderBy('q.id', 'DESC')->get()->getResultArray();
            }
            if ($this->has(array_merge(self::ADMIN, ['hr', 'customer_service', 'sales']))) {
                $data['customers'] = $db->table('users')->select('id, first_name, last_name, email, phone, address, account_status')->where('role', 'customer')->orderBy('last_name')->get()->getResultArray();
            }
            if ($this->has(array_merge(self::ADMIN, ['hr']))) {
                $data['employees'] = $db->table('users')->select('id, first_name, middle_name, last_name, email, username, phone, address, department, role, account_status')
                    ->where('role !=', 'customer')->orderBy('last_name')->get()->getResultArray();
            }
            if ($this->has(array_merge(self::ADMIN, ['dispatch']))) {
                $data['technicians'] = $db->table('users')->select('id, first_name, last_name')->where(['role' => 'technician', 'account_status' => 'active'])->orderBy('last_name')->get()->getResultArray();
            }
            if ($this->has(array_merge(self::ADMIN, ['accounting']))) {
                $data['payments'] = $db->table('payments')->orderBy('id', 'DESC')->limit(200)->get()->getResultArray();
            }
            if ($this->has(array_merge(self::ADMIN, ['customer_service']))) {
                $data['tickets'] = $db->table('support_tickets t')->select('t.*, c.first_name, c.last_name')->join('users c', 'c.id = t.customer_id')->orderBy('t.id', 'DESC')->limit(100)->get()->getResultArray();
            }
            if ($isAdmin) {
                $data['checkins'] = $db->table('attendance_checkins a')->select('a.*, u.first_name, u.last_name')->join('users u', 'u.id = a.technician_id')->orderBy('a.id', 'DESC')->limit(100)->get()->getResultArray();
                $data['reports']  = $db->table('service_reports r')->select('r.*, u.first_name, u.last_name')->join('users u', 'u.id = r.technician_id')->orderBy('r.id', 'DESC')->limit(100)->get()->getResultArray();
                $data['posts']    = $db->table('blog_posts')->orderBy('id', 'DESC')->limit(50)->get()->getResultArray();
                $data['testimonials'] = $db->table('testimonials')->orderBy('id', 'DESC')->limit(100)->get()->getResultArray();
                $data['audit']    = $db->table('audit_logs a')->select('a.*, u.first_name, u.last_name')->join('users u', 'u.id = a.actor_id', 'left')->orderBy('a.id', 'DESC')->limit(50)->get()->getResultArray();
                $data['serviceMetrics'] = $db->query('SELECT s.name, COUNT(b.id) AS bookings, COALESCE(SUM(pay.total),0) AS revenue FROM services s
                    LEFT JOIN bookings b ON b.service_id = s.id
                    LEFT JOIN (SELECT booking_id, SUM(amount) AS total FROM payments GROUP BY booking_id) pay ON pay.booking_id = b.id
                    GROUP BY s.id, s.name ORDER BY bookings DESC')->getResultArray();
                $data['customerMetrics'] = $db->query('SELECT u.first_name, u.last_name, COUNT(b.id) AS bookings FROM users u JOIN bookings b ON b.customer_id = u.id
                    GROUP BY u.id, u.first_name, u.last_name ORDER BY bookings DESC LIMIT 10')->getResultArray();
            }

            $data['metrics'] = [
                'customers'   => $db->table('users')->where('role', 'customer')->countAllResults(),
                'employees'   => $db->table('users')->where('role !=', 'customer')->countAllResults(),
                'pending'     => $db->table('bookings')->where('status', 'Pending')->countAllResults(),
                'upcoming'    => $db->table('bookings')->whereIn('status', ['Approved', 'Scheduled'])->countAllResults(),
                'completed'   => $db->table('bookings')->where('status', 'Completed')->countAllResults(),
                'technicians' => $db->table('users')->where(['role' => 'technician', 'account_status' => 'active'])->countAllResults(),
                'revenue'     => (float) ($db->table('payments')->selectSum('amount')->get()->getRow()->amount ?? 0),
            ];
        }

        return view('dashboard', $data);
    }

    /* --------------------------------------------------------------- receipt */

    public function receipt(int $id)
    {
        if (! $this->loadUser()) {
            return $this->toSignIn();
        }
        $receipt = $this->db->table('receipts r')
            ->select('r.receipt_number, r.issued_at, p.amount, p.reference, p.paid_at, p.customer_id, c.first_name, c.last_name, b.address, s.name AS service_name')
            ->join('payments p', 'p.id = r.payment_id')->join('bookings b', 'b.id = p.booking_id')
            ->join('users c', 'c.id = p.customer_id')->join('services s', 's.id = b.service_id')
            ->where('r.id', $id)->get()->getRowArray();

        $allowed = $receipt && ((int) $receipt['customer_id'] === (int) $this->me['id'] || $this->has(array_merge(self::ADMIN, ['accounting'])));
        if (! $allowed) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('receipt', ['receipt' => $receipt]);
    }

    /* ----------------------------------------------------------- backup / restore */

    public function backup()
    {
        if (! $this->loadUser()) {
            return $this->toSignIn();
        }
        if (! $this->has(self::ADMIN)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $out = ['format' => 'sunson-solar-backup', 'version' => 1, 'created_at' => date('c'), 'tables' => []];
        foreach (self::BACKUP_TABLES as $table) {
            $out['tables'][$table] = $this->db->table($table)->get()->getResultArray();
        }
        $this->audit('backup_downloaded', 'system', null, 'JSON backup downloaded');

        return $this->response->download('sunson-backup-' . date('Ymd-His') . '.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function restore()
    {
        if (! $this->loadUser()) {
            return $this->toSignIn();
        }
        if (! $this->has(self::ADMIN)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $file = $this->request->getFile('backup');
        if (! $file || ! $file->isValid() || $file->getSize() > 20 * 1024 * 1024) {
            return $this->fail('Choose a valid backup file (JSON, up to 20 MB).');
        }
        $json = json_decode((string) file_get_contents($file->getTempName()), true);
        if (! is_array($json) || ($json['format'] ?? '') !== 'sunson-solar-backup' || ! is_array($json['tables'] ?? null)) {
            return $this->fail('That file is not a Sun Son Solar backup.');
        }

        $counts = [];
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->transStart();
        try {
            foreach (self::BACKUP_TABLES as $table) {
                $rows = $json['tables'][$table] ?? [];
                if (! is_array($rows)) {
                    continue;
                }
                $columns = $this->db->getFieldNames($table);
                foreach ($rows as $row) {
                    if (! is_array($row) || ! isset($row['id'])) {
                        continue;
                    }
                    $row = array_intersect_key($row, array_flip($columns));
                    $exists = $this->db->table($table)->where('id', $row['id'])->countAllResults() > 0;
                    if ($exists) {
                        $this->db->table($table)->where('id', $row['id'])->update($row);
                    } else {
                        $this->db->table($table)->insert($row);
                    }
                    $counts[$table] = ($counts[$table] ?? 0) + 1;
                }
            }
            $this->db->transComplete();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
            log_message('error', 'Restore failed: ' . $e->getMessage());
            return $this->fail('The backup could not be restored. No changes were saved.');
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');

        if (! $this->db->transStatus()) {
            return $this->fail('The backup could not be restored. No changes were saved.');
        }
        $this->audit('backup_restored', 'system', null, 'Restored ' . array_sum($counts) . ' records from ' . $file->getClientName());

        return $this->ok('Backup restored: ' . array_sum($counts) . ' records added or updated.');
    }

    /* ---------------------------------------------------------------- actions */

    public function action()
    {
        if (! $this->loadUser()) {
            return $this->toSignIn();
        }
        $action = (string) $this->request->getPost('action');

        // A temporary password must be replaced before anything else.
        if (! empty($this->me['must_change_password']) && $action !== 'password') {
            return $this->fail('Please set your personal password first.');
        }

        $handlers = [
            'profile' => 'actProfile', 'password' => 'actPassword', 'read_notification' => 'actReadNotification',
            'book' => 'actBook', 'cancel_booking' => 'actCancelBooking', 'ticket' => 'actTicket', 'testimonial' => 'actTestimonial',
            'quote_request' => 'actQuoteRequest', 'checkin' => 'actCheckin', 'booking_status' => 'actBookingStatus',
            'service_report' => 'actServiceReport', 'assign_technician' => 'actAssignTechnician',
            'quotation_update' => 'actQuotationUpdate', 'quotation' => 'actQuotation', 'customer_status' => 'actCustomerStatus',
            'availability' => 'actAvailability', 'catalog' => 'actCatalog', 'payment' => 'actPayment',
            'announcement' => 'actAnnouncement', 'notification' => 'actNotification', 'employee' => 'actEmployee',
            'ticket_reply' => 'actTicketReply', 'blog' => 'actBlog', 'publish_testimonial' => 'actPublishTestimonial',
        ];
        if (! isset($handlers[$action])) {
            return $this->fail('That action is not available.');
        }

        try {
            return $this->{$handlers[$action]}();
        } catch (\Throwable $e) {
            log_message('error', 'Dashboard action "' . $action . '" failed: ' . $e->getMessage());
            return $this->fail('Something went wrong and nothing was saved. Please try again.');
        }
    }

    /* -- everyone -- */

    private function actProfile()
    {
        $p = $this->request->getPost();
        $first = $this->t($p['firstName'] ?? ''); $last = $this->t($p['lastName'] ?? '');
        $phone = $this->t($p['phone'] ?? '');     $address = $this->t($p['address'] ?? '');
        if ($first === '' || $last === '' || $phone === '' || $address === '') {
            return $this->fail('First name, last name, phone and address are required.');
        }
        if (mb_strlen($first) > 100 || mb_strlen($last) > 100 || mb_strlen($this->t($p['middleName'] ?? '')) > 100 || mb_strlen($phone) > 32 || mb_strlen($address) > 255) {
            return $this->fail('One of the fields is too long.');
        }
        $set = [
            'first_name' => $first, 'last_name' => $last, 'middle_name' => $this->t($p['middleName'] ?? '') ?: null,
            'phone' => $phone, 'address' => $address, 'preferences' => $this->t($p['preferences'] ?? '') ?: null,
            'updated_at' => $this->now(),
        ];
        if (! empty($p['birthday'])) {
            $d = \DateTime::createFromFormat('Y-m-d', (string) $p['birthday']);
            if (! $d || $d->format('Y-m-d') !== $p['birthday'] || $p['birthday'] > date('Y-m-d')) {
                return $this->fail('Enter a valid birthdate.');
            }
            $set['birthday'] = $p['birthday'];
        }
        if (! empty($p['gender'])) {
            if (! in_array($p['gender'], ['female', 'male', 'non-binary', 'prefer-not-to-say'], true)) {
                return $this->fail('Choose a valid gender option.');
            }
            $set['gender'] = $p['gender'];
        }
        $this->db->table('users')->where('id', $this->me['id'])->update($set);
        session()->set('user.name', $first);

        return $this->ok('Profile saved.');
    }

    private function actPassword()
    {
        $p = $this->request->getPost();
        $new = (string) ($p['new_password'] ?? '');
        $forced = ! empty($this->me['must_change_password']);

        if (! $forced && ! password_verify((string) ($p['current_password'] ?? ''), $this->me['password_hash'])) {
            return $this->fail('Your current password is incorrect.');
        }
        if (strlen($new) < 8 || strlen($new) > 72) {
            return $this->fail('Use a new password of 8 to 72 characters.');
        }
        if ($new !== (string) ($p['confirm_password'] ?? '')) {
            return $this->fail('The new passwords do not match.');
        }
        if (password_verify($new, $this->me['password_hash'])) {
            return $this->fail('Choose a password different from your current one.');
        }
        $this->db->table('users')->where('id', $this->me['id'])->update([
            'password_hash' => password_hash($new, PASSWORD_DEFAULT), 'must_change_password' => 0, 'updated_at' => $this->now(),
        ]);
        session()->regenerate(true);
        $this->audit('password_changed', 'users', (int) $this->me['id'], null);

        return $this->ok('Password updated.');
    }

    private function actReadNotification()
    {
        $this->db->table('notifications')->where(['id' => (int) $this->request->getPost('notification_id'), 'user_id' => $this->me['id']])->update(['read_at' => $this->now()]);

        return $this->ok('Marked as read.');
    }

    /* -- customer -- */

    private function actBook()
    {
        if ($this->me['role'] !== 'customer') {
            return $this->fail('Only customers can request services.');
        }
        $p = $this->request->getPost();
        $service = $this->db->table('services')->where(['id' => (int) ($p['service_id'] ?? 0), 'available' => 1])->get()->getRowArray();
        $address = $this->t($p['address'] ?? '');
        if (! $service || $address === '' || mb_strlen($address) > 255) {
            return $this->fail('Choose an available service and enter the service address.');
        }
        $when = $this->dt($p['scheduled_at'] ?? '');
        if ($when && $when < $this->now()) {
            return $this->fail('Choose a preferred time in the future.');
        }
        $this->db->table('bookings')->insert([
            'customer_id' => $this->me['id'], 'service_id' => $service['id'], 'requested_at' => $this->now(), 'scheduled_at' => $when,
            'address' => $address, 'notes' => $this->t($p['notes'] ?? '') ?: null, 'status' => 'Pending', 'created_by' => $this->me['id'],
        ]);
        $this->audit('booking_requested', 'bookings', (int) $this->db->insertID(), $service['name']);

        return $this->ok('Your service request was sent. The team will confirm your booking.');
    }

    private function actCancelBooking()
    {
        $this->db->table('bookings')->where(['id' => (int) $this->request->getPost('booking_id'), 'customer_id' => $this->me['id']])
            ->whereIn('status', ['Pending', 'Approved', 'Scheduled'])->update(['status' => 'Cancelled']);
        if ($this->db->affectedRows() < 1) {
            return $this->fail('That request can no longer be cancelled.');
        }
        $this->audit('booking_cancelled', 'bookings', (int) $this->request->getPost('booking_id'), null);

        return $this->ok('Service request cancelled.');
    }

    private function actTicket()
    {
        $subject = $this->t($this->request->getPost('subject')); $message = $this->t($this->request->getPost('message'));
        if ($this->me['role'] !== 'customer' || $subject === '' || $message === '' || mb_strlen($subject) > 180) {
            return $this->fail('Enter a subject (up to 180 characters) and your message.');
        }
        $this->db->table('support_tickets')->insert(['customer_id' => $this->me['id'], 'subject' => $subject, 'message' => $message, 'status' => 'Open', 'created_at' => $this->now()]);

        return $this->ok('Your support request was sent.');
    }

    private function actTestimonial()
    {
        $quote = $this->t($this->request->getPost('quote'));
        if ($this->me['role'] !== 'customer' || $quote === '' || mb_strlen($quote) > 2000) {
            return $this->fail('Write your testimonial (up to 2000 characters).');
        }
        $this->db->table('testimonials')->insert([
            'customer_name' => $this->me['first_name'] . ' ' . mb_substr($this->me['last_name'], 0, 1) . '.', 'quote' => $quote, 'approved' => 0, 'created_at' => $this->now(),
        ]);

        return $this->ok('Thank you! Your testimonial will appear once the team reviews it.');
    }

    private function actQuoteRequest()
    {
        $items = $this->t($this->request->getPost('items'));
        if ($this->me['role'] !== 'customer' || $items === '') {
            return $this->fail('Tell us what you would like priced.');
        }
        $this->db->table('quotations')->insert(['customer_id' => $this->me['id'], 'items' => $items, 'amount' => 0, 'status' => 'Requested', 'created_at' => $this->now()]);

        return $this->ok('Quote request sent. Our sales team will follow up.');
    }

    /* -- technician -- */

    private function actCheckin()
    {
        $bookingId = (int) $this->request->getPost('booking_id');
        if ($this->me['role'] !== 'technician' || ! $this->assigned($bookingId)) {
            return $this->fail('That job is not assigned to you.');
        }
        $lat = $this->request->getPost('latitude'); $lng = $this->request->getPost('longitude');
        $valid = is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180;
        $this->db->table('attendance_checkins')->insert([
            'booking_id' => $bookingId, 'technician_id' => $this->me['id'], 'checked_in_at' => $this->now(),
            'latitude' => $valid ? $lat : null, 'longitude' => $valid ? $lng : null,
        ]);

        return $this->ok('Checked in.');
    }

    private function actServiceReport()
    {
        $p = $this->request->getPost();
        $bookingId = (int) ($p['booking_id'] ?? 0);
        $summary = $this->t($p['summary'] ?? '');
        if ($this->me['role'] !== 'technician' || ! $this->assigned($bookingId) || $summary === '') {
            return $this->fail('Write a work report for one of your assigned jobs.');
        }
        $this->db->table('service_reports')->insert([
            'booking_id' => $bookingId, 'technician_id' => $this->me['id'], 'summary' => $summary,
            'equipment_problem' => $this->t($p['equipment_problem'] ?? '') ?: null, 'customer_concern' => $this->t($p['customer_concern'] ?? '') ?: null, 'created_at' => $this->now(),
        ]);

        return $this->ok('Service report sent.');
    }

    /* -- dispatch / technician status -- */

    private function actBookingStatus()
    {
        $p = $this->request->getPost();
        $id = (int) ($p['booking_id'] ?? 0);
        $status = (string) ($p['status'] ?? '');
        if (! in_array($status, self::BOOKING_STATUSES, true)) {
            return $this->fail('Choose a valid status.');
        }
        $booking = $this->db->table('bookings b')->select('b.*, s.name AS service_name')->join('services s', 's.id = b.service_id')->where('b.id', $id)->get()->getRowArray();
        if (! $booking) {
            return $this->fail('Booking not found.');
        }

        if ($this->me['role'] === 'technician') {
            if (! $this->assigned($id) || ! in_array($status, ['In Progress', 'Completed'], true)) {
                return $this->fail('You can only mark your own jobs In Progress or Completed.');
            }
            $set = ['status' => $status];
        } elseif ($this->has(array_merge(self::ADMIN, ['dispatch']))) {
            $set = ['status' => $status];
            if ($when = $this->dt($p['scheduled_at'] ?? '')) {
                $set['scheduled_at'] = $when;
            }
            if (isset($p['quoted_amount']) && $p['quoted_amount'] !== '' && is_numeric($p['quoted_amount']) && (float) $p['quoted_amount'] >= 0) {
                $set['quoted_amount'] = round((float) $p['quoted_amount'], 2);
            }
            if ($status === 'Scheduled' && empty($set['scheduled_at']) && empty($booking['scheduled_at'])) {
                return $this->fail('Pick a date and time before scheduling.');
            }
        } else {
            return $this->deny();
        }

        $this->db->table('bookings')->where('id', $id)->update($set);
        $this->notify((int) $booking['customer_id'], 'Service update', 'Your ' . $booking['service_name'] . ' request is now: ' . $status . '.', 'booking');
        $this->audit('booking_status', 'bookings', $id, $status);

        return $this->ok('Booking updated.');
    }

    private function actAssignTechnician()
    {
        if (! $this->has(array_merge(self::ADMIN, ['dispatch']))) {
            return $this->deny();
        }
        $p = $this->request->getPost();
        $bookingId = (int) ($p['booking_id'] ?? 0);
        $tech = $this->db->table('users')->where(['id' => (int) ($p['technician_id'] ?? 0), 'role' => 'technician', 'account_status' => 'active'])->get()->getRowArray();
        $booking = $this->db->table('bookings')->where('id', $bookingId)->get()->getRowArray();
        if (! $tech || ! $booking) {
            return $this->fail('Choose a booking and an active technician.');
        }
        $this->db->table('technician_assignments')->ignore(true)->insert([
            'booking_id' => $bookingId, 'technician_id' => $tech['id'], 'assigned_by' => $this->me['id'], 'created_at' => $this->now(),
        ]);
        if ($when = $this->dt($p['scheduled_at'] ?? '')) {
            $set = ['scheduled_at' => $when];
            if (in_array($booking['status'], ['Pending', 'Approved'], true)) {
                $set['status'] = 'Scheduled';
            }
            $this->db->table('bookings')->where('id', $bookingId)->update($set);
        }
        $this->notify((int) $tech['id'], 'New job assigned', 'You have been assigned to booking #' . $bookingId . '.', 'assignment');
        $this->notify((int) $booking['customer_id'], 'Technician assigned', 'A technician has been assigned to your booking #' . $bookingId . '.', 'booking');
        $this->audit('technician_assigned', 'bookings', $bookingId, 'Technician #' . $tech['id']);

        return $this->ok('Technician assigned.');
    }

    /* -- sales -- */

    private function actQuotation()
    {
        if (! $this->has(array_merge(self::ADMIN, ['sales']))) {
            return $this->deny();
        }
        $p = $this->request->getPost();
        $customer = $this->db->table('users')->where(['id' => (int) ($p['customer_id'] ?? 0), 'role' => 'customer'])->get()->getRowArray();
        $items = $this->t($p['items'] ?? '');
        if (! $customer || $items === '' || ! is_numeric($p['amount'] ?? null) || (float) $p['amount'] < 0) {
            return $this->fail('Choose a customer, enter the proposal and a valid amount.');
        }
        $bookingId = (int) ($p['booking_id'] ?? 0) ?: null;
        if ($bookingId && ! $this->db->table('bookings')->where(['id' => $bookingId, 'customer_id' => $customer['id']])->countAllResults()) {
            return $this->fail('That booking does not belong to the selected customer.');
        }
        $this->db->table('quotations')->insert([
            'customer_id' => $customer['id'], 'booking_id' => $bookingId, 'items' => $items, 'amount' => round((float) $p['amount'], 2),
            'status' => 'Sent', 'created_by' => $this->me['id'], 'created_at' => $this->now(),
        ]);
        $qid = (int) $this->db->insertID();
        $this->notify((int) $customer['id'], 'New quotation', 'A quotation (#' . $qid . ') is ready for you in your dashboard.', 'quotation');
        $this->audit('quotation_sent', 'quotations', $qid, null);

        return $this->ok('Quotation sent.');
    }

    private function actQuotationUpdate()
    {
        if (! $this->has(array_merge(self::ADMIN, ['sales']))) {
            return $this->deny();
        }
        $p = $this->request->getPost();
        $q = $this->db->table('quotations')->where('id', (int) ($p['quotation_id'] ?? 0))->get()->getRowArray();
        if (! $q || ! in_array($p['status'] ?? '', ['Requested', 'Draft', 'Sent', 'Accepted', 'Declined', 'Expired'], true)) {
            return $this->fail('Choose a quotation and a valid status.');
        }
        $set = ['status' => $p['status']];
        if (isset($p['amount']) && $p['amount'] !== '') {
            if (! is_numeric($p['amount']) || (float) $p['amount'] < 0) {
                return $this->fail('Enter a valid amount.');
            }
            $set['amount'] = round((float) $p['amount'], 2);
        }
        if ($this->t($p['items'] ?? '') !== '') {
            $set['items'] = $this->t($p['items']);
        }
        $this->db->table('quotations')->where('id', $q['id'])->update($set);
        if ($p['status'] === 'Sent' && $q['status'] !== 'Sent') {
            $this->notify((int) $q['customer_id'], 'Quotation update', 'Quotation #' . $q['id'] . ' is ready for you in your dashboard.', 'quotation');
        }
        $this->audit('quotation_updated', 'quotations', (int) $q['id'], $p['status']);

        return $this->ok('Quotation saved.');
    }

    private function actCatalog()
    {
        if (! $this->has(array_merge(self::ADMIN, ['sales']))) {
            return $this->deny();
        }
        $p = $this->request->getPost();
        $type = $p['catalog_type'] ?? '';
        if (! in_array($type, ['service', 'product'], true)) {
            return $this->fail('Unknown catalog type.');
        }
        $table = $type === 'service' ? 'services' : 'products';
        $name = $this->t($p['name'] ?? ''); $category = $this->t($p['category'] ?? '');
        if ($name === '' || $category === '' || ! is_numeric($p['price'] ?? null) || (float) $p['price'] < 0 || mb_strlen($name) > 140 || mb_strlen($category) > 80) {
            return $this->fail('Enter a name, category and a valid price.');
        }
        $row = ['name' => $name, 'category' => $category, 'price' => round((float) $p['price'], 2)];
        if ($type === 'product' && isset($p['stock'])) {
            if (! ctype_digit((string) $p['stock'])) {
                return $this->fail('Stock must be a whole number.');
            }
            $row['stock'] = (int) $p['stock'];
        }

        $itemId = (int) ($p['item_id'] ?? 0);
        if ($itemId) {
            $this->db->table($table)->where('id', $itemId)->update($row);
        } else {
            $row['description'] = $this->t($p['description'] ?? '') ?: null;
            $row['available'] = isset($p['available']) ? 1 : 0;
            $this->db->table($table)->insert($row);
            $itemId = (int) $this->db->insertID();
        }
        $this->audit('catalog_saved', $table, $itemId, $name);

        return $this->ok(ucfirst($type) . ' saved.');
    }

    private function actAvailability()
    {
        if (! $this->has(array_merge(self::ADMIN, ['sales']))) {
            return $this->deny();
        }
        $type = $this->request->getPost('catalog_type');
        if (! in_array($type, ['service', 'product'], true)) {
            return $this->fail('Unknown catalog type.');
        }
        $table = $type === 'service' ? 'services' : 'products';
        $itemId = (int) $this->request->getPost('item_id');
        $this->db->table($table)->where('id', $itemId)->update(['available' => $this->request->getPost('available') ? 1 : 0]);
        $this->audit('availability_changed', $table, $itemId, null);

        return $this->ok('Availability saved.');
    }

    /* -- accounting -- */

    private function actPayment()
    {
        if (! $this->has(array_merge(self::ADMIN, ['accounting']))) {
            return $this->deny();
        }
        $p = $this->request->getPost();
        $booking = $this->db->table('bookings b')->select('b.id, b.customer_id, s.name AS service_name')->join('services s', 's.id = b.service_id')->where('b.id', (int) ($p['booking_id'] ?? 0))->get()->getRowArray();
        if (! $booking || ! is_numeric($p['amount'] ?? null) || (float) $p['amount'] <= 0) {
            return $this->fail('Choose a booking and enter an amount greater than zero.');
        }
        $this->db->transStart();
        $this->db->table('payments')->insert([
            'booking_id' => $booking['id'], 'customer_id' => $booking['customer_id'], 'amount' => round((float) $p['amount'], 2),
            'reference' => mb_substr($this->t($p['reference'] ?? ''), 0, 100) ?: null, 'paid_at' => $this->now(), 'status' => 'recorded', 'recorded_by' => $this->me['id'],
        ]);
        $paymentId = (int) $this->db->insertID();
        $this->db->table('receipts')->insert([
            'payment_id' => $paymentId, 'receipt_number' => 'SSS-' . date('Ymd') . '-' . str_pad((string) $paymentId, 5, '0', STR_PAD_LEFT), 'issued_at' => $this->now(),
        ]);
        $this->db->transComplete();
        if (! $this->db->transStatus()) {
            return $this->fail('The payment could not be recorded.');
        }
        $this->notify((int) $booking['customer_id'], 'Payment received', 'We recorded your payment for booking #' . $booking['id'] . '. Your receipt is in your dashboard.', 'payment');
        $this->audit('payment_recorded', 'payments', $paymentId, 'Booking #' . $booking['id']);

        return $this->ok('Payment recorded and receipt issued.');
    }

    /* -- HR / admin / marketing / support -- */

    private function actCustomerStatus()
    {
        if (! $this->has(array_merge(self::ADMIN, ['hr']))) {
            return $this->deny();
        }
        $status = $this->request->getPost('account_status');
        $id = (int) $this->request->getPost('customer_id');
        if (! in_array($status, ['active', 'inactive'], true)) {
            return $this->fail('Choose a valid account status.');
        }
        $this->db->table('users')->where(['id' => $id, 'role' => 'customer'])->update(['account_status' => $status, 'updated_at' => $this->now()]);
        $this->audit('customer_status', 'users', $id, $status);

        return $this->ok('Customer account updated.');
    }

    private function actAnnouncement()
    {
        if (! $this->has(array_merge(self::ADMIN, ['hr']))) {
            return $this->deny();
        }
        $p = $this->request->getPost();
        $title = $this->t($p['title'] ?? ''); $message = $this->t($p['message'] ?? '');
        if ($title === '' || $message === '' || mb_strlen($title) > 160 || ! in_array($p['audience'] ?? '', ['employees', 'customers', 'everyone'], true)) {
            return $this->fail('Enter an audience, title and message.');
        }
        $this->db->table('announcements')->insert(['title' => $title, 'message' => $message, 'audience' => $p['audience'], 'created_by' => $this->me['id'], 'created_at' => $this->now()]);
        $this->audit('announcement_posted', 'announcements', (int) $this->db->insertID(), $title);

        return $this->ok('Announcement posted.');
    }

    private function actNotification()
    {
        if (! $this->has(array_merge(self::ADMIN, ['hr', 'marketing']))) {
            return $this->deny();
        }
        $p = $this->request->getPost();
        $title = $this->t($p['title'] ?? ''); $message = $this->t($p['message'] ?? '');
        $audience = $p['audience'] ?? '';
        if ($title === '' || $message === '' || mb_strlen($title) > 160 || ! in_array($audience, ['customers', 'employees', 'everyone'], true)) {
            return $this->fail('Enter an audience, title and message.');
        }
        $users = $this->db->table('users')->select('id')->where('account_status', 'active');
        if ($audience === 'customers') {
            $users->where('role', 'customer');
        } elseif ($audience === 'employees') {
            $users->where('role !=', 'customer');
        }
        $rows = [];
        foreach ($users->get()->getResultArray() as $u) {
            $rows[] = ['user_id' => $u['id'], 'title' => $title, 'message' => $message, 'type' => 'update', 'sent_at' => $this->now(), 'delivery_status' => 'sent', 'created_by' => $this->me['id'], 'created_at' => $this->now()];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $this->db->table('notifications')->insertBatch($chunk);
        }
        $this->audit('notification_sent', 'notifications', null, $audience . ': ' . $title);

        return $this->ok('Update delivered to ' . count($rows) . ' inbox(es).');
    }

    private function actTicketReply()
    {
        if (! $this->has(array_merge(self::ADMIN, ['customer_service']))) {
            return $this->deny();
        }
        $p = $this->request->getPost();
        $ticket = $this->db->table('support_tickets')->where('id', (int) ($p['ticket_id'] ?? 0))->get()->getRowArray();
        $response = $this->t($p['response'] ?? '');
        if (! $ticket || $response === '' || ! in_array($p['status'] ?? '', ['Responded', 'Closed'], true)) {
            return $this->fail('Write a reply and choose a ticket status.');
        }
        $this->db->table('support_tickets')->where('id', $ticket['id'])->update(['response' => $response, 'status' => $p['status'], 'updated_at' => $this->now()]);
        $this->notify((int) $ticket['customer_id'], 'Support reply', 'We replied to your request: ' . $ticket['subject'], 'support');
        $this->audit('ticket_reply', 'support_tickets', (int) $ticket['id'], $p['status']);

        return $this->ok('Reply saved.');
    }

    private function actEmployee()
    {
        if (! $this->has(array_merge(self::ADMIN, ['hr']))) {
            return $this->deny();
        }
        $p = $this->request->getPost();
        $isAdmin = $this->has(self::ADMIN);
        $roles = $isAdmin ? self::EMP_ROLES : array_values(array_diff(self::EMP_ROLES, ['administrator']));
        $id = (int) ($p['user_id'] ?? 0);

        $first = $this->t($p['firstName'] ?? ''); $last = $this->t($p['lastName'] ?? '');
        $email = strtolower($this->t($p['email'] ?? '')); $role = (string) ($p['role'] ?? '');
        $department = $this->t($p['department'] ?? ''); $password = (string) ($p['password'] ?? '');
        $status = (string) ($p['account_status'] ?? 'active');

        if ($first === '' || $last === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! in_array($role, $roles, true) || ! in_array($status, ['active', 'inactive'], true)) {
            return $this->fail('Enter a name, a valid email and a role you are allowed to assign.');
        }
        if ($department !== '' && ! $this->db->table('departments')->where('name', $department)->countAllResults()) {
            return $this->fail('Choose a valid department.');
        }
        if ($password !== '' && (strlen($password) < 12 || strlen($password) > 72)) {
            return $this->fail('Temporary passwords must be 12 to 72 characters.');
        }
        $dupe = $this->db->table('users')->where('email', $email)->where('id !=', $id)->countAllResults();
        if ($dupe) {
            return $this->fail('That email is already used by another account.');
        }

        $set = [
            'first_name' => $first, 'middle_name' => $this->t($p['middleName'] ?? '') ?: null, 'last_name' => $last, 'email' => $email,
            'phone' => $this->t($p['phone'] ?? ''), 'address' => $this->t($p['address'] ?? ''), 'department' => $department ?: null,
            'role' => $role, 'account_status' => $status, 'updated_at' => $this->now(),
        ];

        if ($id === 0) {
            $username = $this->t($p['username'] ?? '');
            $birthday = \DateTime::createFromFormat('Y-m-d', (string) ($p['birthday'] ?? ''));
            if (strlen($username) < 3 || ! preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username) || ! $birthday || $password === '') {
                return $this->fail('Username (3+ letters/numbers), birthdate and a temporary password are required.');
            }
            if ($this->db->table('users')->where('username', $username)->countAllResults()) {
                return $this->fail('That username is taken.');
            }
            $gender = in_array($p['gender'] ?? '', ['female', 'male', 'non-binary', 'prefer-not-to-say'], true) ? $p['gender'] : 'prefer-not-to-say';
            $this->db->table('users')->insert($set + [
                'username' => $username, 'birthday' => $birthday->format('Y-m-d'), 'gender' => $gender,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'must_change_password' => 1, 'created_at' => $this->now(),
            ]);
            $this->audit('employee_created', 'users', (int) $this->db->insertID(), $role);

            return $this->ok('Employee account created. They must change the temporary password at first sign-in.');
        }

        $target = $this->db->table('users')->where('id', $id)->where('role !=', 'customer')->get()->getRowArray();
        if (! $target) {
            return $this->fail('Employee not found.');
        }
        $targetIsAdmin = in_array($target['role'], self::ADMIN, true);
        if ($targetIsAdmin && ! $isAdmin) {
            return $this->fail('Only an administrator can edit an administrator account.');
        }
        if ($id === (int) $this->me['id'] && ($role !== $target['role'] || $status !== 'active')) {
            return $this->fail('You cannot change your own role or deactivate yourself.');
        }
        if ($targetIsAdmin && (! in_array($role, self::ADMIN, true) || $status !== 'active')) {
            $others = $this->db->table('users')->whereIn('role', self::ADMIN)->where('account_status', 'active')->where('id !=', $id)->countAllResults();
            if ($others < 1) {
                return $this->fail('At least one active administrator must remain.');
            }
        }
        if ($this->t($p['phone'] ?? '') === '') { unset($set['phone']); }
        if ($this->t($p['address'] ?? '') === '') { unset($set['address']); }
        if ($password !== '') {
            $set['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $set['must_change_password'] = 1;
        }
        $this->db->table('users')->where('id', $id)->update($set);
        $this->audit('employee_updated', 'users', $id, $role . '/' . $status);

        return $this->ok('Employee account saved.');
    }

    private function actBlog()
    {
        if (! $this->has(self::ADMIN)) {
            return $this->deny();
        }
        $title = $this->t($this->request->getPost('title')); $summary = $this->t($this->request->getPost('summary'));
        if ($title === '' || $summary === '' || mb_strlen($title) > 180) {
            return $this->fail('Enter a headline and the update text.');
        }
        $this->db->table('blog_posts')->insert(['title' => $title, 'summary' => $summary, 'published_at' => $this->now(), 'author_id' => $this->me['id']]);
        $this->audit('blog_published', 'blog_posts', (int) $this->db->insertID(), $title);

        return $this->ok('Update published.');
    }

    private function actPublishTestimonial()
    {
        if (! $this->has(self::ADMIN)) {
            return $this->deny();
        }
        $id = (int) $this->request->getPost('testimonial_id');
        $this->db->table('testimonials')->where('id', $id)->update(['approved' => $this->request->getPost('approved') ? 1 : 0]);
        $this->audit('testimonial_review', 'testimonials', $id, null);

        return $this->ok('Review saved.');
    }

    /* ---------------------------------------------------------------- helpers */

    /** Loads the signed-in user fresh from the DB so role/status changes apply immediately. */
    private function loadUser(): bool
    {
        $sid = (int) (session('user.id') ?? 0);
        if (! $sid) {
            return false;
        }
        $row = $this->db->table('users')->where('id', $sid)->get()->getRowArray();
        if (! $row || $row['account_status'] !== 'active') {
            session()->destroy();
            return false;
        }
        $row['name'] = $row['first_name'];
        $this->me = $row;

        return true;
    }

    private function has(array $roles): bool
    {
        return in_array($this->me['role'] ?? '', $roles, true);
    }

    private function assigned(int $bookingId): bool
    {
        return $this->db->table('technician_assignments')->where(['booking_id' => $bookingId, 'technician_id' => $this->me['id']])->countAllResults() > 0;
    }

    private function notify(int $userId, string $title, string $message, string $type = 'update'): void
    {
        $this->db->table('notifications')->insert([
            'user_id' => $userId, 'title' => $title, 'message' => $message, 'type' => $type, 'sent_at' => $this->now(),
            'delivery_status' => 'sent', 'created_by' => $this->me['id'] ?? null, 'created_at' => $this->now(),
        ]);
    }

    private function audit(string $action, string $entity, ?int $entityId, ?string $details): void
    {
        $this->db->table('audit_logs')->insert([
            'actor_id' => $this->me['id'] ?? null, 'action' => $action, 'entity' => $entity, 'entity_id' => $entityId ?: null,
            'details' => $details, 'created_at' => $this->now(),
        ]);
    }

    private function t($v): string { return trim((string) $v); }
    private function now(): string { return date('Y-m-d H:i:s'); }

    private function dt($v): ?string
    {
        $v = trim((string) $v);
        $ts = $v === '' ? false : strtotime($v);

        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    private function ok(string $message)   { return redirect()->to(site_url('dashboard'))->with('status', $message); }
    private function fail(string $message) { return redirect()->to(site_url('dashboard'))->with('status', $message)->with('errors', []); }
    private function deny()                { return $this->fail('Your role is not permitted to do that.'); }
    private function toSignIn()            { return redirect()->to(site_url('account?mode=signin'))->with('status', 'Please sign in to continue.'); }
}
