<?php

namespace Config;

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');
$routes->get('account', 'Account::index');
$routes->post('register', 'Account::register');
$routes->post('login', 'Account::login');
$routes->post('logout', 'Account::logout');
$routes->get('dashboard', 'Dashboard::index');
$routes->post('dashboard/action', 'Dashboard::action');
$routes->get('dashboard/backup', 'Dashboard::backup');
$routes->post('dashboard/restore', 'Dashboard::restore');
$routes->get('dashboard/receipt/(:num)', 'Dashboard::receipt/$1');
