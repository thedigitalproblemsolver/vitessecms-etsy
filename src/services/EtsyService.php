<?php declare(strict_types=1);

namespace VitesseCms\Etsy\Services;

use VitesseCms\Content\Models\Item;
use VitesseCms\Database\Utils\MongoUtil;
use VitesseCms\Setting\Services\SettingService;
use VitesseCms\Spreadshirt\Models\Design;
use Phalcon\Di;
use Phalcon\Exception;

class EtsyService
{
    /**
     * @var \OAuth
     */
    protected $oauth;

    /**
     * @var int
     */
    protected $engine;

    /**
     * @var string
     */
    protected $baseUrl;

    /**
     * @var SettingService
     */
    protected $setting;

    /**
     * @var \oauth_client_class
     */
    protected $oauthClient;

    public function __construct(SettingService $setting)
    {
        $this->baseUrl = 'https://openapi.etsy.com/v2/';
        $this->setting = $setting;

        try {
            $this->setOauth();
        } catch (\Exception $exception) {
            echo $exception->getMessage();
            die();
        }

        try {
            $this->setOauthClient();
        } catch (Exception $exception) {
            echo $exception->getMessage();
            die();
        }
    }

    /**
     * @param Item $item
     *
     * @return mixed
     * @throws \OAuthException
     * @throws \Phalcon\Mvc\Collection\Exception
     */
    public function createListingFromItem(Item $item)
    {
        $clothingCategory = Item::findById($item->_('parentId'));

        if (
            !empty($clothingCategory->_('etsyCategoryId'))
            && !empty($clothingCategory->_('etsyTaxonomyId'))
        ) {
            $params = [
                'title'                => str_replace("'", '', trim($item->_('name'))),
                'description'          => $this->builDescription($item, Di::getDefault()->get('configuration')->getLanguageShort()),
                'price'                => $item->_('price_sale'),
                'shipping_template_id' => $this->setting->get('ETSY_SHIPPING_TEMPLATEID'),
                'category_id'          => (int)$clothingCategory->_('etsyCategoryId'),
                'taxonomy_id'          => (int)$clothingCategory->_('etsyTaxonomyId'),
                'quantity'             => 2,
                'shop_id'              => $this->setting->get('ETSY_SHOP_ID'),
                'who_made'             => 'collective',
                'is_supply'            => true,
                'when_made'            => '2010_2018',
                'language'             => Di::getDefault()->get('configuration')->getLanguageShort(),
            ];

            return $this->fetch('listings', $params);
        }

        return false;
    }

    /**
     * @param string $imagePath
     * @param int $listingId
     *
     * @return mixed
     * @throws \OAuthException
     */
    public function addImageToListing(string $imagePath, int $listingId)
    {
        $mime = mime_content_type($imagePath);

        return $this->fetch_native('listings/'.$listingId.'/images',
            ['@image' => '@'.$imagePath.';type='.$mime]);
    }

    /**
     * @param int $listingId
     *
     * @return mixed
     * @throws \OAuthException
     */
    public function getListing(int $listingId)
    {
        return $this->fetch('listings/'.$listingId, [], OAUTH_HTTP_METHOD_GET);
    }

    /**
     * @param int $listingId
     *
     * @return mixed
     * @throws \OAuthException
     */
    public function getInventory(int $listingId)
    {
        return $this->fetch('listings/'.$listingId.'/inventory', [], OAUTH_HTTP_METHOD_GET);
    }

