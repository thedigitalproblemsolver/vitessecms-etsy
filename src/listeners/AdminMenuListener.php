<?php declare(strict_types=1);

namespace VitesseCms\Etsy\Listeners;

use VitesseCms\Admin\Models\AdminMenu;
use VitesseCms\Admin\Models\AdminMenuNavBarChildren;
use Phalcon\Events\Event;

class AdminMenuListener
{
    public function AddChildren(Event $event, AdminMenu $adminMenu): void
    {
        if (
            $adminMenu->getUser()->getPermissionRole() === 'superadmin'
            && $adminMenu->getSetting()->has('ETSY_CONSUMER_KEY')
        ) :
            $children = new AdminMenuNavBarChildren();
            $children->addChild('Raw Listing', 'admin/etsy/adminlisting/rawListingForm');

            $adminMenu->addDropdown('Etsy', $children);
        endif;
    }
}
