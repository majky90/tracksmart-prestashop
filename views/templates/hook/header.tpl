<script>
    {if isset($tracksmart_container)}
        {literal}
        document.addEventListener("DOMContentLoaded", function () {
                        var trackSmartInitAttempts = 0;
                        var trackSmartMaxAttempts = 100;
            var trackSmartInitInterval = setInterval(function () {
                                trackSmartInitAttempts++;

                                if (trackSmartInitAttempts > trackSmartMaxAttempts) {
                                        clearInterval(trackSmartInitInterval);
                                        return;
                                }

                if (typeof TrackSmart === 'undefined') {
                    return;
                }

                clearInterval(trackSmartInitInterval);
        {/literal}
                {if isset($tracksmart_user)}
        {literal}
                var trackSmart = new TrackSmart('{/literal}{$tracksmart_container|escape:'javascript':'UTF-8'}{literal}', {/literal}{$tracksmart_user|intval}{literal});
        {/literal}
                {else}
        {literal}
                var trackSmart = new TrackSmart('{/literal}{$tracksmart_container|escape:'javascript':'UTF-8'}{literal}');
        {/literal}
                {/if}

        {literal}
                trackSmart.build();
        {/literal}

                {if isset($tracksmart_event)}
                    {if isset($tracksmart_data)}
        {literal}
                trackSmart.process('{/literal}{$tracksmart_event|escape:'javascript':'UTF-8'}{literal}', {/literal}{$tracksmart_data|@json_encode|replace:'\u':'\\u' nofilter}{literal});
        {/literal}
                    {else}
        {literal}
                trackSmart.process('{/literal}{$tracksmart_event|escape:'javascript':'UTF-8'}{literal}');
        {/literal}
                    {/if}
                {/if}
        {literal}
            }, 100);
        });
        {/literal}
    {/if}
</script>