    /**
     * @param Item $item
     *
     * @return mixed
     * @throws \OAuthException
     */
    public function updateInventoryFromItem(Item $item)
    {
        $products = [];
        if (!empty($item->_('variations'))) :
            $price = $item->_('price_sale');
            $usedColorsAndSizes = [];
            $stockTotal = 0;
            $clothingCategory = Item::findById($item->_('parentId'));

            $variations = (array)$item->_('variations');

            $colorsToParse = [];
            foreach ($variations as $variation) :
                $skuParts = array_reverse(explode('_', $variation['sku']));
                unset($skuParts[0]);
                $color = implode('_', array_reverse($skuParts));
                if (\count($colorsToParse) < 3) :
                    $colorsToParse[$color] = '';
                endif;
            endforeach;

            foreach ($variations as $variation) :
                $skuParts = array_reverse(explode('_', $variation['sku']));
                unset($skuParts[0]);
                $color = implode('_', array_reverse($skuParts));
                if (isset($colorsToParse[$color])) :
                    if (!isset($usedColorsAndSizes[$color])) :
                        $usedColorsAndSizes[$color] = [];
                    endif;
                    $usedColorsAndSizes[$color][] = $variation['size'];
                    $stockTotal += (int)$variation['stock'];

                    $products[] = $this->inventoryItemFactory(
                        $this->getColorId($color),
                        $this->getSizeId($variation['size'], $clothingCategory),
                        (int)$variation['stock'],
                        $price,
                        (int)$clothingCategory->_('etsySizeId')
                    );
                endif;
            endforeach;

            $baseSizes = $this->buildBaseSizes($variations);
            foreach ($usedColorsAndSizes as $color => $sizes) :
                $unusedSizes = array_diff($baseSizes, $sizes);
                foreach ($unusedSizes as $size) :
                    $products[] = $this->inventoryItemFactory(
                        $this->getColorId($color),
                        $this->getSizeId($size, $clothingCategory),
                        0,
                        $price,
                        (int)$clothingCategory->_('etsySizeId')
                    );
                endforeach;
            endforeach;

            if ($stockTotal > 0) :
                return $this->fetch('listings/'.$item->_('etsyId').'/inventory',
                    [
                        'products'             => json_encode($products),
                        'quantity_on_property' => '200,'.(int)$clothingCategory->_('etsySizeId'),
                    ],
                    'PUT'
                );
            endif;
        endif;

        return null;
    }

    public function getListingTranslation(int $listingId, string $language)
    {
        return $this->fetch('listings/'.$listingId.'/translations/'.$language, [], OAUTH_HTTP_METHOD_GET);
    }

    public function updateListingTranslation( Item $item, string $language)
    {
        $clothingCategory = Item::findById($item->_('parentId'));

        $params = [
            'listing_id'  => $item->_('etsyId'),
            'language'    => $language,
            'title'       => $item->_('name', $language),
            'description' => $this->builDescription($item, $language),
            'tags'        => $clothingCategory->_('etsyTags', $language),
        ];

        return $this->fetch(
            'listings/'.$item->_('etsyId').'/translations/'.$language,
            $params,
            OAUTH_HTTP_METHOD_PUT
        );
    }

    /**
     * @param string $apiCall
     * @param array $params
     * @param string $method
     *
     * @return mixed
     */
    protected function fetch(string $apiCall, array $params = [], $method = 'POST')
    {
        $options = ['FailOnAccessError' => true];
        /*if (isset($params['products'])) {
            $products = $params['products'];
            unset($params['products']);
            $params['products'] = json_encode([]);
            $options['PostValues'] = $params;
            $params = [];

        }*/
        $this->oauthClient->CallAPI(
            $this->baseUrl.$apiCall,
            $method,
            $params,
            $options,
            $response
        );

        return $response;
    }

    /**
     * @param string $apiCall
     * @param array $params
     * @param string $method
     *
     * @return mixed
     */
    protected function fetch_native(string $apiCall, array $params = [], $method = 'POST')
    {
        try {
            $response = $this->oauth->fetch(
                $this->baseUrl.$apiCall,
                $params,
                $method,
                []
            );
        } catch (\OAuthException $e) {
            echo $e->getMessage();
            die();
        }

        return $response;
    }

    /**
     * @param Item $item
     *
     * @return string
     */
    protected function builDescription(Item $item, string $language): string
    {
        $description = strip_tags($item->_('introtext', $language));

        if (MongoUtil::isObjectId($item->_('design'))) :
            $design = Design::findById($item->_('design'));
            if ($design) :
                if (!empty($description)) :
                    $description .= "

";
                endif;
                $description .= strip_tags($design->_('introtext', $language));
            endif;
        endif;

        if (MongoUtil::isObjectId($item->_('manufacturer'))) :
            $manufacturer = Item::findById($item->_('manufacturer'));
            if ($manufacturer) :
                $description .= str_replace(
                    ["  ", "  ", "  "],
                    " ",
                    $manufacturer->_('name', $language)
                    ."

".
                    strip_tags($manufacturer->_('introtext', $language))
                );
            endif;
        endif;

        return trim($description);
    }

