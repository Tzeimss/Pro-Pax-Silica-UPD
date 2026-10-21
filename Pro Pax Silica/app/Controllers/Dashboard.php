<?php

namespace App\Controllers;

use App\Models\UserModel;
use CodeIgniter\HTTP\RedirectResponse;

class Dashboard extends BaseController
{
    private function user(): ?array { return session('user') ?: null; }
    private function allowed(array $roles): bool { return in_array($this->user()['role'] ?? '', $roles, true); }
    private function db() { return db_connect(); }
    private function audit(string $action, string $entity, ?int $id = null, string $details = ''): void
    {
        $this->db()->table('audit_logs')->insert(['actor_id' => $this->user()['id'], 'action' => $action, 'entity' => $entity, 'entity_id' => $id, 'details' => $details, 'created_at' => date('Y-m-d H:i:s')]);
    }

    public function index()
    {
        if (!$this->user()) return redirect()->to(site_url('account?mode=signin'))->with('status', 'Please sign in to continue.');
        $role = $this->user()['role']; $id = (int) $this->user()['id']; $db = $this->db();
        $data = ['role' => $role, 'user' => $this->user(), 'status' => session()->getFlashdata('status'), 'errors' => session()->getFlashdata('errors') ?? [], 'services' => $db->table('services')->groupStart()->where('available', 1)->orWhereIn('available', $role==='customer'?[1]:[0,1])->groupEnd()->get()->getResultArray(), 'products' => $db->table('products')->groupStart()->where('available', 1)->orWhereIn('available', $role==='customer'?[1]:[0,1])->groupEnd()->get()->getResultArray()];
        $notifications = $db->table('notifications')->groupStart()->where('user_id', $id)->orWhere('user_id', null)->groupEnd()->orderBy('created_at', 'DESC')->get()->getResultArray();
        $data['notifications'] = $notifications;
        $data['announcements'] = $db->table('announcements')->whereIn('audience', $role === 'customer' ? ['customers','everyone'] : ['employees','everyone'])->orderBy('created_at', 'DESC')->get()->getResultArray();
        if ($role === 'customer') {
            $data['bookings'] = $db->table('bookings b')->select('b.*,s.name service_name')->join('services s','s.id=b.service_id')->where('b.customer_id',$id)->orderBy('b.requested_at','DESC')->get()->getResultArray();
            $data['payments'] = $db->table('payments')->where('customer_id',$id)->orderBy('paid_at','DESC')->get()->getResultArray();
            $data['receipts'] = $db->table('receipts r')->select('r.*,p.booking_id,p.amount,p.paid_at')->join('payments p','p.id=r.payment_id')->where('p.customer_id',$id)->orderBy('r.issued_at','DESC')->get()->getResultArray();
            $data['tickets'] = $db->table('support_tickets')->where('customer_id',$id)->orderBy('created_at','DESC')->get()->getResultArray();
            $data['quotes'] = $db->table('quotations')->where('customer_id',$id)->orderBy('created_at','DESC')->get()->getResultArray();
        } elseif ($role === 'technician') {
            $data['bookings'] = $db->table('bookings b')->select('b.*,s.name service_name,u.first_name,u.last_name')->join('services s','s.id=b.service_id')->join('technician_assignments a','a.booking_id=b.id')->join('users u','u.id=b.customer_id')->where('a.technician_id',$id)->orderBy('b.scheduled_at','ASC')->get()->getResultArray();
        } else {
            if (!in_array($role, ['administrator','admin','hr','dispatch','accounting','customer_service','it','marketing','sales'], true)) return redirect()->to(site_url('/'));
            $data['bookings'] = $db->table('bookings b')->select('b.*,s.name service_name,u.first_name,u.last_name,u.phone customer_phone')->join('services s','s.id=b.service_id')->join('users u','u.id=b.customer_id')->orderBy('b.requested_at','DESC')->get()->getResultArray();
            $data['employees'] = $db->table('users')->where('role !=','customer')->orderBy('last_name')->get()->getResultArray();
            $data['customers'] = $db->table('users')->where('role','customer')->orderBy('last_name')->get()->getResultArray();
            $data['technicians'] = $db->table('users')->where('role','technician')->where('account_status','active')->get()->getResultArray();
            $data['tickets'] = $db->table('support_tickets t')->select('t.*,u.first_name,u.last_name')->join('users u','u.id=t.customer_id')->orderBy('t.created_at','DESC')->get()->getResultArray();
            $data['quotes'] = $db->table('quotations q')->select('q.*,u.first_name,u.last_name')->join('users u','u.id=q.customer_id')->orderBy('q.created_at','DESC')->get()->getResultArray();
            $data['payments'] = $db->table('payments')->orderBy('paid_at','DESC')->get()->getResultArray();
            $data['reports'] = $db->table('service_reports r')->select('r.*,u.first_name,u.last_name,b.address')->join('users u','u.id=r.technician_id')->join('bookings b','b.id=r.booking_id')->orderBy('r.created_at','DESC')->get()->getResultArray();
            $data['checkins'] = $db->table('attendance_checkins c')->select('c.*,u.first_name,u.last_name')->join('users u','u.id=c.technician_id')->orderBy('c.checked_in_at','DESC')->get()->getResultArray();
            $data['audit'] = $db->table('audit_logs a')->select('a.*,u.first_name,u.last_name')->join('users u','u.id=a.actor_id','left')->orderBy('a.created_at','DESC')->limit(30)->get()->getResultArray();
            $data['metrics'] = ['customers'=>(int)$db->table('users')->where('role','customer')->countAllResults(), 'employees'=>(int)$db->table('users')->where('role !=','customer')->countAllResults(), 'pending'=>(int)$db->table('bookings')->where('status','Pending')->countAllResults(), 'upcoming'=>(int)$db->table('bookings')->whereIn('status',['Approved','Scheduled'])->countAllResults(), 'completed'=>(int)$db->table('bookings')->where('status','Completed')->countAllResults(), 'technicians'=>(int)$db->table('users')->where('role','technician')->where('account_status','active')->countAllResults(), 'revenue'=>(float)$db->table('payments')->selectSum('amount')->where('status','recorded')->get()->getRow()->amount];
            $data['serviceMetrics']=$db->query('SELECT s.id,s.name,COUNT(DISTINCT b.id) bookings,COALESCE((SELECT SUM(p.amount) FROM payments p JOIN bookings bp ON bp.id=p.booking_id WHERE bp.service_id=s.id),0) revenue FROM services s LEFT JOIN bookings b ON b.service_id=s.id GROUP BY s.id,s.name ORDER BY bookings DESC')->getResultArray();
            $data['customerMetrics']=$db->table('users u')->select('u.first_name,u.last_name,COUNT(b.id) bookings')->join('bookings b','b.customer_id=u.id','left')->where('u.role','customer')->groupBy('u.id')->orderBy('bookings','DESC')->limit(8)->get()->getResultArray();
        }
        $data['profile'] = (new UserModel())->find($id);
        $data['departments'] = $db->table('departments')->orderBy('name')->get()->getResultArray();
        $data['testimonials'] = $db->table('testimonials')->orderBy('created_at','DESC')->get()->getResultArray();
        $data['posts'] = $db->table('blog_posts')->orderBy('published_at','DESC')->get()->getResultArray();
        return view('dashboard', $data);
    }

