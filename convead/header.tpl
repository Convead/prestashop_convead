<!-- Convead Widget -->
{literal}
<script>
window.ConveadSettings = {
  {/literal}{if $visitor}{literal}
  visitor_uid: '{/literal}{$visitor->id}{literal}',
  visitor_info: {
    first_name: '{/literal}{$visitor->firstname}{literal}',
    last_name: '{/literal}{$visitor->lastname}{literal}',
    email: '{/literal}{$visitor->email}{literal}'
  },
  {/literal}{/if}{literal}
  app_key: "{/literal}{$app_key}{literal}"

  /* For more information on widget configuration please see:
     http://convead.ru/help/kak-nastroit-sobytiya-vizitov-prosmotrov-tovarov-napolneniya-korzin-i-pokupok-dlya-vashego-sayta
  */
};

(function(w,d,c){w[c]=w[c]||function(){(w[c].q=w[c].q||[]).push(arguments)};var ts = (+new Date()/86400000|0)*86400;var s = d.createElement('script');s.type = 'text/javascript';s.async = true;s.src = 'https://tracker.convead.io/widgets/'+ts+'/widget-{/literal}{$app_key}{literal}.js';var x = d.getElementsByTagName('script')[0];x.parentNode.insertBefore(s, x);})(window,document,'convead');
{/literal}{if $is_product_page}{literal}
  convead('event', 'view_product', {
    product_id: '{/literal}{$product_id}{literal}',
    product_name: '{/literal}{$product_name}{literal}',
    category_id: '{/literal}{$category_id}{literal}',
    product_url: window.location.href
  });
{/literal}{/if}{literal}
</script>
{/literal}
<!-- /Convead Widget -->