    /**
     * @param string $size
     * @param Item $item
     *
     * @return int
     */
    protected function getSizeId(string $size, Item $item): int
    {
        if (isset($item->_('etsySizeMapper')[$size]) && !empty($item->_('etsySizeMapper')[$size])) {
            return (int)$item->_('etsySizeMapper')[$size];
        }

        echo 'Maat onbekend : '.$size;
        mail('jasper@craftbeershirts.net', 'Etsy maat onbekend : '.$size, $item->_('name').' : '.$item->_('slug'));
        die();
    }

    /**
     * @param string $color
     *
     * @return int
     */
    protected function getColorId(string $color): int
    {
        switch (strtoupper($color)) :
            //Zwart
            case 'WASHED_BLACK':
                //return 100101;
            case 'ASPHALT':
                //return 180487796296;
            case 'BLACK':
            case 'ZWART':
            case 'USED-BLACK':
            case 'USED_BLACK';
            case 'NEPPY_BLACK':
            case 'WHITE_BLACK':
                return 1;

            //Blauw
            case 'PEACOCK-BLUE':
            case 'BLUE_MARBLE':
            case 'VINTAGE_DENIM':
                //return 100044;
            case 'PACIFIC':
                //return 100041;
            case 'STEEL_BLUE':
                //return 344162229778;
            case 'HEATHER_BLUE':
                //return 189155708546;
            case 'NAVY':
            case 'HEATHER_NAVY':
            case 'WHITE_NAVY':
                //return 105218139766;
            case 'ROYAL_BLUE':
                //return 106533445468;
            case 'SKY':
                //return 131405330252;
            case 'DIVA_BLUE':
                //return 336707637170;
            case 'STONE-BLUE':
            case 'TURQUOISE':
                return 2;

            //Bruin
            case 'VINTAGE_KHAKI':
                //return 100118;
            case 'KHAKI':
                //return 113303730820;
            case 'NOBLE_BROWN':
                //return 352297575715;
            case 'KAKI':
            case 'BRUIN':
            case 'BROWN':
                return 3;

            //Groen
            case 'KHAKI_GREEN':
                //return 100086;
            case 'MOSS_GREEN':
                //return 100058;
            case 'MINT_GREEN':
                //return 100089;
            case 'STEEL_GREEN':
                //return 100066;
            case 'KELLY_GREEN':
                //return 180509014454;
            case 'BOTTLE-GREEN':
            case 'GROEN':
                return 4;

            //Grijs
            case 'DARK_GREY':
                //return 100042;
            case 'VINTAGE_GRAY':
                //return 100080;
            case 'GREY_MARBLE':
                //return 100053;
            case 'GRAPHITE_GREY':
                //return 336716794796;
            case 'HEATHER_GREY':
            case 'NEPPY_HEATHER_GREY':
                //return 180509014394;
            case 'CHARCOAL_GREY':
            case 'DARK_GREY_HEATHER':
            case 'CHARCOAL':
                //return 187941038486;
            case 'ASH-GREY':
            case 'SPORTS-GREY':
            case 'GRIJS':
            case 'GREY':
            case 'GRAY':
                return 5;

            //Oranje
            case 'ORANGE':
            case 'CORAL':
                return 6;

            //Rood
            case 'RED_MARBLE':
            case 'DARK_RED':
            case 'WASHED_BURGUNDY':
                //return 352301127235;
            case 'ROOD':
            case 'RED':
            case 'BORDEAUX':
                return 9;

            //Wit
            case 'NEPPY_CREME':
                //return 100068;
            case 'WHITE':
                return 10;

            //Geel
            case 'LEMON':
                //return 100043;
            case 'SUN_YELLOW':
                //return 100090;
            case 'YELLOW':
                return 11;

            //Roze
            case 'FUCHSIA':
            case 'DARK_PINK':
                //return 100046;
            case 'NEON_PINK':
                //return 100171;
            case 'PINK':
                return 7;
        endswitch;
        echo 'Kleur onbekend : '.$color;
        mail('jasper@craftbeershirts.net', 'Etsy kleur onbekend : '.$color, '');
        die();
    }
    /*
 "1213" => "Beige"
 "1216" => "Brons"
 "1214" => "Goud"
 "1218" => "Koper"
 "8" => "Paars"
 "1220" => "Regenboog"
 "1217" => "Roze goud"
 "1219" => "Transparant"
 "1215" => "Zilver */


