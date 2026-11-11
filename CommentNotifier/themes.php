<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    http_response_code(404);
    exit;
}

$plugin = Enhancement_Plugin::runtimeSettings();
$template = isset($plugin->template) ? (string)$plugin->template : 'default';
$indexUrl = $options->adminUrl . 'extending.php?panel=' . urlencode(Enhancement_Plugin::$commentNotifierPanel) . '&act=index';
$token = \Widget\Security::alloc()->getToken($request->getRequestUrl());
$themes = glob(__DIR__ . '/template/*', GLOB_ONLYDIR) ?: array();
usort($themes, static function ($left, $right) use ($template) {
    return (basename($right) === $template ? 1 : 0) <=> (basename($left) === $template ? 1 : 0);
});
?>
<div class="typecho-table-wrap">
    <table class="typecho-list-table typecho-theme-list">
        <colgroup><col width="35%"/><col/></colgroup>
        <thead><tr><th><?php _e('截图'); ?></th><th><?php _e('详情'); ?></th></tr></thead>
        <tbody>
        <?php foreach ($themes as $themeDirectory):
            $name = basename($themeDirectory);
            try {
                $themeFile = Enhancement_CommentNotifier_TemplateHelper::resolve($name, 'owner.html');
            } catch (RuntimeException $error) {
                continue;
            }
            $info = \Typecho\Plugin::parseInfo($themeFile);
            $screens = array_filter(glob($themeDirectory . '/*') ?: array(), static function ($path) {
                return !is_link($path) && preg_match('~/screenshot\.(?:jpg|png|gif|bmp|jpeg|webp)$~i', $path);
            });
            $image = $screens
                ? Enhancement_Plugin::appendVersionToAssetUrl($options->pluginUrl . '/Enhancement/CommentNotifier/template/' . rawurlencode($name) . '/' . rawurlencode(basename(current($screens))))
                : \Typecho\Common::url('noscreen.png', $options->adminStaticUrl('img'));
            $editUrl = $options->adminUrl . 'extending.php?panel=' . urlencode(Enhancement_Plugin::$commentNotifierPanel) . '&act=theme&theme=' . rawurlencode($name);
            $previewUrl = $options->pluginUrl . '/Enhancement/CommentNotifier/template/preview.php?file=owner.html&theme=' . rawurlencode($name);
            $homepage = Enhancement_MailHelper::safeUrl($info['homepage'] ?? '');
            ?>
            <tr>
                <td valign="top"><img src="<?php echo Enhancement_MailHelper::escape($image); ?>" alt=""/></td>
                <td valign="top">
                    <h3><?php echo Enhancement_MailHelper::escape($info['title'] ?? $name); ?></h3>
                    <cite>
                        <?php if (!empty($info['author'])): ?>
                            作者：<?php if ($homepage !== ''): ?><a href="<?php echo Enhancement_MailHelper::escape($homepage); ?>" rel="noopener noreferrer"><?php endif; ?><?php echo Enhancement_MailHelper::escape($info['author']); ?><?php if ($homepage !== ''): ?></a><?php endif; ?>&nbsp;&nbsp;
                        <?php endif; ?>
                        <?php if (!empty($info['version'])): ?>版本：<?php echo Enhancement_MailHelper::escape($info['version']); ?><?php endif; ?>
                    </cite>
                    <p><?php echo nl2br(Enhancement_MailHelper::escape($info['description'] ?? '')); ?></p>
                    <p>
                        <a href="<?php echo Enhancement_MailHelper::escape($editUrl); ?>">编辑</a>&nbsp;
                        <a href="<?php echo Enhancement_MailHelper::escape($previewUrl); ?>" target="_blank" rel="noopener noreferrer">快速预览</a>
                        <?php if ($template === $name): ?>（当前启用）<?php endif; ?>
                    </p>
                    <?php if ($template !== $name): ?>
                        <form method="post" action="<?php echo Enhancement_MailHelper::escape($indexUrl); ?>">
                            <input type="hidden" name="_" value="<?php echo Enhancement_MailHelper::escape($token); ?>"/>
                            <input type="hidden" name="change" value="<?php echo Enhancement_MailHelper::escape($name); ?>"/>
                            <button type="submit" class="btn">启用</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
