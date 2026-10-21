# Sun Son Solar service and management system

The CodeIgniter 4 app in this folder contains the public solar website and the database-backed customer, employee, dispatch, and administration workspace.

## Run locally

Requirements: PHP 8.1+, Composer, MySQL or MariaDB, and PHP extensions `intl`, `mysqli`, `mbstring`, `json`, and `curl`.

1. Open a terminal in this folder and install the app dependencies:

   ```sh
   composer install
   ```

2. Copy `.env.example` to `.env`. Set `app.baseURL` and the database host, database name, username, password, and port. Create the database named in the configuration.

3. Apply the database migrations and start the development server:

   ```sh
   php spark migrate
   php spark serve
   ```

4. Open `http://localhost:8080` and create a customer account. The dashboard is at `/dashboard`.

## First administrator

Employee registration is intentionally disabled. To bootstrap the first administrator, register your own account, then promote that account from your database console using your own email address:

```sql
UPDATE users
SET role = 'administrator', department = 'Administration'
WHERE email = 'YOUR-ACCOUNT-EMAIL' AND role = 'customer';
```

Sign out and sign in again for the new role to take effect. Do not put real passwords or private customer data in source files. Administrators and HR staff can create employee accounts from the dashboard; employees must change their temporary password when they first sign in.

## Features

- Public homepage with solar benefits, products, services, quote and service CTAs, testimonials, FAQs, news, contact/support link, social links, and legal notices. Product display is informational; the site does not process product orders or online payments.
- Customer self-registration, hashed passwords, login/logout, profiles, password updates, preferences, service requests, booking status and cancellation, notifications, payment history, receipts, quotations, support tickets, and testimonials.
- Role-specific workspaces for administrators, HR, dispatch, accounting, customer service, marketing, sales, IT, and technicians.
- HR-managed employee accounts, department assignment, deactivation, announcements, and urgent notices.
- Booking review, weekly calendar, schedule changes, technician assignment, technician availability checks, service progress, and customer notifications.
- Technician job view limited to assigned customer name, service address, service type, and schedule; GPS check-in is permission-based, and technicians can submit work, equipment, and customer concern reports.
- Service and product catalog controls, pricing and availability, inventory quantities, quotation preparation, payment records, analytics, charts, and audit history.
- Admin-only JSON backup and recovery from the dashboard. Restore updates or adds records with matching IDs; it does not delete records absent from the uploaded backup. Store backup files securely because they contain account and service data.

## Notes

- The system uses MySQL/MariaDB and the migrations in `app/Database/Migrations` to create users, services, products, bookings, notifications, reports, receipts, quotations, and related tables with primary and foreign keys.
- Notification delivery is recorded by the application. Actual SMS/email delivery needs an external provider and credentials configured by the deploying organization.
- The social links are generic placeholders. Replace them with Sun Son Solar's official profiles before launch. Add the organization's confirmed telephone, email, and legal language before public deployment.
- For production, point the web server document root to `public/`, use HTTPS, configure a least-privilege database account, secure the `.env` file, and store downloaded backups in access-controlled storage.
