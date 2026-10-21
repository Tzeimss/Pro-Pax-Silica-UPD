<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateOperations extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            'role' => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'customer'],
            'department' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'account_status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'active'],
            'must_change_password' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'preferences' => ['type' => 'TEXT', 'null' => true],
        ]);

        $tables = [
            'departments' => ['name VARCHAR(80) NOT NULL UNIQUE'],
            'services' => ['name VARCHAR(100) NOT NULL', 'category VARCHAR(80) NOT NULL', 'description TEXT NULL', 'price DECIMAL(12,2) NOT NULL DEFAULT 0', 'duration_minutes INT NOT NULL DEFAULT 120', 'available TINYINT(1) NOT NULL DEFAULT 1'],
            'products' => ['name VARCHAR(140) NOT NULL', 'category VARCHAR(80) NOT NULL', 'description TEXT NULL', 'price DECIMAL(12,2) NOT NULL DEFAULT 0', 'stock INT NOT NULL DEFAULT 0', 'available TINYINT(1) NOT NULL DEFAULT 1'],
            'bookings' => ['customer_id INT UNSIGNED NOT NULL', 'service_id INT UNSIGNED NOT NULL', 'requested_at DATETIME NOT NULL', 'scheduled_at DATETIME NULL', 'address VARCHAR(255) NOT NULL', 'notes TEXT NULL', 'status VARCHAR(24) NOT NULL DEFAULT \'Pending\'', 'quoted_amount DECIMAL(12,2) NULL', 'created_by INT UNSIGNED NULL'],
            'technician_assignments' => ['booking_id INT UNSIGNED NOT NULL', 'technician_id INT UNSIGNED NOT NULL', 'assigned_by INT UNSIGNED NOT NULL', 'UNIQUE KEY uniq_booking_technician (booking_id, technician_id)', 'created_at DATETIME NOT NULL'],
            'attendance_checkins' => ['booking_id INT UNSIGNED NOT NULL', 'technician_id INT UNSIGNED NOT NULL', 'checked_in_at DATETIME NOT NULL', 'latitude DECIMAL(10,7) NULL', 'longitude DECIMAL(10,7) NULL', 'note VARCHAR(255) NULL'],
            'service_reports' => ['booking_id INT UNSIGNED NOT NULL', 'technician_id INT UNSIGNED NOT NULL', 'summary TEXT NOT NULL', 'equipment_problem TEXT NULL', 'customer_concern TEXT NULL', 'created_at DATETIME NOT NULL'],
            'notifications' => ['user_id INT UNSIGNED NULL', 'title VARCHAR(160) NOT NULL', 'message TEXT NOT NULL', 'type VARCHAR(32) NOT NULL DEFAULT \'update\'', 'sent_at DATETIME NULL', 'read_at DATETIME NULL', 'delivery_status VARCHAR(24) NOT NULL DEFAULT \'queued\'', 'created_by INT UNSIGNED NULL'],
            'support_tickets' => ['customer_id INT UNSIGNED NOT NULL', 'subject VARCHAR(180) NOT NULL', 'message TEXT NOT NULL', 'response TEXT NULL', 'status VARCHAR(24) NOT NULL DEFAULT \'Open\'', 'created_at DATETIME NOT NULL', 'updated_at DATETIME NULL'],
            'payments' => ['booking_id INT UNSIGNED NOT NULL', 'customer_id INT UNSIGNED NOT NULL', 'amount DECIMAL(12,2) NOT NULL', 'reference VARCHAR(100) NULL', 'paid_at DATETIME NULL', 'status VARCHAR(24) NOT NULL DEFAULT \'recorded\'', 'recorded_by INT UNSIGNED NULL'],
            'receipts' => ['payment_id INT UNSIGNED NOT NULL', 'receipt_number VARCHAR(80) NOT NULL UNIQUE', 'issued_at DATETIME NOT NULL'],
            'quotations' => ['customer_id INT UNSIGNED NOT NULL', 'booking_id INT UNSIGNED NULL', 'items TEXT NOT NULL', 'amount DECIMAL(12,2) NOT NULL DEFAULT 0', 'status VARCHAR(24) NOT NULL DEFAULT \'Draft\'', 'created_by INT UNSIGNED NULL', 'created_at DATETIME NOT NULL'],
            'announcements' => ['title VARCHAR(160) NOT NULL', 'message TEXT NOT NULL', 'audience VARCHAR(24) NOT NULL DEFAULT \'employees\'', 'created_by INT UNSIGNED NOT NULL', 'created_at DATETIME NOT NULL'],
            'testimonials' => ['customer_name VARCHAR(120) NOT NULL', 'quote TEXT NOT NULL', 'approved TINYINT(1) NOT NULL DEFAULT 0', 'created_at DATETIME NOT NULL'],
            'blog_posts' => ['title VARCHAR(180) NOT NULL', 'summary TEXT NOT NULL', 'published_at DATETIME NULL', 'author_id INT UNSIGNED NULL'],
            'audit_logs' => ['actor_id INT UNSIGNED NULL', 'action VARCHAR(120) NOT NULL', 'entity VARCHAR(80) NOT NULL', 'entity_id INT UNSIGNED NULL', 'details TEXT NULL', 'created_at DATETIME NOT NULL'],
            'system_settings' => ['setting_key VARCHAR(100) NOT NULL UNIQUE', 'setting_value TEXT NOT NULL'],
        ];

        foreach ($tables as $name => $fields) {
            $definitions = ["id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY"];
            foreach ($fields as $field) $definitions[] = $field;
            $hasCreatedAt = false;
            foreach ($fields as $field) if (str_starts_with($field, 'created_at ')) $hasCreatedAt = true;
            if (!$hasCreatedAt && !in_array($name, ['departments', 'system_settings'], true)) $definitions[] = "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP";
            $this->db->query('CREATE TABLE IF NOT EXISTS `' . $name . '` (' . implode(', ', $definitions) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }

        $departments = ['Administration', 'IT', 'Dispatch', 'Accounting', 'HR', 'Marketing', 'Sales', 'Customer Service'];
        foreach ($departments as $department) $this->db->table('departments')->ignore(true)->insert(['name' => $department]);
        $services = [
            ['Solar consultation', 'Consultation', 'Discuss energy needs and project goals.', 0],
            ['System design', 'Design', 'Custom solar system planning.', 150],
            ['Permitting assistance', 'Permitting', 'Permit and utility coordination.', 250],
            ['Solar installation', 'Installation', 'Professional system installation.', 2500],
            ['Maintenance', 'Maintenance', 'Inspection and preventive care.', 120],
            ['Repair', 'Repair', 'Solar equipment troubleshooting and repair.', 180],
            ['Monitoring', 'Monitoring', 'System performance monitoring.', 35],
        ];
        foreach ($services as [$name, $category, $description, $price]) $this->db->table('services')->ignore(true)->insert(['name' => $name, 'category' => $category, 'description' => $description, 'price' => $price]);
        $products = [
            ['Monocrystalline Solar Panel 400W', 'Solar panels', 'High-efficiency rooftop panel.', 320, 20],
            ['Hybrid Inverter 5kW', 'Inverters', 'Hybrid inverter ready for battery storage.', 2400, 8],
            ['Lithium Battery 10kWh', 'Batteries', 'Home energy storage system.', 6500, 5],
            ['Roof Mounting Kit', 'Mounting equipment', 'Weather-resistant panel mounting kit.', 180, 16],
            ['Solar Cable and Connector Set', 'Wires', 'Weather-rated cabling and connectors.', 85, 30],
        ];
        foreach ($products as [$name, $category, $description, $price, $stock]) $this->db->table('products')->ignore(true)->insert(['name' => $name, 'category' => $category, 'description' => $description, 'price' => $price, 'stock' => $stock]);

        $links = [
            ['bookings','customer_id','users'], ['bookings','service_id','services'], ['bookings','created_by','users'],
            ['technician_assignments','booking_id','bookings'], ['technician_assignments','technician_id','users'], ['technician_assignments','assigned_by','users'],
            ['attendance_checkins','booking_id','bookings'], ['attendance_checkins','technician_id','users'], ['service_reports','booking_id','bookings'], ['service_reports','technician_id','users'],
            ['notifications','user_id','users'], ['notifications','created_by','users'], ['support_tickets','customer_id','users'], ['payments','booking_id','bookings'], ['payments','customer_id','users'], ['payments','recorded_by','users'], ['receipts','payment_id','payments'],
            ['quotations','customer_id','users'], ['quotations','booking_id','bookings'], ['quotations','created_by','users'], ['announcements','created_by','users'], ['blog_posts','author_id','users'], ['audit_logs','actor_id','users'],
        ];
        foreach ($links as [$table,$column,$parent]) $this->db->query('ALTER TABLE `' . $table . '` ADD CONSTRAINT `fk_' . $table . '_' . $column . '` FOREIGN KEY (`' . $column . '`) REFERENCES `' . $parent . '` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT');
    }

    public function down()
    {
        foreach (['system_settings','audit_logs','blog_posts','testimonials','announcements','quotations','receipts','payments','support_tickets','notifications','service_reports','attendance_checkins','technician_assignments','bookings','products','services','departments'] as $table) $this->forge->dropTable($table, true);
        $this->forge->dropColumn('users', ['role','department','account_status','must_change_password','preferences']);
    }
}
