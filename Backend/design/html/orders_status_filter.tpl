{if $up_status_groups}
    <div class="col-md-3 col-lg-3 col-sm-12">
        <select class="selectpicker form-control" onchange="location = this.value;" aria-label="{$btr->sviat__ukrposhta__list_filter|escape}">
            <option value="{url up_shipment=null page=null}" {if !$up_status_group}selected{/if}>{$btr->sviat__ukrposhta__list_filter_all|escape}</option>
            {foreach $up_status_groups as $group => $groupKey}
                <option value="{url up_shipment=$group page=null}" {if $up_status_group == $group}selected{/if}>{$btr->getTranslation($groupKey)|escape}</option>
            {/foreach}
        </select>
    </div>
{/if}
