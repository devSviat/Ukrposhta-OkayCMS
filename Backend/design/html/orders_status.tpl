{if !empty($order->up_shipment)}
    {$us = $order->up_shipment}
    <div class="up-list-status">
        {* Власна монограма, не знак Укрпошти: офіційного SVG для вбудовування у відкритому доступі немає. *}
        <svg class="up-list-status__logo" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><rect width="16" height="16" rx="4" fill="#ffc400"/><path d="M4.4 4.2l3.1 5.2 2.9-5.2M7.5 9.4l-1.3 2.4" fill="none" stroke="#1d1d1b" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
        {if $us.barcode}<a href="" class="up-list-status__barcode fn_up_list_copy hint-bottom-middle-t-info-s-small-mobile hint-anim" data-hint="{$btr->sviat__ukrposhta__list_copy|escape}" data-copy-string="{$us.barcode|escape}">{$us.barcode|escape}</a>{/if}
        {if $us.statusKey}<span class="up-ship__badge up-ship__badge--{$us.group|escape}{if $us.eventName} hint-bottom-middle-t-info-s-small-mobile hint-anim{/if}"{if $us.eventName} data-hint="{$us.eventName|escape}"{/if}>{$btr->getTranslation($us.statusKey)|escape}</span>{/if}
    </div>
{/if}
