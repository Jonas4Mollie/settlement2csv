#!/usr/bin/env php
<?php
require_once __DIR__ . '/vendor/autoload.php';
use Symfony\Component\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

$env = new Dotenv();
$env->loadEnv(__DIR__ . '/.env');

$app = new Application('Settlement2CSV', '0.1');
$app->add(new \Fjbender\Settlement2csv\Commands\ConvertCommand());
$app->run();