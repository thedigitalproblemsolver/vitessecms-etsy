<?php declare(strict_types=1);

namespace VitesseCms\Etsy\Listeners\Admin;

use Phalcon\Events\Event;
use VitesseCms\Admin\Models\AdminMenu;
use VitesseCms\Admin\Models\AdminMenuNavBarChildren;
use VitesseCms\Setting\Services\SettingService;

class AdminMenuListener
{
    /**
     * @var SettingService
     */
    private $settingService;

    public function __construct(SettingService $settingService)
    {
        $this->settingService = $settingService;
    }

    public function AddChildren(Event $event, AdminMenu $adminMenu): void
    {
        if ($this->settingService->has('ETSY_CONSUMER_KEY')) :
            $children = new AdminMenuNavBarChildren();
            $children->addChild('Raw Listing', 'admin/etsy/adminlisting/rawListingForm');

            $adminMenu->addDropdown('Etsy', $children);
        endif;
    }
}
