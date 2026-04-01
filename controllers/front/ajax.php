<?php

use PrestaShop\PrestaShop\Adapter\ObjectPresenter;
use PrestaShop\PrestaShop\Adapter\Cart\CartPresenter;

class TrackSmartAjaxModuleFrontController extends ModuleFrontController
{

    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        header('Content-Type: application/json');

        if (!Tools::getIsset('id')) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Missing product id.'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $id = Tools::getValue('id');
        $attribute = Tools::getIsset('attribute') ? ((int) Tools::getValue('attribute')) : 0;
        $customization = Tools::getIsset('customization') ? ((int) Tools::getValue('customization')) : 0;

        $product = null;

        // Try CartPresenter for customized products, fallback to ObjectPresenter for normal products
        if ($customization > 0) {
            if (class_exists('PrestaShop\\PrestaShop\\Adapter\\Cart\\CartPresenter')) {
                $data = (new CartPresenter())->present($this->context->cart);
                foreach ($data['products'] as $target) {
                    if ($target['id_product'] == $id && $target['id_product_attribute'] == $attribute
                        && $target['id_customization'] == $customization) {
                        $product = $target;
                        break;
                    }
                }
            }
        }

        if ($product == null) {
            if (class_exists('PrestaShop\\PrestaShop\\Adapter\\ObjectPresenter')) {
                $product = (new ObjectPresenter())->present(new Product($id, true, $this->context->language->id));
            } else {
                // Fallback: minimal product info
                $p = new Product($id, true, $this->context->language->id);
                $product = [
                    'id' => $p->id,
                    'name' => $p->name,
                    'id_category_default' => $p->id_category_default,
                    'price' => $p->price,
                    'manufacturer_name' => method_exists($p, 'getManufacturerName') ? $p->getManufacturerName() : null,
                    'attributes_small' => '',
                    'minimal_quantity' => $p->minimal_quantity,
                ];
            }
        }
        $category = new Category($product['id_category_default'], $this->context->language->id);

        // Get product ID
        $product_id = (int) ($customization > 0 ? ($product['id_product'] ?? $product['id']) : $product['id']);
        
        // Get price with tax included
        $price_tax_incl = (double) Product::getPriceStatic($product_id, true, null, 6);
        
        // Get manufacturer name with fallback
        $manufacturer_name = '';
        if (!empty($product['manufacturer_name'])) {
            $manufacturer_name = $product['manufacturer_name'];
        } else {
            $prod_obj = new Product($product_id, false, $this->context->language->id);
            if ($prod_obj->id_manufacturer > 0) {
                $manufacturer = new Manufacturer($prod_obj->id_manufacturer);
                $manufacturer_name = $manufacturer->name ?? '';
            }
        }

        $item_data = [
            'item_name' => Tools::replaceAccentedChars($product['name']),
            'item_id' => (string) $product_id,
            'price' => $price_tax_incl,
            'item_category' => $category->name,
            'quantity' => (int) ($customization > 0 ? ($product['quantity'] ?? $product['minimal_quantity']) : $product['minimal_quantity'])
        ];

        // Add item_brand only if not empty
        if (!empty($manufacturer_name)) {
            $item_data['item_brand'] = $manufacturer_name;
        }

        // Add item_variant only if not empty
        if (!empty($product['attributes_small'])) {
            $item_data['item_variant'] = $product['attributes_small'];
        }

        echo json_encode($item_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

}
