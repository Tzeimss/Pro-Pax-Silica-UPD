<?php

namespace Config;

use CodeIgniter\Config\BasePaths;

class Paths extends BasePaths
{
    public $systemDirectory = __DIR__ . '/../../vendor/codeigniter4/framework/system';
    public $appDirectory = __DIR__ . '/..';
    public $writableDirectory = __DIR__ . '/../../writable';
    public $testsDirectory = __DIR__ . '/../../tests';
    public $viewDirectory = __DIR__ . '/../Views';
}
