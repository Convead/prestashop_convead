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
        !$this->registerHook('actionOrderStatusPostUpdate') ||
        !$this->registerHook('cart') ||
        !$this->registerHook('newOrder')
      ) return false;
    return true;
  }
  
  function uninstall()
  {
    if (!parent::uninstall()) return false;
    Configuration::deleteByName('APP_KEY');
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
    if (count($this->post_errors) > 0) $output .= $this->displayError('<span class="required">'.implode('<br />', $this->post_errors).'</span><br /><b>'.$this->l('Contact support hosting').'</b>');

    return $output.$this->displayForm();
  }

  public function displayForm()
  {      
    $this->fields_form[0]['form'] = array(
      'legend' => array(
        'title' => $this->l('Convead')
      ),
      'input' => array(
        array(
          'type' => 'text',
          'label' => $this->l('app_key'),
          'name' => 'APP_KEY',
          'required' => true
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
    return $fields_values;
  }
  
  private function _postValidation()
  {
    if (!Tools::getValue('APP_KEY')) $this->post_errors[] = $this->l('Invalid').' '.$this->l('app_key');
  }

  private function _postProcess()
  {
    Configuration::updateValue('APP_KEY', (string)Tools::getValue('APP_KEY'));
      
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

    return $this->_sendUpdateCart($params);
  }

  function hookActionOrderStatusPostUpdate($params)
  {
    if (!($tracker = $this->_includeTracker())) return;
    
    $state = $params['newOrderStatus']->id;
    $order = new Order((int)$params['id_order']);

    if (!($order_data = $this->_getOrderData($order))) return;

    $tracker->webHookOrderUpdate($order_data->order_id, $state, $order_data->revenue, $order_data->items);
  }

  function hookNewOrder($params)
  {
    if (empty($params['order']) or empty($params['customer'])) return;
    
    $customer = $params['customer'];
  
    if (!($tracker = $this->_includeTracker($customer->id, $this->_getVisitorInfo($customer)))) return;
    
    $order = $params['order'];
    
    if (!($order_data = $this->_getOrderData($order))) return;

    $tracker->eventOrder($order_data->order_id, $order_data->revenue, $order_data->items, $order_data->state);

    return;
  }

  private function _getOrderData($order)
  {
    if (!(Validate::isLoadedObject($order))) return false;

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
        'product_name' => addslashes($product['product_name']),
        'price' => Tools::ps_round(floatval($product['product_price_wt']) / floatval($conversion_rate), 2)
      );
    }

    $revenue = Tools::ps_round(floatval($order->total_paid) / floatval($conversion_rate), 2);
    //$shipping = Tools::ps_round(floatval($order->total_shipping) / floatval($conversion_rate), 2);

    $ret = new stdClass();
    $ret->order_id = strval($order->id);
    $ret->revenue = $revenue;
    $ret->items = $products_array;
    $ret->state = OrderHistory::getLastOrderState($order->id);

    return $ret;
  }

  private function _sendUpdateCart($params)
  {
    $user_id = $this->context->customer->isLogged() ? $this->context->customer->id : false;
    if (!($tracker = $this->_includeTracker($user_id)) || !isset($params['cart'])) return;

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
  
  private function _getVisitorInfo($suctomer)
  {
    $visitor_info = array();
    $fields = array(
      'lastname' => 'first_name',
      'firstname' => 'last_name',
      'email' => 'email'
    );
    foreach($fields as $key_cms=>$key_cnv)
    {
      if (!empty($suctomer->$key_cms)) $visitor_info[$key_cnv] = $suctomer->$key_cms;
    }
    return $visitor_info;
  }

  private function _includeTracker($user_id = false, $visitor_info = false)
  {
    $app_key = Configuration::get('APP_KEY');

    if (empty($app_key)) return false;

    include_once('api/ConveadTracker.php');

    $guest_uid = !empty($_COOKIE['convead_guest_uid']) ? $_COOKIE['convead_guest_uid'] : false;
    $tracker = new ConveadTracker($app_key, $_SERVER['SERVER_NAME'], $guest_uid, $user_id, (!empty($visitor_info) ? $visitor_info : false));
    return $tracker;
  }

}
