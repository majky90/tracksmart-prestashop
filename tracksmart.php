
    <?php

if (!defined('_PS_VERSION_'))
{
    exit;
}

class TrackSmart extends Module
{

    private $configuration_fields = [
        'TRACKSMART_STATE' => array(
            'type' => 'switch',
            'label' => 'State',
            'is_bool' => true,
            'desc' => 'General state of the module',
            'values' => array(
                array(
                    'id' => 'active_on',
                    'value' => true,
                    'label' => 'Enabled'
                ),
                array(
                    'id' => 'active_off',
                    'value' => false,
                    'label' => 'Disabled'
                ),
            ),
        ),

        'TRACKSMART_ID' => array(
            'type' => 'text',
            'label' => 'Container ID',
            'desc' => 'Format: GTM-XXXXXX',
            'required' => true
        )
    ];

    public function __construct()
    {
        $this->name = 'tracksmart';
        $this->tab = 'analytics_stats';
        $this->module_key = 'dc5b9ea5c7aeb8266461cf40270cc604';
        $this->version = '1.1.1';
        $this->author = 'Kacper Duras';
        $this->need_instance = 1;

        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('TrackSmart');
        $this->description = $this->l('Module to enhanced tracking for Google Analytics 4 (via Google Tag Manager)');

        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => '9.9');
    }

    // PrestaShop 9.x uses displayHeader instead of header
    public function hookDisplayHeader($params = [])
    {
        return $this->hookHeader($params);
    }

    public function install()
    {
        foreach ($this->configuration_fields as $key => $value)
        {
            if ($value != null)
            {
                $boolean = $value['boolean'] ?? false;
                Configuration::updateValue($key, $boolean ? false : '');
            }
        }

        return parent::install() &&
            $this->registerHook('actionFrontControllerSetMedia') &&
            $this->registerHook('displayHeader');
    }

    public function uninstall()
    {
        foreach ($this->configuration_fields as $key => $value)
        {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    public function getContent()
    {
        $this->context->smarty->assign('module_dir', $this->_path);
        $output = $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');

        if (Tools::isSubmit('submitTrackSmart'))
        {
            $state = Tools::getValue('TRACKSMART_STATE');
            $container = Tools::getValue('TRACKSMART_ID');

            if ($state && ($container == null || empty($container) || strncmp($container, "GTM-", 4) !== 0))
            {
                $output .= $this->displayError('Please, provide valid format of container ID (GTM-XXXXXX)');
            }
            else
            {
                foreach (array_keys($this->configuration_fields) as $key)
                {
                    Configuration::updateValue($key, Tools::getValue($key));
                }
                $output .= $this->displayConfirmation('Settings updated');
            }
        }

        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitTrackSmart';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $vars = array();
        $input = array();

        foreach ($this->configuration_fields as $key => $value)
        {
            $vars[$key] = Configuration::get($key);

            $value['name'] = $key;
            array_push($input, $value);
        }

        $helper->tpl_vars = array(
            'fields_value' => $vars,
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );

        return $output . $helper->generateForm(
            array(
                array(
                    'form' => array(
                        'legend' => array(
                            'title' => 'General settings',
                            'icon' => 'icon-cogs',
                        ),

                        'input' => $input,

                        'submit' => array(
                            'title' => 'Save',
                        ),
                    ),
                )
            ));
    }

    public function hookActionFrontControllerSetMedia()
    {
        if (!Configuration::get('TRACKSMART_STATE'))
        {
            return;
        }

        $this->context->controller->registerJavascript('tracksmart_sdk',
            'modules/' . $this->name . '/views/js/sdk.js', array('position' => 'head', 'priority' => 100));
        $this->context->controller->registerJavascript('tracksmart_front',
            'modules/' . $this->name . '/views/js/front.js', array('position' => 'bottom', 'priority' => 100));
    }

    public function hookHeader()
    {
        if (!Configuration::get('TRACKSMART_STATE'))
        {
            return;
        }

        $controller = $this->context->controller->php_self;
        if (empty($controller))
        {
            $controller = Tools::getValue('controller');
        }

        $event = array('data' => array());

        if ($controller === 'cart')
        {
            // BEGIN_CHECKOUT event with prices including tax
            $event['name'] = 'begin_checkout';
            $event['data']['currency'] = $this->context->currency->iso_code;
            $event['data']['value'] = (double) $this->context->cart->getOrderTotal(true);

            $products = array();

            foreach ($this->context->cart->getProducts() as $product)
            {
                $category = new Category($product['id_category_default'], $this->context->language->id);
                
                $item = array(
                    'item_name' => Tools::replaceAccentedChars($product['name']),
                    'item_id' => (string) $product['id_product'],
                    'price' => (double) $product['price_wt'],
                    'item_brand' => $product['manufacturer_name'],
                    'item_category' => $category->name,
                    'quantity' => (int) $product['quantity']
                );

                // Add item_variant only if it exists
                if (!empty($product['attributes_small']))
                {
                    $item['item_variant'] = $product['attributes_small'];
                }

                $products[] = $item;
            }

            $event['data']['items'] = $products;
        }
        elseif ($controller === 'order-confirmation')
        {
            // PURCHASE event with prices including tax from Order object
            $id_order = (int) Tools::getValue('id_order');
            if ($id_order > 0)
            {
                $order = new Order($id_order);

                $event['name'] = 'purchase';
                $event['data']['currency'] = $this->context->currency->iso_code;
                $event['data']['transaction_id'] = $order->reference;
                $event['data']['affiliation'] = Configuration::get('PS_SHOP_NAME');
                $event['data']['value'] = (double) $order->total_paid_tax_incl;

                // Calculate tax from order totals
                $tax_amount = (double)$order->total_paid_tax_incl - (double)$order->total_paid_tax_excl;
                $event['data']['tax'] = ($tax_amount > 0) ? (double)$tax_amount : 0.0;
                
                // Get shipping cost with tax included
                $event['data']['shipping'] = (double) $order->total_shipping_tax_incl;

                $products = array();

                foreach ($order->getProducts() as $product)
                {
                    $category = new Category($product['id_category_default'], $this->context->language->id);

                    // Get manufacturer name from product
                    $manufacturer_name = '';
                    if (!empty($product['product_manufacturer']))
                    {
                        $manufacturer_name = $product['product_manufacturer'];
                    }
                    elseif ($product['product_id'] > 0)
                    {
                        $prod_obj = new Product((int) $product['product_id']);
                        if ($prod_obj->id_manufacturer > 0)
                        {
                            $manufacturer = new Manufacturer($prod_obj->id_manufacturer);
                            $manufacturer_name = $manufacturer->name ?? '';
                        }
                    }

                    $item = array(
                        'item_name' => Tools::replaceAccentedChars($product['product_name']),
                        'item_id' => (string) $product['product_id'],
                        'price' => (double) $product['unit_price_tax_incl'],
                        'item_category' => $category->name,
                        'quantity' => (int) $product['product_quantity']
                    );

                    // Add item_brand only if not empty
                    if (!empty($manufacturer_name))
                    {
                        $item['item_brand'] = $manufacturer_name;
                    }

                    // Add item_variant only if it exists
                    if (!empty($product['product_attribute_text']))
                    {
                        $item['item_variant'] = $product['product_attribute_text'];
                    }

                    $products[] = $item;
                }

                // Add coupon if available
                $coupons = array();
                $cart_rules = $order->getCartRules();
                if (is_array($cart_rules) && count($cart_rules) > 0)
                {
                    foreach ($cart_rules as $rule)
                    {
                        $coupons[] = $rule['name'];
                    }
                    if (count($coupons) > 0)
                    {
                        $event['data']['coupon'] = implode(' | ', $coupons);
                    }
                }

                $event['data']['items'] = $products;
            }
        }
        elseif ($controller === 'product')
        {
            // VIEW_ITEM event with price including tax
            $product = $this->context->controller->getTemplateVarProduct();
            $category = new Category($product['id_category_default'], $this->context->language->id);

            // Get price with tax included
            $price_tax_incl = (double) Product::getPriceStatic((int) $product['id_product'], true, null, 6);

            $event['name'] = 'view_item';
            $event['data']['currency'] = $this->context->currency->iso_code;
            $event['data']['value'] = $price_tax_incl;

            $item = array(
                'item_name' => Tools::replaceAccentedChars($product['name']),
                'item_id' => (string) $product['id_product'],
                'price' => $price_tax_incl,
                'item_brand' => $product['manufacturer_name'],
                'item_category' => $category->name,
                'quantity' => (int) $product['minimal_quantity']
            );

            // Add item_variant only if it exists
            if (!empty($product['attributes_small']))
            {
                $item['item_variant'] = $product['attributes_small'];
            }

            $event['data']['items'] = array($item);
        }
        elseif ($controller === 'category')
        {
            // VIEW_ITEM_LIST event with index and fixed quantity = 1
            $page = (int) (Tools::getIsset('page') ? Tools::getValue('page') : 1);
            $limit = (int) (Configuration::get('PS_PRODUCTS_PER_PAGE') ?? 12);
            $products = $this->context->controller->getCategory()
                ->getProducts($this->context->language->id, $page, $limit);

            $event['name'] = 'view_item_list';
            $event['data']['currency'] = $this->context->currency->iso_code;
            $result = array();

            if (is_array($products) && count($products) > 0)
            {
                $index = 0;
                foreach ($products as $product)
                {
                    // Get price with tax included
                    $price_tax_incl = (double) Product::getPriceStatic((int) $product['id_product'], true, null, 6);
                    $category = new Category($product['id_category_default'], $this->context->language->id);

                    $item = array(
                        'item_name' => Tools::replaceAccentedChars($product['name']),
                        'item_id' => (string) $product['id_product'],
                        'price' => $price_tax_incl,
                        'item_brand' => $product['manufacturer_name'],
                        'item_category' => $category->name,
                        'index' => (int) $index,
                        'quantity' => 1
                    );

                    // Add item_variant only if it exists
                    if (!empty($product['attributes_small']))
                    {
                        $item['item_variant'] = $product['attributes_small'];
                    }

                    $result[] = $item;
                    $index++;
                }
            }

            $event['data']['items'] = $result;
        }

        $variables = array(
            'tracksmart_container' => Configuration::get('TRACKSMART_ID'),
            'tracksmart_user' => $this->context->customer->id ?? null,
            'tracksmart_event' => $event['name'] ?? null,
            // Use native PHP functions for JSON encode/decode (PHP 8.4 compatible)
            'tracksmart_data' => json_decode(json_encode($event['data'] ?? (object)[], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true)
        );

        Media::addJsDef(array('tracksmart_frontcontroller' =>
            Context::getContext()->link->getModuleLink($this->name, 'ajax', array(), true)));

        $this->context->smarty->assign($variables);
        return $this->display(__FILE__, 'views/templates/hook/header.tpl');
    }

}
