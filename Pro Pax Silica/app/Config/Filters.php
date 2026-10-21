<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use CodeIgniter\Filters\CSRF;

class Filters extends BaseConfig
{
    public array $aliases = ['csrf' => CSRF::class];
    public array $globals = ['before' => ['csrf'], 'after' => []];
    public array $methods = [];
    public array $filters = [];
}
