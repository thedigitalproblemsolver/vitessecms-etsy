<?php declare(strict_types=1);

namespace VitesseCms\Etsy\Listeners;

use Phalcon\Events\Manager;
use VitesseCms\Etsy\Listeners\Admin\AdminMenuListener;

class InitiateAdminListeners
{
    public static function setListeners(Manager $eventsManager): void
    {
        $eventsManager->attach('adminMenu', new AdminMenuListener());
    }
}