    /**
     * @param int $colorId
     * @param int $sizeId
     * @param int $quantity
     * @param float $price
     * @param int $sizePropertyId
     *
     * @return mixed
     */
    protected function inventoryItemFactory(
        int $colorId,
        int $sizeId,
        int $quantity,
        float $price,
        int $sizePropertyId
    ) {
        $enabled = '1';
        if ($quantity < 1) :
            $enabled = '0';
        elseif ($quantity > 10) :
            $quantity = 10;
        endif;

        /*unserialize('O:8:"stdClass":5:{s:10:"product_id";i:2519322786;s:3:"sku";s:0:"";s:15:"property_values";a:2:{i:0;O:8:"stdClass":6:{s:11:"property_id";i:200;s:13:"property_name";s:13:"Primary color";s:8:"scale_id";N;s:10:"scale_name";N;s:9:"value_ids";a:1:{i:0;i:1;}s:6:"values";a:1:{i:0;s:5:"Black";}}i:1;O:8:"stdClass":6:{s:11:"property_id";i:62809790395;s:13:"property_name";s:4:"Size";s:8:"scale_id";i:42;s:10:"scale_name";s:11:"Letter size";s:9:"value_ids";a:1:{i:0;i:2019;}s:6:"values";a:1:{i:0;s:1:"L";}}}s:9:"offerings";a:1:{i:0;O:8:"stdClass":5:{s:11:"offering_id";i:2376563313;s:5:"price";O:8:"stdClass":6:{s:6:"amount";i:2000;s:7:"divisor";i:100;s:13:"currency_code";s:3:"EUR";s:24:"currency_formatted_short";s:8:"€20.00";s:23:"currency_formatted_long";s:12:"€20.00 EUR";s:22:"currency_formatted_raw";s:5:"20.00";}s:8:"quantity";i:2;s:10:"is_enabled";i:1;s:10:"is_deleted";i:0;}}s:10:"is_deleted";i:0;}');*/

        $inventoryItem = unserialize('O:8:"stdClass":5:{s:10:"product_id";i:2519322786;s:3:"sku";s:0:"";s:15:"property_values";a:2:{i:0;O:8:"stdClass":6:{s:11:"property_id";i:200;s:13:"property_name";s:13:"Primary color";s:8:"scale_id";N;s:10:"scale_name";N;s:9:"value_ids";a:1:{i:0;i:1;}s:6:"values";a:1:{i:0;s:5:"Black";}}i:1;O:8:"stdClass":6:{s:11:"property_id";i:62809790395;s:13:"property_name";s:4:"Size";s:8:"scale_id";i:42;s:10:"scale_name";s:11:"Letter size";s:9:"value_ids";a:1:{i:0;i:2019;}s:6:"values";a:1:{i:0;s:1:"L";}}}s:9:"offerings";a:1:{i:0;O:8:"stdClass":5:{s:11:"offering_id";i:2376563313;s:5:"price";O:8:"stdClass":6:{s:6:"amount";i:2000;s:7:"divisor";i:100;s:13:"currency_code";s:3:"EUR";s:24:"currency_formatted_short";s:8:"€20.00";s:23:"currency_formatted_long";s:12:"€20.00 EUR";s:22:"currency_formatted_raw";s:5:"20.00";}s:8:"quantity";i:2;s:10:"is_enabled";i:1;s:10:"is_deleted";i:0;}}s:10:"is_deleted";i:0;}',
            [\stdClass::class]);
        //2
//25
        unset(
            $inventoryItem->product_id,
            $inventoryItem->sku,
            $inventoryItem->property_values[0]->scale_id,
            $inventoryItem->property_values[0]->scale_name,
            $inventoryItem->property_values[0]->property_name,
            $inventoryItem->property_values[0]->values,
            $inventoryItem->property_values[1]->scale_id,
            $inventoryItem->property_values[1]->scale_name,
            $inventoryItem->property_values[1]->property_name,
            $inventoryItem->property_values[1]->values,
            $inventoryItem->offerings[0]->is_deleted,
            $inventoryItem->offerings[0]->offering_id,
            $inventoryItem->is_deleted
        );

        $inventoryItem->property_values[0]->value_ids[0] = $colorId;
        $inventoryItem->property_values[1]->value_ids[0] = $sizeId;
        $inventoryItem->property_values[1]->property_id = $sizePropertyId;

        $inventoryItem->offerings[0]->price = $price;
        $inventoryItem->offerings[0]->quantity = $quantity;
        $inventoryItem->offerings[0]->is_enabled = $enabled;

        return $inventoryItem;
    }

