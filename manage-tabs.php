<?php

$enhancementCurrentTab = isset($enhancementCurrentTab) ? (string)$enhancementCurrentTab : '';
$enhancementTabPreset = isset($enhancementTabPreset) ? (string)$enhancementTabPreset : '';
$enhancementTabs = isset($enhancementTabs) && is_array($enhancementTabs) ? $enhancementTabs : array();

if (empty($enhancementTabs)) {
    if ($enhancementTabPreset === 'core') {
        $enhancementTabs = array(
            'links' => array('label' => _t('链接'), 'url' => 'extending.php?panel=Enhancement/manage-enhancement.php'),
            'moments' => array('label' => _t('瞬间'), 'url' => 'extending.php?panel=Enhancement/manage-moments.php'),
            'settings' => array('label' => _t('设置'), 'url' => 'options-plugin.php?config=Enhancement')
        );
    } elseif ($enhancementTabPreset === 'summary') {
        $enhancementTabs = array(
            'summary' => array('label' => _t('摘要'), 'url' => 'extending.php?panel=Enhancement/manage-ai-summary.php'),
            'settings' => array('label' => _t('设置'), 'url' => 'options-plugin.php?config=Enhancement')
        );
    }
}
?>
<style>
.enhancement-manage-tabs{
    max-width:100%;
}
@media (max-width: 640px){
    .enhancement-manage-tabs{
        display:flex;
        flex-wrap:nowrap;
        gap:6px;
        overflow-x:auto;
        overflow-y:hidden;
        -webkit-overflow-scrolling:touch;
        scrollbar-width:none;
        touch-action:pan-x;
    }
    .enhancement-manage-tabs::-webkit-scrollbar{
        display:none;
    }
    .enhancement-manage-tabs li{
        float:none!important;
        flex:0 0 auto;
        white-space:nowrap;
    }
    .enhancement-manage-tabs a{
        -webkit-user-drag:none;
    }
}
</style>
<ul class="typecho-option-tabs clearfix enhancement-manage-tabs" data-enhancement-drag-scroll>
    <?php foreach ($enhancementTabs as $tabKey => $tab): ?>
        <?php
        $tabLabel = isset($tab['label']) ? trim((string)$tab['label']) : '';
        $tabUrl = isset($tab['url']) ? trim((string)$tab['url']) : '';
        if ($tabLabel === '' || $tabUrl === '') {
            continue;
        }
        ?>
        <li<?php if ((string)$tabKey === $enhancementCurrentTab): ?> class="current"<?php endif; ?>>
            <a href="<?php $options->adminUrl($tabUrl); ?>"><?php echo htmlspecialchars($tabLabel, ENT_QUOTES, 'UTF-8'); ?></a>
        </li>
    <?php endforeach; ?>
</ul>
