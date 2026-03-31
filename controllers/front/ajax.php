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
        echo json_encode([
            'item_name' => Tools::replaceAccentedChars($product['name']),
            'item_id' => $customization > 0 ? $product['id_product'] ?? $product['id'] : $product['id'],
            'price' => ((double) $product['price']),
            'item_brand' => $product['manufacturer_name'] ?? null,
            'item_category' => $category->name,
            'item_variant' => $product['attributes_small'] ?? null,
            'quantity' => $customization > 0 ? ($product['quantity'] ?? $product['minimal_quantity']) : $product['minimal_quantity']
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

}
