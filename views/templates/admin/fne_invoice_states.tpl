
<div class="panel">
    <div class="panel-heading">
        <i class="icon-file-text"></i>
        {l s='Commandes FNE' mod='sendtofne_ci'}

        <span class="badge">
            {$fne_orders_count|intval}
        </span>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>{l s='N° commande' mod='sendtofne_ci'}</th>
                    <th>{l s='Date commande' mod='sendtofne_ci'}</th>
                    <th>{l s='État FNE' mod='sendtofne_ci'}</th>
                    <th>{l s='Référence facture FNE' mod='sendtofne_ci'}</th>
                    <th>{l s='Référence avoir FNE' mod='sendtofne_ci'}</th>
                    <th>{l s='Dernière opération FNE' mod='sendtofne_ci'}</th>
                </tr>
            </thead>

            <tbody>
                {if $fne_orders}
                    {foreach from=$fne_orders item=order}
                        <tr>
                            <td>
                                <a
                                    href="{$order.order_url|escape:'html':'UTF-8'}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    {$order.order_reference|escape:'html':'UTF-8'}
                                </a>
                            </td>

                            <td>
                                {$order.order_date|escape:'html':'UTF-8'}
                            </td>

                            <td>
                                {if $order.state_label == 'Avoir FNE émis'}
                                    <span class="label label-warning">
                                        {$order.state_label|escape:'html':'UTF-8'}
                                    </span>
                                {else}
                                    <span class="label label-success">
                                        {$order.state_label|escape:'html':'UTF-8'}
                                    </span>
                                {/if}
                            </td>

                            <td>
                                {if $order.invoice_reference}
                                    {$order.invoice_reference|escape:'html':'UTF-8'}
                                {else}
                                    —
                                {/if}
                            </td>

                            <td>
                                {if $order.refund_reference}
                                    {$order.refund_reference|escape:'html':'UTF-8'}
                                {else}
                                    —
                                {/if}
                            </td>

                            <td>
                                {$order.last_event_date|escape:'html':'UTF-8'}
                            </td>
                        </tr>
                    {/foreach}
                {else}
                    <tr>
                        <td colspan="6" class="text-center">
                            {l s='Aucune commande avec une facture FNE réussie.' mod='sendtofne_ci'}
                        </td>
                    </tr>
                {/if}
            </tbody>
        </table>
    </div>
</div>