    /**
     * @throws \OAuthException
     * @throws \Phalcon\Mvc\Collection\Exception
     * @throws \Exception
     */
    protected function setOauth(): void
    {
        $this->oauth = new \OAuth(
            $this->setting->get('ETSY_CONSUMER_KEY'),
            $this->setting->get('ETSY_CONSUMER_SECRET'),
            OAUTH_SIG_METHOD_HMACSHA1,
            OAUTH_AUTH_TYPE_URI
        );

        if (\defined('OAUTH_REQENGINE_CURL')) {
            $this->engine = OAUTH_REQENGINE_CURL;
            $this->oauth->setRequestEngine(OAUTH_REQENGINE_CURL);
        } elseif (\defined('OAUTH_REQENGINE_STREAMS')) {
            $this->engine = OAUTH_REQENGINE_STREAMS;
            $this->oauth->setRequestEngine(OAUTH_REQENGINE_STREAMS);
        } else {
            throw new \Exception('Warning: cURL engine not present on OAuth PECL package: sudo apt-get install libcurl4-dev or sudo yum install curl-devel');
        }

        $this->oauth->setToken(
            $this->setting->get('ETSY_ACCESS_TOKEN'),
            $this->setting->get('ETSY_ACCESS_SECRET')
        );
    }

    /**
     * @throws \Phalcon\Mvc\Collection\Exception
     */
    protected function setOauthClient(): void
    {
        require_once(__DIR__.'/../../../vendor/hatframework/oauth-api/httpclient/http.php');
        require_once(__DIR__.'/../../../vendor/hatframework/oauth-api/oauth-api/oauth_client.php');

        $this->oauthClient = new \oauth_client_class();
        $this->oauthClient->debug = true;
        $this->oauthClient->debug_http = true;
        $this->oauthClient->server = 'Etsy';
        $this->oauthClient->configuration_file = __DIR__.'/../../../vendor/hatframework/oauth-api/oauth-api/oauth_configuration.json';
        $this->oauthClient->redirect_uri = 'http://'.$_SERVER['HTTP_HOST'].
            dirname(strtok($_SERVER['REQUEST_URI'], '?')).'/login_with_etsy.php';
        $this->oauthClient->client_id = $this->setting->get('ETSY_CONSUMER_KEY');
        $this->oauthClient->client_secret = $this->setting->get('ETSY_CONSUMER_SECRET');
        $this->oauthClient->access_token = $this->setting->get('ETSY_ACCESS_TOKEN');
        $this->oauthClient->access_token_secret = $this->setting->get('ETSY_ACCESS_SECRET');

        if (($success = $this->oauthClient->Initialize())) {
            if (($success = $this->oauthClient->Process())) {
                if (strlen($this->oauthClient->access_token)) {
                    $success = $this->oauthClient->CallAPI(
                        $this->baseUrl.'users/__SELF__',
                        'GET', [], ['FailOnAccessError' => true], $user);
                }
            }
            $this->oauthClient->Finalize($success);
        }
    }

    /**
     * @param array $variations
     *
     * @return array
     */
    protected function buildBaseSizes(array $variations): array
    {
        $sizes = [];
        foreach ($variations as $variation) :
            if (!isset($sizes[$variation['size']])) :
                $sizes[] = $variation['size'];
            endif;
        endforeach;

        return $sizes;
    }
}
