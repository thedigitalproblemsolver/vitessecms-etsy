<?php declare(strict_types=1);

namespace VitesseCms\Etsy;

use Phalcon\Di\DiInterface;
use VitesseCms\Core\AbstractModule;
use VitesseCms\Etsy\Services\EtsyService;

class Module extends AbstractModule
{
    public function registerServices(DiInterface $di, string $string = null)
    {
        $di->setShared('Etsy', new EtsyService($di->get('setting')));

        parent::registerServices($di, 'Etsy');
    }
}