    public function action(): RedirectResponse
    {
        if (!$this->user()) return redirect()->to(site_url('account?mode=signin'));
        $action = (string) $this->request->getPost('action'); $db = $this->db(); $id = (int) $this->user()['id']; $post = $this->request->getPost();
        if (!empty($this->user()['must_change_password']) && $action !== 'password') return $this->back('Change your temporary password before using the dashboard.');
        $admin = ['administrator','admin']; $staff = array_merge($admin,['hr','dispatch','accounting','customer_service','it','marketing','sales']);
        try {
            switch ($action) {
                case 'book':
                    if ($this->user()['role'] !== 'customer') throw new \RuntimeException('Customer account required.');
                    if(trim($post['address']??'')==='' || mb_strlen(trim($post['address']))>255) throw new \RuntimeException('Enter a service address up to 255 characters.');
                    $service = $db->table('services')->where('id',(int)$post['service_id'])->where('available',1)->get()->getRowArray();
                    if (!$service) throw new \RuntimeException('Please choose an available service.');
                    $requestedTime=null; if(!empty($post['scheduled_at'])){$time=strtotime($post['scheduled_at']);if($time===false||$time<time())throw new \RuntimeException('Choose a valid future preferred appointment time.');$requestedTime=date('Y-m-d H:i:s',$time);}
                    $db->table('bookings')->insert(['customer_id'=>$id,'service_id'=>(int)$service['id'],'requested_at'=>date('Y-m-d H:i:s'),'scheduled_at'=>$requestedTime,'address'=>trim($post['address']??''),'notes'=>trim($post['notes']??''),'status'=>'Pending','quoted_amount'=>$service['price']]);
                    $this->audit('booking requested','booking',(int)$db->insertID()); $this->notify($id,'Booking request received','Your '.$service['name'].' request is pending review.','booking');
                    return $this->back('Your service request has been submitted.');
                case 'booking_status':
                    if (!$this->allowed($staff) && !$this->allowed(['technician'])) throw new \RuntimeException('You do not have permission.');
                    $bookingId=(int)$post['booking_id']; $booking=$db->table('bookings')->where('id',$bookingId)->get()->getRowArray(); if(!$booking) throw new \RuntimeException('Booking not found.');
                    if ($this->user()['role']==='technician') { if(!in_array($post['status'],['In Progress','Completed'],true) || !$db->table('technician_assignments')->where(['booking_id'=>$bookingId,'technician_id'=>$id])->countAllResults()) throw new \RuntimeException('You can only update your assigned job.'); }
                    elseif(!$this->allowed(array_merge($admin,['dispatch']))) throw new \RuntimeException('Dispatch or an administrator must update bookings.');
                    $status=in_array($post['status'],['Approved','Declined','Scheduled','In Progress','Completed','Cancelled'],true)?$post['status']:'Pending'; $update=['status'=>$status];
                    if(!empty($post['scheduled_at'])) { $time=strtotime($post['scheduled_at']); if($time===false || $time<time())throw new \RuntimeException('Choose a valid future appointment time.'); $start=date('Y-m-d H:i:s',$time); $service=$db->table('bookings b')->select('s.duration_minutes')->join('services s','s.id=b.service_id')->where('b.id',$bookingId)->get()->getRowArray(); $end=date('Y-m-d H:i:s',$time+(int)$service['duration_minutes']*60); $assigned=$db->table('technician_assignments')->where('booking_id',$bookingId)->get()->getResultArray(); foreach($assigned as $assignment){$overlap=$db->query("SELECT b.id FROM technician_assignments a JOIN bookings b ON b.id=a.booking_id JOIN services s ON s.id=b.service_id WHERE a.technician_id=? AND b.id<>? AND b.status IN ('Approved','Scheduled','In Progress') AND b.scheduled_at < ? AND DATE_ADD(b.scheduled_at, INTERVAL s.duration_minutes MINUTE) > ? LIMIT 1",[$assignment['technician_id'],$bookingId,$end,$start])->getNumRows(); if($overlap)throw new \RuntimeException('Schedule conflict: an assigned technician has another job during that time.'); } $update['scheduled_at']=$start; }
                    if(isset($post['quoted_amount']) && $this->allowed(array_merge($admin,['sales']))) $update['quoted_amount']=max(0,(float)$post['quoted_amount']);
                    $db->table('bookings')->where('id',$bookingId)->update($update); $this->audit('booking status changed','booking',$bookingId,$status); $this->notify((int)$booking['customer_id'],'Booking update','Your booking is now '.$status.'.','booking');
                    return $this->back('Booking updated.');
                case 'cancel_booking':
                    if($this->user()['role']!=='customer') throw new \RuntimeException('Customer account required.'); $bookingId=(int)$post['booking_id']; $booking=$db->table('bookings')->where(['id'=>$bookingId,'customer_id'=>$id])->get()->getRowArray(); if(!$booking || !in_array($booking['status'],['Pending','Approved','Scheduled'],true)) throw new \RuntimeException('This booking can no longer be cancelled.'); $db->table('bookings')->where('id',$bookingId)->update(['status'=>'Cancelled']); $this->audit('booking cancelled','booking',$bookingId); return $this->back('Booking cancelled.');
                case 'quote_request':
                    if($this->user()['role']!=='customer') throw new \RuntimeException('Customer account required.'); if(trim($post['items']??'')==='')throw new \RuntimeException('Describe the products or solar project you would like quoted.'); $db->table('quotations')->insert(['customer_id'=>$id,'items'=>trim($post['items']),'amount'=>0,'status'=>'Requested','created_at'=>date('Y-m-d H:i:s')]); $this->audit('quotation requested','quotation',(int)$db->insertID()); return $this->back('Your quote request has been sent to the team.');
                case 'assign_technician':
                    if (!$this->allowed(array_merge($admin,['dispatch']))) throw new \RuntimeException('Dispatch or an administrator must assign technicians.');
                    $bookingId=(int)$post['booking_id']; $techId=(int)$post['technician_id']; if(empty($post['scheduled_at'])) throw new \RuntimeException('Choose a date and time before assigning a technician.'); $scheduledTime=strtotime($post['scheduled_at']); if($scheduledTime===false || $scheduledTime<time())throw new \RuntimeException('Choose a valid future appointment time.');
                    $technician=$db->table('users')->where(['id'=>$techId,'role'=>'technician','account_status'=>'active'])->get()->getRowArray(); if(!$technician) throw new \RuntimeException('Choose an active technician account.');
                    $service=$db->table('bookings b')->select('s.duration_minutes')->join('services s','s.id=b.service_id')->where('b.id',$bookingId)->get()->getRowArray(); if(!$service) throw new \RuntimeException('Booking not found.'); $start=date('Y-m-d H:i:s',$scheduledTime); $end=date('Y-m-d H:i:s',$scheduledTime+(int)$service['duration_minutes']*60);
                    $conflict=$db->query("SELECT a.id FROM technician_assignments a JOIN bookings b ON b.id=a.booking_id JOIN services s ON s.id=b.service_id WHERE a.technician_id=? AND b.id<>? AND b.status IN ('Approved','Scheduled','In Progress') AND b.scheduled_at < ? AND DATE_ADD(b.scheduled_at, INTERVAL s.duration_minutes MINUTE) > ? LIMIT 1",[$techId,$bookingId,$end,$start])->getNumRows();
                    if($conflict) throw new \RuntimeException('This technician already has a job at that time.');
                    $db->table('technician_assignments')->ignore(true)->insert(['booking_id'=>$bookingId,'technician_id'=>$techId,'assigned_by'=>$id,'created_at'=>date('Y-m-d H:i:s')]);
                    $update=['status'=>'Scheduled']; if(!empty($post['scheduled_at'])) $update['scheduled_at']=date('Y-m-d H:i:s',strtotime($post['scheduled_at'])); $db->table('bookings')->where('id',$bookingId)->update($update); $booking=$db->table('bookings')->where('id',$bookingId)->get()->getRowArray();
                    $this->notify($techId,'New assigned job','A service job has been assigned to you.','job'); $this->notify((int)$booking['customer_id'],'Service schedule confirmed','Your service appointment is scheduled for '.$update['scheduled_at'].'.','booking'); $this->audit('technician assigned','booking',$bookingId,'technician='.$techId); return $this->back('Technician assigned and schedule saved.');
                case 'checkin':
                    if($this->user()['role']!=='technician') throw new \RuntimeException('Technician account required.'); $bookingId=(int)$post['booking_id'];
                    if(!$db->table('technician_assignments')->where(['booking_id'=>$bookingId,'technician_id'=>$id])->countAllResults()) throw new \RuntimeException('This job is not assigned to you.'); if($db->table('attendance_checkins')->where(['booking_id'=>$bookingId,'technician_id'=>$id])->countAllResults())throw new \RuntimeException('You have already checked in for this job.');
                    $db->table('attendance_checkins')->insert(['booking_id'=>$bookingId,'technician_id'=>$id,'checked_in_at'=>date('Y-m-d H:i:s'),'latitude'=>is_numeric($post['latitude']??null)?$post['latitude']:null,'longitude'=>is_numeric($post['longitude']??null)?$post['longitude']:null,'note'=>substr(trim($post['note']??''),0,255)]); $this->audit('site check-in','booking',$bookingId); return $this->back('Check-in recorded.');
                case 'service_report':
                    if($this->user()['role']!=='technician') throw new \RuntimeException('Technician account required.'); $bookingId=(int)$post['booking_id']; if(!$db->table('technician_assignments')->where(['booking_id'=>$bookingId,'technician_id'=>$id])->countAllResults()) throw new \RuntimeException('This job is not assigned to you.');
                    $db->table('service_reports')->insert(['booking_id'=>$bookingId,'technician_id'=>$id,'summary'=>trim($post['summary']??''),'equipment_problem'=>trim($post['equipment_problem']??''),'customer_concern'=>trim($post['customer_concern']??''),'created_at'=>date('Y-m-d H:i:s')]); $this->audit('service report submitted','booking',$bookingId); return $this->back('Service report sent to operations.');
                case 'profile':
                    $rules=['firstName'=>'required|max_length[100]','lastName'=>'required|max_length[100]','birthday'=>'required|valid_date[Y-m-d]','gender'=>'required|in_list[female,male,non-binary,prefer-not-to-say]','phone'=>'required|max_length[32]','address'=>'required|max_length[255]']; if(!$this->validateData($post,$rules)) return $this->back('Please check the profile fields.',$this->validator->getErrors());
                    (new UserModel())->update($id,['first_name'=>trim($post['firstName']),'middle_name'=>trim($post['middleName']??''),'last_name'=>trim($post['lastName']),'birthday'=>$post['birthday'],'gender'=>$post['gender'],'phone'=>trim($post['phone']),'address'=>trim($post['address']),'preferences'=>trim($post['preferences']??'')]); $sessionUser=$this->user(); $sessionUser['name']=trim($post['firstName']); session()->set('user',$sessionUser); return $this->back('Profile updated.');
                case 'password':
                    $user=(new UserModel())->find($id); $mustChange=!empty($this->user()['must_change_password']); if(!$mustChange && !password_verify($post['current_password']??'',$user['password_hash'])) throw new \RuntimeException('Current password is incorrect.'); if(strlen($post['new_password']??'')<8 || $post['new_password']!==($post['confirm_password']??'')) throw new \RuntimeException('Use at least 8 characters and make both new passwords match.'); (new UserModel())->update($id,['password_hash'=>password_hash($post['new_password'],PASSWORD_DEFAULT),'must_change_password'=>0]); $sessionUser=$this->user(); $sessionUser['must_change_password']=0; session()->set('user',$sessionUser); $this->audit('password changed','user',$id); return $this->back('Password changed.');
                case 'employee':
                    if(!$this->allowed(array_merge($admin,['hr']))) throw new \RuntimeException('HR or administrator access required.');
                    $target=(int)($post['user_id']??0); $employeeRoles=['hr','dispatch','technician','accounting','customer_service','it','marketing','sales']; if($this->allowed($admin))$employeeRoles[]='administrator'; $role=in_array($post['role']??'', $employeeRoles,true)?$post['role']:'technician'; if(!$db->table('departments')->where('name',trim($post['department']??''))->countAllResults())throw new \RuntimeException('Choose a listed employee department.');
                    if($target){ $user=(new UserModel())->find($target); if(!$user || $user['role']==='customer') throw new \RuntimeException('Employee account not found.'); $changes=['first_name'=>trim($post['firstName']),'middle_name'=>trim($post['middleName']??''),'last_name'=>trim($post['lastName']),'phone'=>trim($post['phone']??''),'address'=>trim($post['address']??''),'role'=>$role,'department'=>trim($post['department']??''),'account_status'=>($post['account_status']??'active')==='inactive'?'inactive':'active']; if(trim($post['email']??'')!=='')$changes['email']=strtolower(trim($post['email'])); if(trim($post['password']??'')!==''){if(strlen($post['password'])<12)throw new \RuntimeException('Temporary password must be at least 12 characters.');$changes['password_hash']=password_hash($post['password'],PASSWORD_DEFAULT);$changes['must_change_password']=1;} (new UserModel())->update($target,$changes); $this->audit('employee updated','user',$target,$role); }
                    else { if(strlen($post['password']??'')<12) throw new \RuntimeException('Temporary password must be at least 12 characters.'); $userModel=new UserModel(); $userModel->insert(['first_name'=>trim($post['firstName']),'middle_name'=>trim($post['middleName']??''),'last_name'=>trim($post['lastName']),'birthday'=>$post['birthday']??date('Y-m-d'),'gender'=>$post['gender']??'prefer-not-to-say','email'=>strtolower(trim($post['email'])),'phone'=>trim($post['phone']??''),'address'=>trim($post['address']??''),'username'=>trim($post['username']),'password_hash'=>password_hash($post['password'],PASSWORD_DEFAULT),'role'=>$role,'department'=>trim($post['department']??''),'account_status'=>'active','must_change_password'=>1]); $this->audit('employee created','user',(int)$userModel->getInsertID(),$role); }
                    return $this->back('Employee account saved.');
                case 'customer_status':
                    if(!$this->allowed(array_merge($admin,['hr'])))throw new \RuntimeException('HR or administrator access required.'); $customerId=(int)$post['customer_id']; $customer=(new UserModel())->find($customerId); if(!$customer || $customer['role']!=='customer')throw new \RuntimeException('Customer account not found.'); $status=($post['account_status']??'active')==='inactive'?'inactive':'active'; (new UserModel())->update($customerId,['account_status'=>$status]); $this->audit('customer account status changed','user',$customerId,$status); return $this->back('Customer account status updated.');
                case 'ticket':
                    if($this->user()['role']!=='customer') throw new \RuntimeException('Customer account required.'); if(trim($post['subject']??'')===''||trim($post['message']??'')==='') throw new \RuntimeException('Enter a subject and message.'); $db->table('support_tickets')->insert(['customer_id'=>$id,'subject'=>trim($post['subject']),'message'=>trim($post['message']),'status'=>'Open','created_at'=>date('Y-m-d H:i:s')]); return $this->back('Support request sent.');
                case 'ticket_reply':
                    if(!$this->allowed(array_merge($admin,['customer_service']))) throw new \RuntimeException('Customer Service or administrator access required.'); $db->table('support_tickets')->where('id',(int)$post['ticket_id'])->update(['response'=>trim($post['response']??''),'status'=>($post['status']??'Open')==='Closed'?'Closed':'Responded','updated_at'=>date('Y-m-d H:i:s')]); return $this->back('Ticket response saved.');
                case 'notification':
                    if(!$this->allowed(array_merge($admin,['marketing','hr']))) throw new \RuntimeException('HR, Marketing or administrator access required.'); $audience=$post['audience']??'customers'; $recipients=$audience==='employees'?$db->table('users')->where('role !=','customer')->get()->getResultArray():($audience==='everyone'?$db->table('users')->get()->getResultArray():$db->table('users')->where('role','customer')->get()->getResultArray()); foreach($recipients as $recipient)$this->notify((int)$recipient['id'],trim($post['title']??''),trim($post['message']??''),'announcement'); $this->audit('notification sent','notification',null,'audience='.$audience.'; count='.count($recipients)); return $this->back('Notification queued for '.count($recipients).' accounts.');
                case 'read_notification': $db->table('notifications')->where('id',(int)$post['notification_id'])->where('user_id',$id)->update(['read_at'=>date('Y-m-d H:i:s'),'delivery_status'=>'read']); return $this->back('Notification marked read.');
                case 'announcement':
                    if(!$this->allowed(array_merge($admin,['hr']))) throw new \RuntimeException('HR or administrator access required.'); $db->table('announcements')->insert(['title'=>trim($post['title']??''),'message'=>trim($post['message']??''),'audience'=>in_array($post['audience']??'', ['employees','customers','everyone'],true)?$post['audience']:'employees','created_by'=>$id,'created_at'=>date('Y-m-d H:i:s')]); return $this->back('Announcement posted.');
                case 'catalog':
                    if(!$this->allowed(array_merge($admin,['sales']))) throw new \RuntimeException('Sales or administrator access required.'); $table=$post['catalog_type']==='product'?'products':'services'; $row=['name'=>trim($post['name']??''),'category'=>trim($post['category']??''),'price'=>max(0,(float)($post['price']??0)),'available'=>isset($post['available'])?1:0]; if(array_key_exists('description',$post))$row['description']=trim($post['description']); if($table==='services' && array_key_exists('duration_minutes',$post))$row['duration_minutes']=max(15,(int)$post['duration_minutes']); if($table==='products' && array_key_exists('stock',$post))$row['stock']=max(0,(int)$post['stock']); $itemId=(int)($post['item_id']??0); if($itemId)$db->table($table)->where('id',$itemId)->update($row);else$db->table($table)->insert($row); $this->audit('catalog updated',$table,$itemId?:null,$row['name']); return $this->back('Catalog item saved.');
                case 'availability':
                    if(!$this->allowed(array_merge($admin,['sales']))) throw new \RuntimeException('Sales or administrator access required.'); $table=($post['catalog_type']??'')==='product'?'products':'services'; $itemId=(int)($post['item_id']??0); if(!$itemId)throw new \RuntimeException('Catalog item not found.'); $available=(int)($post['available']??0)===1?1:0; $db->table($table)->where('id',$itemId)->update(['available'=>$available]); $this->audit('catalog availability changed',$table,$itemId,$available?'available':'unavailable'); return $this->back('Availability updated.');
                case 'payment':
                    if(!$this->allowed(array_merge($admin,['accounting']))) throw new \RuntimeException('Accounting or administrator access required.'); $bookingId=(int)$post['booking_id']; $booking=$db->table('bookings')->where('id',$bookingId)->get()->getRowArray(); if(!$booking)throw new \RuntimeException('Booking not found.'); $db->table('payments')->insert(['booking_id'=>$bookingId,'customer_id'=>$booking['customer_id'],'amount'=>max(0,(float)$post['amount']),'reference'=>trim($post['reference']??''),'paid_at'=>date('Y-m-d H:i:s'),'status'=>'recorded','recorded_by'=>$id]); $paymentId=(int)$db->insertID(); $db->table('receipts')->insert(['payment_id'=>$paymentId,'receipt_number'=>'SSS-'.date('Ymd').'-'.str_pad((string)$paymentId,6,'0',STR_PAD_LEFT),'issued_at'=>date('Y-m-d H:i:s')]); $this->audit('payment recorded','booking',$bookingId); return $this->back('Payment recorded and receipt issued.');
                case 'quotation':
                    if(!$this->allowed(array_merge($admin,['sales']))) throw new \RuntimeException('Sales or administrator access required.'); $customerId=(int)$post['customer_id']; $db->table('quotations')->insert(['customer_id'=>$customerId,'booking_id'=>!empty($post['booking_id'])?(int)$post['booking_id']:null,'items'=>trim($post['items']??''),'amount'=>max(0,(float)$post['amount']),'status'=>'Sent','created_by'=>$id,'created_at'=>date('Y-m-d H:i:s')]); $this->notify($customerId,'Your solar quotation is ready','A new quotation is available in your dashboard.','quotation'); return $this->back('Quotation prepared and shared.');
                case 'quotation_update':
                    if(!$this->allowed(array_merge($admin,['sales'])))throw new \RuntimeException('Sales or administrator access required.'); $quoteId=(int)$post['quotation_id']; $quote=$db->table('quotations')->where('id',$quoteId)->get()->getRowArray(); if(!$quote)throw new \RuntimeException('Quotation not found.'); $status=in_array($post['status']??'', ['Requested','Draft','Sent','Accepted','Declined','Expired'],true)?$post['status']:'Draft'; $db->table('quotations')->where('id',$quoteId)->update(['items'=>trim($post['items']??$quote['items']),'amount'=>max(0,(float)($post['amount']??$quote['amount'])),'status'=>$status]); $this->notify((int)$quote['customer_id'],'Quotation updated','Your quotation has been updated and is available in your dashboard.','quotation'); $this->audit('quotation updated','quotation',$quoteId,$status); return $this->back('Quotation updated and customer notified.');
                case 'testimonial':
                    if($this->user()['role']!=='customer')throw new \RuntimeException('Customer account required.'); $db->table('testimonials')->insert(['customer_name'=>$this->user()['name'],'quote'=>trim($post['quote']??''),'approved'=>0,'created_at'=>date('Y-m-d H:i:s')]); return $this->back('Thank you. Your testimonial will appear after review.');
                case 'publish_testimonial':
                    if(!$this->allowed(array_merge($admin,['marketing'])))throw new \RuntimeException('Marketing or administrator access required.'); $db->table('testimonials')->where('id',(int)$post['testimonial_id'])->update(['approved'=>(int)($post['approved']??0)]); return $this->back('Testimonial review saved.');
                case 'blog':
                    if(!$this->allowed(array_merge($admin,['marketing'])))throw new \RuntimeException('Marketing or administrator access required.'); $db->table('blog_posts')->insert(['title'=>trim($post['title']??''),'summary'=>trim($post['summary']??''),'published_at'=>date('Y-m-d H:i:s'),'author_id'=>$id]); return $this->back('News post published.');
                default: throw new \RuntimeException('Unknown action.');
            }
        } catch (\Throwable $e) { log_message('error','Dashboard action failed: {message}', ['message'=>$e->getMessage()]); return $this->back($e instanceof \RuntimeException ? $e->getMessage() : 'We could not save that change. Check the required fields and try again.'); }
    }

