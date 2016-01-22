<!-- Convead Widget -->
{literal}
<script>
window.ConveadSettings = {
    {/literal}{if $customer}{literal}
    visitor_uid: '{/literal}{$customer->id}{literal}',
    visitor_info: {
        first_name: '{/literal}{$customer->firstname}{literal}',
        last_name: '{/literal}{$customer->lastname}{literal}',
        email: '{/literal}{$customer->email}{literal}'
    },
    {/literal}{/if}{literal}
    {/literal}{if $is_product_page}{literal}
    onready: function() {
        convead('event', 'view_product', {
            product_id: '{/literal}{$product_id}{literal}',
            product_name: '{/literal}{$product_name}{literal}',
            category_id: '{/literal}{$category_id}{literal}',
            product_url: window.location.href
        });
    },
    {/literal}{/if}{literal}
    app_key: "{/literal}{$app_key}{literal}"

    /* For more information on widget configuration please see:
       http://convead.ru/help/kak-nastroit-sobytiya-vizitov-prosmotrov-tovarov-napolneniya-korzin-i-pokupok-dlya-vashego-sayta
    */
};

(function(w,d,c){w[c]=w[c]||function(){(w[c].q=w[c].q||[]).push(arguments)};var ts = (+new Date()/86400000|0)*86400;var s = d.createElement('script');s.type = 'text/javascript';s.async = true;s.src = 'https://tracker.convead.io/widgets/'+ts+'/widget-{/literal}{$app_key}{literal}.js';var x = d.getElementsByTagName('script')[0];x.parentNode.insertBefore(s, x);})(window,document,'convead');
</script>
{/literal}
<!-- /Convead Widget -->
