<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

// The test database's address comes from the environment (compose.dev.yaml, or CI).
new Dotenv()->bootEnv(__DIR__.'/../.env');