    public function backup()
    {
        if (!$this->user() || !$this->allowed(['administrator','admin'])) return redirect()->to(site_url('dashboard'))->with('status','Administrator access required.');
        $tables=['users','departments','services','products','bookings','technician_assignments','attendance_checkins','service_reports','notifications','support_tickets','payments','receipts','quotations','announcements','testimonials','blog_posts','audit_logs','system_settings']; $snapshot=['created_at'=>date(DATE_ATOM),'tables'=>[]]; foreach($tables as $table)$snapshot['tables'][$table]=$this->db()->table($table)->get()->getResultArray();
        return $this->response->download('sun-son-solar-backup-'.date('Ymd-His').'.json',json_encode($snapshot,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    }

    public function restore(): RedirectResponse
    {
        if (!$this->user() || !$this->allowed(['administrator','admin'])) return redirect()->to(site_url('dashboard'))->with('status','Administrator access required.');
        $file=$this->request->getFile('backup');
        if(!$file || !$file->isValid() || $file->getSize()>20*1024*1024) return $this->back('Choose a valid backup file smaller than 20 MB.');
        $snapshot=json_decode(file_get_contents($file->getTempName()),true);
        if(!is_array($snapshot) || !is_array($snapshot['tables']??null)) return $this->back('This is not a valid Sun Son Solar backup.');
        $order=['users','departments','services','products','bookings','technician_assignments','attendance_checkins','service_reports','notifications','support_tickets','payments','receipts','quotations','announcements','testimonials','blog_posts','system_settings','audit_logs']; $db=$this->db(); $db->transBegin();
        try {
            foreach($order as $table) foreach(($snapshot['tables'][$table]??[]) as $row) {
                if(!is_array($row)) continue;
                if($table==='departments') { if(!empty($row['id']) && $db->table($table)->where('id',$row['id'])->countAllResults()) $db->table($table)->where('id',$row['id'])->update($row); elseif(!empty($row['name'])) $db->table($table)->ignore(true)->insert(['name'=>$row['name']]); continue; }
                if($table==='system_settings') { if(empty($row['setting_key'])) continue; if($db->table($table)->where('setting_key',$row['setting_key'])->countAllResults()) $db->table($table)->where('setting_key',$row['setting_key'])->update($row); else $db->table($table)->insert($row); continue; }
                if(empty($row['id'])) continue; $exists=$db->table($table)->where('id',(int)$row['id'])->countAllResults(); if($exists)$db->table($table)->where('id',(int)$row['id'])->update($row); else $db->table($table)->insert($row);
            }
            if(!$db->transStatus()) throw new \RuntimeException('Restore transaction failed.');
            $db->transCommit(); $this->audit('database restored','backup',null,basename($file->getName())); return $this->back('Backup restored. Existing rows with matching IDs were updated.');
        } catch(\Throwable $e) { $db->transRollback(); log_message('error','Backup restore failed: {message}',['message'=>$e->getMessage()]); return $this->back('Restore failed. The transaction was rolled back; check that the backup belongs to this application.'); }
    }

    public function receipt(int $receiptId)
    {
        if (!$this->user()) return redirect()->to(site_url('account?mode=signin'));
        $receipt=$this->db()->table('receipts r')->select('r.receipt_number,r.issued_at,p.amount,p.reference,p.paid_at,p.customer_id,b.address,s.name service_name,u.first_name,u.last_name')->join('payments p','p.id=r.payment_id')->join('bookings b','b.id=p.booking_id')->join('services s','s.id=b.service_id')->join('users u','u.id=p.customer_id')->where('r.id',$receiptId)->get()->getRowArray();
        if(!$receipt || (($this->user()['role']??'')==='customer' && (int)$receipt['customer_id']!==(int)$this->user()['id']) || (($this->user()['role']??'')!=='customer' && !$this->allowed(['administrator','admin','accounting']))) return redirect()->to(site_url('dashboard'))->with('status','Receipt not found or you do not have permission to view it.');
        return view('receipt',['receipt'=>$receipt]);
    }

    private function notify(int $userId, string $title, string $message, string $type): void { $this->db()->table('notifications')->insert(['user_id'=>$userId,'title'=>$title,'message'=>$message,'type'=>$type,'sent_at'=>date('Y-m-d H:i:s'),'delivery_status'=>'sent','created_by'=>$this->user()['id']??null,'created_at'=>date('Y-m-d H:i:s')]); }
    private function back(string $status, array $errors=[]): RedirectResponse { $r=redirect()->to(site_url('dashboard'))->with('status',$status); return $errors ? $r->with('errors',$errors) : $r; }
}
