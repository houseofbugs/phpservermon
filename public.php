<?php

/**
 * PHP Server Monitor / DFS Monitor
 *
 * This file is part of DFS Monitor, a fork of PHP Server Monitor (GPL-3.0-or-later).
 */

namespace {
    define('PSM_PUBLIC_PAGE', true);

    require __DIR__ . '/src/bootstrap.php';

    $router->run('server_status');
}
