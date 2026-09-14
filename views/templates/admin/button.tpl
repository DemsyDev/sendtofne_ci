<div class="btn-group">

{* FACTURE FNE *}
{if $already_sent}
    <span class="btn btn-default disabled">
        Déjà envoyé à la FNE
    </span>
{else}
    <!-- <a href="{$sendtofne_url}" class="btn btn-primary" onclick="return confirm('Confirmer l’envoi de la facture vers la FNE ?\n\nCette action est fiscale et irréversible.');">
        Envoi vers FNE
    </a> -->
    <form method="post" action="{$sendtofne_url|escape:'htmlall':'UTF-8'}" style="display:inline-block; margin:0;">

        <div style="display:inline-block; margin-right:8px;">

            <label for="fne_payment_method" style="margin-right:5px;">
                Mode de paiement :
            </label>

            <select name="fne_payment_method" id="fne_payment_method" class="form-control" style="display:inline-block; width:auto;">
                <option value="cash" {if $fne_default_payment_method == 'cash'}selected{/if} >
                    Espèces
                </option>

                <option value="card" {if $fne_default_payment_method == 'card'}selected{/if} >
                    Carte
                </option>

                <option value="check" {if $fne_default_payment_method == 'check'}selected{/if} >
                    Chèque
                </option>

                <option value="mobile-money" {if $fne_default_payment_method == 'mobile-money'}selected{/if} >
                    Mobile Money
                </option>

                <option value="transfer" {if $fne_default_payment_method == 'transfer'}selected{/if} >
                    Virement
                </option>

                <option value="deferred" {if $fne_default_payment_method == 'deferred'}selected{/if} >
                    Paiement à terme
                </option>
            </select>

        </div>

        <div style="display:inline-block; margin-right:8px;">

            <label for="fne_template" style="margin-right:5px;" >
                Template :
            </label>

            <select name="fne_template" id="fne_template" class="form-control" style="display:inline-block; width:auto;" >
                <option value="B2B" {if $fne_default_template == 'B2B'}selected{/if} >
                    B2B
                </option>

                <option value="B2F" {if $fne_default_template == 'B2F'}selected{/if} >
                    B2F
                </option>

                <option value="B2G" {if $fne_default_template == 'B2G'}selected{/if} >
                    B2G
                </option>

                <option value="B2C" {if $fne_default_template == 'B2C'}selected{/if} >
                    B2C
                </option>
            </select>

        </div>

        <button type="submit" class="btn btn-primary"
            onclick="return confirm(
                'Confirmer l’envoi de la facture vers la FNE ?\n\n' +
                'Cette action est fiscale et irréversible.'
            );"
        >
            Envoi vers FNE
        </button>

    </form>
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
