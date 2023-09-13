<?php
declare(strict_types=1);

namespace VitesseCms\Etsy\Controllers;

use stdClass;
use VitesseCms\Content\Enum\ItemEnum;
use VitesseCms\Content\Models\Item;
use VitesseCms\Content\Repositories\ItemRepository;
use VitesseCms\Core\AbstractControllerFrontend;
use VitesseCms\Database\Models\FindOrder;
use VitesseCms\Database\Models\FindOrderIterator;
use VitesseCms\Database\Models\FindValue;
use VitesseCms\Database\Models\FindValueIterator;
use VitesseCms\Etsy\Enums\SettingsEnum;
use VitesseCms\Export\Enums\ExportTypeEnums;
use VitesseCms\Export\Helpers\EtsyExportHelper;
use VitesseCms\Export\Models\ExportType;
use VitesseCms\Export\Repositories\ExportTypeRepository;
use VitesseCms\Language\Enums\LanguageEnum;
use VitesseCms\Language\Repositories\LanguageRepository;
use VitesseCms\Setting\Enum\SettingEnum;
use VitesseCms\Setting\Services\SettingService;

class ListingController extends AbstractControllerFrontend
{
    private SettingService $settingService;
    private ExportTypeRepository $exportTypeRepository;
    private ItemRepository $itemRepository;
    private LanguageRepository $languageRepository;

    public function OnConstruct()
    {
        parent::onConstruct();

        $this->settingService = $this->eventsManager->fire(SettingEnum::ATTACH_SERVICE_LISTENER, new stdClass());
        $this->exportTypeRepository = $this->eventsManager->fire(
            ExportTypeEnums::GET_REPOSITORY->value,
            new stdClass()
        );
        $this->itemRepository = $this->eventsManager->fire(ItemEnum::GET_REPOSITORY, new stdClass());
        $this->languageRepository = $this->eventsManager(LanguageEnum::GET_REPOSITORY->value, new stdClass());
    }

    public function syncAction(): void
    {
        $datagroup = $this->settingService->get(SettingsEnum::ETSY_LISTING_DATAGROUP->name);
        $etsyExports = $this->exportTypeRepository->findAll(
            new FindValueIterator([
                new FindValue('type', EtsyExportHelper::class),
                new FindValue('datagroup', $datagroup)
            ])
        );
        $findValueIterator = new FindValueIterator([
            new FindValue('datagroup', $datagroup),
            new FindValue('outOfStock', ['$in' => ['', null, false]])
        ]);
        if ($etsyExports) :
            $excludeFromExport = [];
            foreach ($etsyExports as $etsyExport) :
                $excludeFromExport[] = (string)$etsyExport->getId();
            endforeach;
            $findValueIterator->append(new FindValue('excludeFromExport', ['$nin' => $excludeFromExport]));
        endif;

        $item = $this->itemRepository->findFirst(
            $findValueIterator,
            true,
            new FindOrderIterator(new FindOrder('etsyLastSyncDate', 1))
        );

        if ($item) :
            $this->sync($item);
        endif;
    }

    protected function sync(Item $item): void
    {
        $etsyLanguages = [
            'de' => 'de',
            'en' => 'en',
            'es' => 'es',
            'fr' => 'fr',
            'it' => 'it',
            'ja' => 'ja',
            'nl' => 'nl',
            'pl' => 'pl',
            'pt' => 'pt',
            'ru' => 'ru'
        ];

        $parentItem = $this->itemRepository->getById($item->getParentId());
        echo '<pre>Parsing : ' . $parentItem->_('name') . ' ' . $item->_('name') . '<br />';

        if (
            empty($item->_('etsyId'))
            && !empty($parentItem->_('etsyCategoryId'))
            && !empty($parentItem->_('etsyTaxonomyId'))
            && !empty($parentItem->_('etsySizeId'))
        ) :
            $listing = $this->etsy->createListingFromItem($item);
            if ($listing) :
                $item->set('etsyId', $listing->results[0]->listing_id);
                $item->save();

                $this->log->write(
                    $item->getId(),
                    Item::class,
                    'Etsy listing ' . $item->_('etsyId') . ' for <b>' . $item->_('name') . '</b> created.'
                );

                if ($item->_('variations')) :
                    $colorImages = [];
                    foreach ((array)$item->_('variations') as $variation) :
                        if (!isset($colorImages[$variation['color']])) :
                            $colorImages[$variation['color']] = $variation['image'];
                        endif;
                    endforeach;
                    foreach ($colorImages as $colorImage) :
                        foreach ((array)$colorImage as $image) :
                            $this->etsy->addImageToListing(
                                $this->configService->getUploadDir() . $image,
                                (int)$item->_('etsyId')
                            );
                        endforeach;
                    endforeach;
                else :
                    $this->etsy->addImageToListing(
                        $this->configService->getUploadDir() . $item->_('firstImage'),
                        (int)$item->_('etsyId')
                    );
                endif;
            endif;
        endif;

        if (
            !empty($item->_('etsyId'))
            && !empty($parentItem->_('etsyCategoryId'))
            && !empty($parentItem->_('etsyTaxonomyId'))
            && !empty($parentItem->_('etsySizeId'))
        ) :
            //var_dump($this->etsy->getInventory($item->_('etsyId')));
            //die();
            $return = $this->etsy->updateInventoryFromItem($item);
            if ($return !== null) :
                $this->logService->write(
                    $item->getId(),
                    Item::class,
                    'Etsy listing ' . $item->_('etsyId') . ' for <b>' . $item->_('name') . '</b> stock updated.'
                );
            endif;
            var_dump($return);

            foreach ($this->languageRepository->findAll() as $language) :
                if (isset($etsyLanguages[$language->_('short')])) :
                    $this->etsy->updateListingTranslation($item, $language->_('short'));
                endif;
            endforeach;
        endif;

        $item->set('etsyLastSyncDate', time())->save();
        die();
    }
}
