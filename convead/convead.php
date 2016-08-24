<?php

if (!defined('_PS_VERSION_')) exit;

class Convead extends Module
{ 
  public function __construct()
  {
    $this->name = 'convead';
    $this->tab = 'analytics_stats';
    $this->version = '2.0';
    $this->author = 'Rostber';
    $this->displayName = $this->l('Convead');
    $this->module_key = '95857606d6e384f5992eeb0771f7a3a5';
    $this->bootstrap = true;
    
    parent::__construct();
    
    if ($this->id AND !Configuration::get('APP_KEY')) $this->warning = $this->l('You have not yet set your Convead APP_KEY');
    $this->description = $this->l('Integrate Convead script into your shop');
    $this->confirmUninstall = $this->l('Are you sure you want to delete your details?');
  }

  function install()
  {
    if (!parent::install() ||
        !$this->registerHook('header') ||
        !$this->registerHook('orderConfirmation') ||
        !$this->registerHook('actionOrderStatusPostUpdate') ||
        !$this->registerHook('cart')
      ) return false;
    return true;
  }
  
  function uninstall()
  {
    if (!parent::uninstall()) return false;
    Configuration::deleteByName('APP_KEY');
    Configuration::deleteByName('deleted_state_id');
    return true;
  }
  
  private $post_errors = array();
  
  public function getContent()
  {
    $output = '';
    if (Tools::isSubmit('submitConvead'))
    {
      $this->_postValidation();
      if (count($this->post_errors) == 0) $output .= $this->_postProcess();
      else foreach ($this->post_errors as $err) $output .= $this->displayError($err);     
    }
    
    if (!function_exists('curl_exec')) $output .= $this->displayError($this->l('Disabled expansion curl'));
    if (!extension_loaded('mbstring')) $output .= $this->displayError($this->l('Disabled expansion mbstring'));
    if (count($error_array) > 0) $output .= $this->displayError('<span class="required">'.implode('<br />', $error_array).'</span><br /><b>'.$this->l('Contact support hosting').'</b>');

    return $output.$this->displayForm();
  }

  public function displayForm()
  {
    $sate_core = new OrderStateCore();
    $states = $sate_core->getOrderStates($this->context->language->id);
    $options = array(''=>'-');
    foreach($states as $state) $options[] = array('id_option'=>$state['id_order_state'], 'name'=>$state['name']);
      
    $this->fields_form[0]['form'] = array(
        'legend' => array(
        'title' => $this->l('Convead')
      ),
      'input' => array(
        array(
          'type' => 'text',
          'label' => $this->l('app_key'),
          'desc' => $this->l('Register convead').' <a href="http://convead.io/" target="_blank">Convead</a>',
          'name' => 'APP_KEY',
          'required' => true
        ),
        array(
          'type' => 'select',
          'label' => $this->l('deleted_state_id'),
          'name' => 'deleted_state_id',
          'options' => array(
            'query' => $options,
            'id' => 'id_option',   
            'name' => 'name'
          )
        )
      ),
      'submit' => array(
        'name' => 'submitConvead',
        'title' => $this->l('Save')
      )
    );

    $helper = new HelperForm();
    $helper->module = $this;
    $helper->show_toolbar = false;
    $helper->table = $this->table;
    $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
    $helper->default_form_language = $lang->id;
    $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
    $helper->identifier = $this->identifier;
    $helper->submit_action = 'convead';
    $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
    $helper->token = Tools::getAdminTokenLite('AdminModules');
    $helper->tpl_vars = array(
      'fields_value' => $this->getConfigFieldsValues(),
      'languages' => $this->context->controller->getLanguages(),
      'id_language' => $this->context->language->id
    );
    return $helper->generateForm($this->fields_form);
  }
  
  public function getConfigFieldsValues()
  {
    $fields_values = array();
    
    $fields_values['APP_KEY'] = Configuration::get('APP_KEY');
    $fields_values['deleted_state_id'] = Configuration::get('deleted_state_id');
    return $fields_values;
  }
  
  private function _postValidation()
  {
    if (!Tools::getValue('APP_KEY')) $this->post_errors[] = $this->l('Invalid').' '.$this->l('app_key');
  }

  private function _postProcess()
  {
    Configuration::updateValue('APP_KEY', (string)Tools::getValue('APP_KEY'));
    Configuration::updateValue('deleted_state_id', (string)Tools::getValue('deleted_state_id'));
      
    return $this->displayConfirmation($this->l('Settings updated'));
  }
  
