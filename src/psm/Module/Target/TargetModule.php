<?php

/**
 * DFS Monitor — Notification targets module.
 *
 * This file is part of DFS Monitor, a fork of PHP Server Monitor (GPL-3.0-or-later).
 */

namespace psm\Module\Target;

use psm\Module\ModuleInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class TargetModule implements ModuleInterface
{
    public function load(ContainerBuilder $container)
    {
    }

    public function getControllers()
    {
        return array(
            'target' => __NAMESPACE__ . '\Controller\TargetController',
        );
    }
}
