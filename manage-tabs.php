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
        cursor:grab;
        user-select:none;
    }
    .enhancement-manage-tabs::-webkit-scrollbar{
        display:none;
    }
    .enhancement-manage-tabs.is-dragging{
        cursor:grabbing;
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
<script>
(function () {
    if (window.__enhancementDragScrollBooted) {
        return;
    }
    window.__enhancementDragScrollBooted = true;

    function enableDragScroll(scroller) {
        if (!scroller || scroller.__enhancementDragScrollReady) {
            return;
        }
        scroller.__enhancementDragScrollReady = true;

        var dragging = false;
        var moved = false;
        var startX = 0;
        var startLeft = 0;

        scroller.addEventListener('pointerdown', function (event) {
            if (event.button !== 0 || scroller.scrollWidth <= scroller.clientWidth) {
                return;
            }
            dragging = true;
            moved = false;
            startX = event.clientX;
            startLeft = scroller.scrollLeft;
            scroller.classList.add('is-dragging');
            if (scroller.setPointerCapture) {
                scroller.setPointerCapture(event.pointerId);
            }
        });

        scroller.addEventListener('pointermove', function (event) {
            if (!dragging) {
                return;
            }
            var delta = event.clientX - startX;
            if (Math.abs(delta) > 3) {
                moved = true;
                event.preventDefault();
            }
            scroller.scrollLeft = startLeft - delta;
        });

        function stopDrag(event) {
            if (!dragging) {
                return;
            }
            dragging = false;
            scroller.classList.remove('is-dragging');
            if (scroller.releasePointerCapture && event && event.pointerId) {
                try {
                    scroller.releasePointerCapture(event.pointerId);
                } catch (e) {}
            }
            window.setTimeout(function () {
                moved = false;
            }, 0);
        }

        scroller.addEventListener('pointerup', stopDrag);
        scroller.addEventListener('pointercancel', stopDrag);
        scroller.addEventListener('mouseleave', stopDrag);
        scroller.addEventListener('click', function (event) {
            if (moved) {
                event.preventDefault();
                event.stopPropagation();
            }
        }, true);
    }

    function init() {
        var scrollers = document.querySelectorAll('[data-enhancement-drag-scroll]');
        for (var i = 0; i < scrollers.length; i++) {
            enableDragScroll(scrollers[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
