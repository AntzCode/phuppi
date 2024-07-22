$(() => {
    $('.clickable').click((event) => {
        let button = event.currentTarget;
        if ($(button).data('url')) {
            window.location = $(button).data('url');
        }
    });
    $('.clickable-confirm').click((event) => {
        let button = event.currentTarget;
        if ($(button).data('confirm')) {
            if (confirm($(button).data('confirm'))) {
                let action = eval($(button).data('action'))
                if (typeof action == 'function') {
                    action(event);
                }
            }
        }
    });
    $('.ui.dropdown').dropdown();
    $('.copy-to-clipboard').click((event) => {
        let button = event.currentTarget;
        if ($(button).data('content')) {
            navigator.clipboard.writeText($(button).data('content'));
            $.toast({ message: '"' + $(button).data('content') + '" copied to clipboard!' })
        }
    });
    $('.tabular.menu .item').tab();
    $('input[type=checkbox].multiple-select-all').change((event) => {
        let checkbox = event.currentTarget;
        if(checkbox.checked){
            $('input[type=checkbox].multiple-select').prop('checked', true);
        }else{
            $('input[type=checkbox].multiple-select').prop('checked', false);
        }
    });
    $('select[name=multiple-select-action]').on('change', (event) => {
        let select = event.currentTarget;
        switch($(select).val()){
            case 'download':
                let downloadIds = $('input[type=checkbox].multiple-select:checked').get().map((chk) => $(chk).val());
                $(select).val('').change();
                setTimeout(() => {
                    window.location = 'file.php?id='+JSON.stringify(downloadIds);
                }, 300);
            break;
        }
    });
});
