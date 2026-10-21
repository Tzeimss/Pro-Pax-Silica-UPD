<?php

use CodeIgniter\Boot;

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);

$pathsPath = realpath(FCPATH . '../app/Config/Paths.php');
if ($pathsPath === false) {
    throw new RuntimeException('Cannot find app/Config/Paths.php.');
}
require $pathsPath;
$paths = new Config\Paths();
require rtrim($paths->systemDirectory, '\\/') . DIRECTORY_SEPARATOR . 'Boot.php';
Boot::bootWeb($paths);
