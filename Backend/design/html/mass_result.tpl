{if $up_mass_result}
    <div class="row fn_up_mass_result">
        <div class="col-lg-12 col-md-12 col-sm-12">
            <div class="boxed up-mass">
                <div class="up-mass__head">
                    <div class="up-mass__title">{$btr->sviat__ukrposhta__mass_heading|escape}{if $up_mass_result.title}: {$up_mass_result.title|escape}{/if}</div>
                    <button type="button" class="up-mass__hide fn_up_mass_hide">{$btr->sviat__ukrposhta__mass_hide|escape}</button>
                </div>
                {if $up_mass_result.error}
                    <div class="up-mass__row up-mass__row--failed">{$up_mass_result.error|escape}</div>
                {/if}
                {foreach $up_mass_result.rows as $row}
                    <div class="up-mass__row up-mass__row--{$row.kind|escape}"><a href="{url controller=OrderAdmin id=$row.orderId return=$smarty.server.REQUEST_URI}">№ {$row.orderId|escape}</a> — {$row.text|escape}</div>
                {/foreach}
                {if $up_mass_result.untouched}
                    <div class="up-mass__note">{$up_mass_result.untouched|escape}</div>
                {/if}
            </div>
        </div>
    </div>
{/if}
