<?php declare(strict_types=1);

namespace VitesseCms\Etsy\Fields;

use VitesseCms\Datafield\Models\Datafield;
use VitesseCms\Database\AbstractCollection;
use VitesseCms\Datafield\AbstractField;
use VitesseCms\Form\AbstractForm;
use VitesseCms\Form\Models\Attributes;
use VitesseCms\Shop\Enum\SizeAndColorEnum;

class EtsyCategory extends AbstractField
{
    public function buildItemFormElement(
        AbstractForm $form,
        Datafield $datafield,
        Attributes $attributes,
        AbstractCollection $data = null
    )
    {
        $form->addText('Etsy CategoryId', 'etsyCategoryId')
            ->addText('Etsy TaxanomyId', 'etsyTaxonomyId')
            ->addText('Etsy Tags', 'etsyTags', (new Attributes())->setMultilang(true))
            ->addText('Etsy SizeId', 'etsySizeId');

        foreach (SizeAndColorEnum::sizes as $size => $sizeName) :
            $form->addText($size . ' to Etsy Size', 'etsySizeMapper[' . $size . ']');
        endforeach;
    }
}
