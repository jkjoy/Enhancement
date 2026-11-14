<?php

$enhancementListSortUrl = isset($enhancementListSortUrl) ? trim((string)$enhancementListSortUrl) : '';
$enhancementListSortField = isset($enhancementListSortField) ? trim((string)$enhancementListSortField) : 'lid';
$enhancementListHighlightPanel = !empty($enhancementListHighlightPanel);
?>
<script type="text/javascript">
(function () {
    $(document).ready(function () {
        var table = $('.typecho-list-table');

        <?php if ($enhancementListSortUrl !== ''): ?>
        table = table.tableDnD({
            onDrop : function () {
                var ids = [];

                $('input[type=checkbox]', table).each(function () {
                    ids.push($(this).val());
                });

                var payload = {};
                payload[<?php echo json_encode($enhancementListSortField); ?>] = ids;
                $.post(<?php echo json_encode($enhancementListSortUrl); ?>, $.param(payload));

                $('tr', table).each(function (i) {
                    if (i % 2) {
                        $(this).addClass('even');
                    } else {
                        $(this).removeClass('even');
                    }
                });
            }
        });
        <?php endif; ?>

        table.tableSelectable({
            checkEl     :   'input[type=checkbox]',
            rowEl       :   'tr',
            selectAllEl :   '.typecho-table-select-all',
            actionEl    :   '.dropdown-menu a'
        });

        $('.btn-drop').dropdownMenu({
            btnEl       :   '.dropdown-toggle',
            menuEl      :   '.dropdown-menu'
        });

        <?php if ($enhancementListHighlightPanel): ?>
        $('.typecho-mini-panel').effect('highlight', '#AACB36');
        <?php endif; ?>
    });
})();
</script>
