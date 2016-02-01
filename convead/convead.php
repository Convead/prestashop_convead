<?php

if (!defined('_PS_VERSION_')) exit;

class Convead extends Module
{ 
  public function __construct()
  {
    $this->name = 'convead';
    $this->tab = 'analytics_stats';
    $this->version = '1.2';
    $this->author = 'Rostber';
    $this->displayName = $this->l('Convead');
    $this->module_key = '95857606d6e384f5992eeb0771f7a3a5';
    
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
        !$this->registerHook('cart')
      ) return false;
    return true;
  }
  
  function uninstall()
  {
    if (!Configuration::deleteByName('APP_KEY') || !parent::uninstall()) return false;
    return true;
  }
  
  public function getContent()
  {
    $output = '<h2>'.$this->l('Convead').'</h2>';
    if (Tools::isSubmit('submitConvead') AND ($app_key = Tools::getValue('APP_KEY')))
    {
      Configuration::updateValue('APP_KEY', $app_key);
      $output .= '<div class="bootstrap"><div class="alert alert-success">'.$this->l('Settings updated').'</div></div>';
    }
    return $output.$this->displayForm();
  }

  public function displayForm()
  {
    $output = '';

    $error_array = array();
    if (!function_exists('curl_exec')) $error_array[] = $this->l('Disabled expansion curl');
    if (!extension_loaded('mbstring')) $error_array[] = $this->l('Disabled expansion mbstring');
    if (count($error_array) > 0) $output .= '
      <div class="error">
        <span class="required">'.implode('<br />', $error_array).'</span><br />
        <b>'.$this->l('Contact support hosting').'</b>
      </div>
    ';

    $output .= '
    <form action="'.Tools::safeOutput($_SERVER['REQUEST_URI']).'" method="post">
      <fieldset>
        <legend><img src="../img/admin/cog.gif" alt="" class="middle" />'.$this->l('Settings').'</legend>
        <label>'.$this->l('app_key').'</label>
        <div class="margin-form">
          <input type="text" name="APP_KEY" value="'.Tools::safeOutput(Tools::getValue('APP_KEY', Configuration::get('APP_KEY'))).'" style="width: 300px" /><br />
          '.$this->l('Register convead').' <a href="http://convead.io/" target="_blank">Convead</a>
        </div>
        <input type="submit" name="submitConvead" value="'.$this->l('Save').'" class="button" />
      </fieldset>
    </form>';
    return $output;
  }
  
  function hookHeader($params)
  {
    $app_key = Configuration::get('APP_KEY');

    if (empty($app_key)) return false;

    $this->context->smarty->assign('app_key', $app_key);

    if ($this->context->customer->isLogged())
    {
      $this->context->smarty->assign('customer', $this->context->customer);
    }
    else $this->context->smarty->assign('customer', false);

    if (Dispatcher::getInstance()->getController() == 'product' && $product_id = Tools::getValue('id_product'))
    {
      $product = new Product($product_id, true, $this->context->language->id, $this->context->shop->id);
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
    if (!($convead = $this->_includeApi())) return;

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

      $convead->eventOrder(intval($order->id), $total, $products_array);
    }

    return;
  }

  function _updateCart($params)
  {
    if (!($convead = $this->_includeApi()) || !isset($params['cart'])) return;

    $c_products_cart = array();
    $products_cart = $params['cart']->getProducts(true);
    foreach($products_cart as $product)
    {
      $product_id = $product['id_product'];
      if ($product['id_product_attribute']) $product_id .= 'c'.$product['id_product_attribute'];
      $c_products_cart[] = array('product_id' => $product_id, 'qnt' => $product['cart_quantity'], 'price' => $product['price']);
    }
    $convead->eventUpdateCart($c_products_cart);
  }

  function _includeApi()
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

    $convead = new ConveadTracker($app_key, $_SERVER['SERVER_NAME'], $guest_uid, (isset($user_id) ? $user_id : false), (isset($visitor_info) ? $visitor_info : false));
    
    return $convead;
  }

}
