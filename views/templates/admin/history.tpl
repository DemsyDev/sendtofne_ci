<div class="card mt-3">
    <div class="card-header">
        <strong>Historique FNE</strong>
    </div>

    <div class="card-body">
        {if isset($fne_logs) && $fne_logs|@count > 0}
            <table class="table table-sm table-bordered">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Code</th>
                        <th>Référence</th>
                        <th>Détail</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$fne_logs item=log}
                        <tr>
                            <td>{$log.created_at|escape:'html'}</td>
                            <td>{$log.type|escape:'html'}</td>
                            <td>{$log.response_code|escape:'html'}</td>
                            <td>{$log.fne_reference|escape:'html'}</td>
                            <td style="max-width:400px; word-break:break-word;">
                            {if $log.response_code == '200' || $log.response_code == '201'}
                                {if $log.type == 'INVOICE'}
                                    Envoi réussi à la FNE
                                {elseif $log.type == 'REFUND'}
                                    Avoir FNE généré avec succès
                                {else}
                                    Succès
                                {/if}
                            {else}
                                {$log.response_body|escape:'html'}
                            {/if}
                        </td>

                        </tr>
                    {/foreach}
                </tbody>
            </table>
        {else}
            <p class="text-muted">
                Aucun historique FNE disponible pour cette commande.
            </p>
        {/if}
    </div>
</div>
