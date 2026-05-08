<?php

use Montelibero\BSN\ApplicationContext;

/** @var ApplicationContext $App */
$App = require __DIR__ . '/bootstrap.php';

if (IS_CLI_CONTEXT) {
    $App->syncRequestContext();
} else {
    $App->handleRequest();
}

return $App->Container;
