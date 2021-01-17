<?php declare(strict_types=1);

namespace VitesseCms\Etsy;

use VitesseCms\Core\AbstractModule;
use VitesseCms\Etsy\Services\EtsyService;
use Phalcon\DiInterface;

class Module extends AbstractModule
{
    public function registerServices(DiInterface $di, string $string = null)
    {
        $di->setShared('etsy', new EtsyService($di->get('setting')));

        parent::registerServices($di, 'Etsy');
    }
}
