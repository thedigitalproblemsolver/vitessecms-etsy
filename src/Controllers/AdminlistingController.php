<?php declare(strict_types=1);

namespace VitesseCms\Etsy\Controllers;

use VitesseCms\Admin\AbstractAdminController;
use VitesseCms\Form\Forms\BaseForm;

class AdminlistingController extends AbstractAdminController
{
    public function rawListingFormAction(): void
    {
        $form = new BaseForm();
        $form->_(
            'text',
            'Esty ID',
            'etsyId',
            [
                'InputType' => 'number',
                'required' => true
            ]
        )->_(
            'submit',
            'Submit'
        );
        $this->view->setVar('content', $form->renderForm('admin/etsy/adminlisting/rawListingDisplay'));

        $this->prepareView();
    }

    public function rawListingDisplayAction(): void
    {
        $this->view->setVar('content', 'Geen Listing ingegeven.');
        if ($this->request->isPost()) :
            $listing = $this->etsy->getListing((int)$this->request->getPost('etsyId'));
            $inventory = $this->etsy->getInventory((int)$this->request->getPost('etsyId'));

            $this->view->setVar(
                'content',
                'Results for listing : ' . $this->request->getPost('etsyId') .
                '<br />
                <b>Listing</b><br /><pre>' .
                print_r($listing, true) .
                '<b>Inventory</b><br /><pre>' .
                print_r($inventory, true) .
                '<a href="' . $this->url->getBaseUri() . 'admin/etsy/adminlisting/rawListingForm">terug</a>
                </pre>'
            );
        endif;

        $this->prepareView();
    }
}