  function hookHeader($params)
  {
    $app_key = Configuration::get('APP_KEY');

    if (empty($app_key)) return false;

    $this->context->smarty->assign('app_key', $app_key);

    if ($this->context->customer->isLogged()) $this->context->smarty->assign('customer', $this->context->customer);
    else $this->context->smarty->assign('customer', false);

    if (Dispatcher::getInstance()->getController() == 'product' && $product_id = Tools::getValue('id_product'))
    {
      $product = new Product($product_id, true, $this->context->language->id, $this->context->shop->id);
      if (!empty($product->cache_default_attribute)) $product_id .= 'c'.$product->cache_default_attribute;
      $this->context->smarty->assign('is_product_page', true);
      $this->context->smarty->assign('product_id', $product_id);
      $this->context->smarty->assign('product_name', $product->name);
      $this->context->smarty->assign('category_id', $product->id_category_default);
    }
    else $this->context->smarty->assign('is_product_page', false);

    return $this->display(__FILE__, 'header.tpl');
  }
  
  function hookFooter($params)
  {
    // for retrocompatibility
    if (!$this->isRegisteredInHook('header'))
      $this->registerHook('header');
    return ;
  }

  function hookCart($params)
  {
    // rejection if the cart does not exist
    $context = Context::getContext();
    if (empty($context->cookie->id_cart)) return false;

    return $this->_updateCart($params);
  }

  function hookOrderConfirmation($params)
  {
    if (!($tracker = $this->_includeTracker())) return;

    $parameters = Configuration::getMultiple(array('PS_LANG_DEFAULT'));
    
    $order = $params['objOrder'];
    if (Validate::isLoadedObject($order))
    {
      $conversion_rate = 1;
      if ($order->id_currency != Configuration::get('PS_CURRENCY_DEFAULT'))
      {
        $currency = new Currency(intval($order->id_currency));
        $conversion_rate = floatval($currency->conversion_rate);
      }

      $products = $order->getProducts();
      $products_array = array();
      foreach ($products AS $product)
      {
        $product_id = $product['product_id'];
        if ($product['product_attribute_id']) $product_id .= 'c'.$product['product_attribute_id'];
        $products_array[] = array(
          'product_id' => $product_id,
          'qnt' => addslashes(intval($product['product_quantity'])),
          //'product_name' => addslashes($product['product_name']),
          'price' => Tools::ps_round(floatval($product['product_price_wt']) / floatval($conversion_rate), 2)
        );
      }

      $total = Tools::ps_round(floatval($order->total_paid) / floatval($conversion_rate), 2);
      //$shipping = Tools::ps_round(floatval($order->total_shipping) / floatval($conversion_rate), 2);

      $tracker->eventOrder(intval($order->id), $total, $products_array);
    }

    return;
  }

  function hookActionOrderStatusPostUpdate($params)
  {
    if (!($api = $this->_includeApi())) return;
    
    $order_id = $params['id_order'];
    $state = $params['newOrderStatus']->id;

    // detect set new state or delete order
    if ((string)$state == (string)Configuration::get('deleted_state_id')) $api->order_delete($order_id);
    else $api->order_set_state($order_id, $state);
  }

  function _updateCart($params)
  {
    if (!($tracker = $this->_includeTracker()) || !isset($params['cart'])) return;

    $c_products_cart = array();
    $products_cart = $params['cart']->getProducts(true);
    foreach($products_cart as $product)
    {
      $product_id = $product['id_product'];
      if ($product['id_product_attribute']) $product_id .= 'c'.$product['id_product_attribute'];
      $c_products_cart[] = array('product_id' => $product_id, 'qnt' => $product['cart_quantity'], 'price' => $product['price']);
    }
    $tracker->eventUpdateCart($c_products_cart);
  }

  function _includeApi()
  {
    $app_key = Configuration::get('APP_KEY');

    if (empty($app_key)) return false;

    include_once('api/ConveadTracker.php');

    $api = new ConveadApi($app_key);
    return $api;
  }

  function _includeTracker()
  {
    $app_key = Configuration::get('APP_KEY');

    if (empty($app_key)) return false;

    include_once('api/ConveadTracker.php');

    if ($this->context->customer->isLogged())
    {
      $user_id = $this->context->customer->id;
      $guest_uid = false;
    }
    else 
    {
      if (empty($_COOKIE['convead_guest_uid'])) return false;
      $guest_uid = $_COOKIE['convead_guest_uid'];
    }

    $tracker = new ConveadTracker($app_key, $_SERVER['SERVER_NAME'], $guest_uid, (isset($user_id) ? $user_id : false), (isset($visitor_info) ? $visitor_info : false));
    
    return $tracker;
  }

}
