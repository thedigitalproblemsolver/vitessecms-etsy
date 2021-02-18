<?php declare(strict_types=1);

namespace VitesseCms\Etsy\Controllers;

use VitesseCms\Admin\AbstractAdminController;
use VitesseCms\Content\Models\Item;
use VitesseCms\Export\Helpers\EtsyExportHelper;
use VitesseCms\Export\Models\ExportType;
use VitesseCms\Language\Models\Language;

class ListingController extends AbstractAdminController
{
    public function syncAction(): void
    {
        $datagroup = $this->setting->get('ETSY_LISTING_DATAGROUP');

        ExportType::setFindValue('type', EtsyExportHelper::class);
        ExportType::setFindValue('datagroup', $datagroup);
        $etsyExports = ExportType::findAll();
        if ($etsyExports) :
            $excludeFromExport = [];
            foreach ($etsyExports as $etsyExport) :
                $excludeFromExport[] = (string)$etsyExport->getId();
            endforeach;
            Item::setFindValue('excludeFromExport', ['$nin' => $excludeFromExport]);
        endif;
        Item::addFindOrder('etsyLastSyncDate', 1);
        Item::setFindValue('datagroup', $datagroup);
        Item::setFindValue('outOfStock', ['$in' => ['', null, false]]);
        $item = Item::findFirst();

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

        $parentItem = Item::findById($item->_('parentId'));
        echo '<pre>Parsing : '.$parentItem->_('name').' '.$item->_('name').'<br />';

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
                    'Etsy listing '.$item->_('etsyId').' for <b>'.$item->_('name').'</b> created.'
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
                                $this->config->get('uploadDir').$image,
                                (int)$item->_('etsyId')
                            );
                        endforeach;
                    endforeach;
                else :
                    $this->etsy->addImageToListing(
                        Di::getDefault()->get('config')->get('uploadDir').$item->_('firstImage'),
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
                $this->log->write(
                    $item->getId(),
                    Item::class,
                    'Etsy listing '.$item->_('etsyId').' for <b>'.$item->_('name').'</b> stock updated.'
                );
            endif;
            var_dump($return);

            foreach (Language::findAll() as $language) :
                if(isset($etsyLanguages[$language->_('short')])) :
                    $this->etsy->updateListingTranslation($item, $language->_('short'));
                endif;
            endforeach;
        endif;

        $item->set('etsyLastSyncDate', time())->save();
        die();
    }
}
