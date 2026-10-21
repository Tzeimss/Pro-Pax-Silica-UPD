# Sun Son Solar

**Live website:** https://tzeimss.github.io/Pro-Pax-Silica/

Sun Son Solar introduces residential solar products and services. The static homepage at the repository root preserves the website design and is served by GitHub Pages.

## Complete service and management system

The CodeIgniter 4 project is in [`Pro Pax Silica/`](Pro%20Pax%20Silica/README.md). It includes customer booking, staff and technician dashboards, scheduling, GPS site check-in, service reports, notifications, support tickets, inventory, quotations, payment records and receipts, analytics, audit logs, and database backup/recovery. The folder README includes setup and first-administrator steps.

To run it locally, open that folder and follow its README:

```sh
cd "Pro Pax Silica"
composer install
php spark migrate
php spark serve
```

Configure the database in `Pro Pax Silica/.env` first. GitHub Pages only serves static files; the management features require running the CodeIgniter app on a PHP-capable server with MySQL or MariaDB.

## Team

Pro Pax Silica
