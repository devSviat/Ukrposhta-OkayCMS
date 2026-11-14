{* Нове відправлення, перевірка невідомого створення й відмова: у правій колонці, поруч із пунктом видачі. *}
{if $up_shipment && $up_shipment.mode != 'card'}{include file=$smarty.current_dir|cat:'/shipment.tpl'}{/if}
