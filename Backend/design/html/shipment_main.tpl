{* Відправлення, коли воно вже є: під товарами в основній колонці, як картка накладної NP. *}
{if $up_shipment && $up_shipment.mode == 'card'}{include file=$smarty.current_dir|cat:'/shipment.tpl'}{/if}
