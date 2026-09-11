<div class="btn-group">

{* FACTURE FNE *}
{if $already_sent}
    <span class="btn btn-default disabled">
        Déjà envoyé à la FNE
    </span>
{else}
    <a href="{$sendtofne_url}" class="btn btn-primary" onclick="return confirm('Confirmer l’envoi de la facture vers la FNE ?\n\nCette action est fiscale et irréversible.');">
        Envoi vers FNE
    </a>
{/if}

{* ================= AVOIR FNE ================= *}

{if $already_refunded}
    <span class="btn btn-default disabled">
        Avoir : Déjà généré sur FNE
    </span>

{elseif !$canRefund}
    <span class="btn btn-default disabled">
        Avoir FNE indisponible
    </span>

{else}
    <a href="{$refund_url}" class="btn btn-warning" onclick="return confirm('Confirmer la génération de l’avoir FNE ?\n\nCette action est fiscale et irréversible.');">
        Avoir FNE
    </a>
{/if}


</div>
