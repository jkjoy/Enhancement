<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/TemplateHelper.php';

use Typecho\Widget;
use Widget\Notice;
use Widget\Options;

/* @var Options $options */
Options::alloc()->to($options);
\Widget\User::alloc()->pass('administrator');

/** 初始化上下文 */
$request = $options->request;
$response = $options->response;
$current = $request->get('act', 'index');
$theme = $request->get('file', 'owner.html');
$othertheme = $request->get('theme', Enhancement_Plugin::commentNotifierConfigStr('template', 'default'));
try {
    Enhancement_CommentNotifier_TemplateHelper::resolve($othertheme, $theme, true);
} catch (RuntimeException $error) {
    throw new Typecho_Widget_Exception(_t('模板文件不存在'), 404);
}
$name = $othertheme;

$title = '编辑邮件模板 ' . $theme;
if ($current == 'index') {
    $title = '邮件发信模板';
}

if ($request->get('change') !== null) {
    if (!$request->isPost()) {
        $response->setStatus(405)->setHeader('Allow', 'POST');
        exit;
    }
    \Widget\Security::alloc()->protect();
    try {
        $selected = $request->get('change');
        Enhancement_CommentNotifier_TemplateHelper::resolve($selected, 'owner.html');
    } catch (RuntimeException $error) {
        throw new Typecho_Widget_Exception(_t('模板文件不存在'), 400);
    }
    Enhancement_ConfigStorageHelper::configHandle(array('template' => $selected), false);
    Notice::alloc()->set(_t('邮件模板启用成功'), 'success');
    $response->redirect($options->adminUrl . 'extending.php?panel=' . urlencode(Enhancement_Plugin::$commentNotifierPanel));
}

if ($request->is('do=editTheme')) {
    if (!$request->isPost()) {
        $response->setStatus(405)->setHeader('Allow', 'POST');
        exit;
    }
    \Widget\Security::alloc()->protect();
    try {
        Enhancement_CommentNotifier_TemplateHelper::write($othertheme, $request->edit, $request->content);
        Notice::alloc()->set(_t('邮件模板更改已经保存'), 'success');
    } catch (RuntimeException $error) {
        throw new Typecho_Widget_Exception(_t('邮件模板保存失败，请检查文件名和写入权限'), 400);
    }
    $response->goBack();
}
include 'header.php';
include 'menu.php';

class Enhancement_CommentNotifier_Console extends Typecho_Widget
{
    /**
     * 当前文件
     *
     * @access private
     * @var string
     */
    private $_currentFile;

    /**
     * 执行函数
     *
     * @access public
     * @return void
     * @throws Typecho_Widget_Exception
     */
    public function execute()
    {
        $template = $this->request->get('theme', Enhancement_Plugin::commentNotifierConfigStr('template', 'default'));
        $directory = Enhancement_CommentNotifier_TemplateHelper::directory($template);
        $files = glob($directory . '/*.{html,HTML}', GLOB_BRACE);

        $this->_currentFile = $this->request->get('file', 'owner.html');

        Enhancement_CommentNotifier_TemplateHelper::resolve($template, $this->_currentFile);
        foreach ($files as $file) {
            if (is_file($file) && !is_link($file)) {
                $file = basename($file);
                $this->push(array(
                    'file' => $file,
                    'current' => ($file == $this->_currentFile)
                ));
            }
        }
    }

    /**
     * 获取文件内容
     *
     * @access public
     * @return string
     */
    public function currentContent(): string
    {
        $template = $this->request->get('theme', Enhancement_Plugin::commentNotifierConfigStr('template', 'default'));
        return htmlspecialchars(Enhancement_CommentNotifier_TemplateHelper::read($template, $this->_currentFile), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * 获取文件是否可读
     *
     * @access public
     * @return bool
     */
    public function currentIsWriteable(): bool
    {
        $template = $this->request->get('theme', Enhancement_Plugin::commentNotifierConfigStr('template', 'default'));
        return is_writeable(Enhancement_CommentNotifier_TemplateHelper::resolve($template, $this->_currentFile));
    }

    /**
     * 获取当前文件
     *
     * @access public
     * @return string
     */
    public function currentFile(): string
    {
        return $this->_currentFile;
    }
}

?>
<style>
.typecho-theme-list img{
    width: 100%;
    object-fit: cover;
}
</style>
<div class="main">
    <div class="body container">
        <div class="typecho-page-title">
            <h2><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
        </div>
        <div class="row typecho-page-main" role="main">
            <div class="col-mb-12">
                <ul class="typecho-option-tabs fix-tabs clearfix">
                    <li<?= ($current == 'index' ? ' class="current"' : '') ?>><a
                            href="<?php $options->adminUrl('extending.php?panel=' . Enhancement_Plugin::$commentNotifierPanel . '&act=index'); ?>">
                            <?php _e('模板列表'); ?>
                        </a></li>
                    <li<?= ($current == 'theme' ? ' class="current"' : '') ?>><a
                            href="<?php $options->adminUrl('extending.php?panel=' . Enhancement_Plugin::$commentNotifierPanel . '&act=theme&theme='.$othertheme); ?>">
                            <?php echo htmlspecialchars('编辑' . $name . '邮件模板', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                        </a></li>
                    <li>
                        <a href="<?php $options->adminUrl('options-plugin.php?config=Enhancement') ?>"><?php _e('插件设置'); ?></a>
                    </li>
                </ul>
            </div>

            <?php if ($current == 'index'): ?>

                <?php include(dirname(__FILE__) . '/themes.php'); ?>


            <?php else: ?>
                <?php
                /** @var Enhancement_CommentNotifier_Console $files */
                Widget::widget('Enhancement_CommentNotifier_Console')->to($files);
                ?>
                <div class="typecho-edit-theme">
                    <div class="col-mb-12 col-tb-8 col-9 content">
                        <form method="post" name="theme" id="theme"
                              action="<?php $options->adminUrl('extending.php?panel=' . Enhancement_Plugin::$commentNotifierPanel . '&act=theme&theme=' .$othertheme. '&file=' . $files->file); ?>">
                            <label for="content" class="sr-only"><?php _e('编辑源码'); ?></label>
                            <input type="hidden" name="_" value="<?php echo htmlspecialchars(\Widget\Security::alloc()->getToken($request->getRequestUrl()), ENT_QUOTES, 'UTF-8'); ?>"/>
                            <textarea name="content" id="content" class="w-100 mono"
                                      <?php if (!$files->currentIsWriteable()): ?>readonly<?php endif; ?>><?php echo $files->currentContent(); ?></textarea>
                            <p class="submit">
                                <?php if ($files->currentIsWriteable()): ?>
                                    <input type="hidden" name="do" value="editTheme"/>
                                    <input type="hidden" name="edit" value="<?php echo $files->currentFile(); ?>"/>
                                    <button type="submit" class="btn primary"><?php _e('保存文件'); ?></button>
                                    <a href="<?php echo $options->pluginUrl; ?>/Enhancement/CommentNotifier/template/preview.php?file=<?php echo $theme; ?>&theme=<?php echo $othertheme; ?>" target="_blank" id="btn-preview" class="btn" style="align-items: center;display: inline-flex;"><i class="i-exlink"></i> <?php _e('预览模板'); ?></a>
                                <?php else: ?>
                                    <em><?php _e('此文件无法写入'); ?></em>
                                <?php endif; ?>
                            </p>
                        </form>
                    </div>
                    <ul class="col-mb-12 col-tb-4 col-3">
                        <li><strong><?php _e("模板文件"); ?></strong></li>
                        <?php while ($files->next()): ?>
                            <li<?php if ($files->current): ?> class="current"<?php endif; ?>>
                                <a href="<?php $options->adminUrl('extending.php?panel=' . Enhancement_Plugin::$commentNotifierPanel . '&act=theme' . '&file=' . $files->file.'&theme='.$othertheme); ?>"><?php $files->file(); ?></a>
                            </li>
                        <?php endwhile; ?>
                        <li><strong><?php _e("参数说明"); ?></strong></li>
                        <li><?php _e("文章标题：{title}"); ?></li>
                        <li><?php _e("文章作者昵称：{PostAuthor}"); ?></li>
                        <li><?php _e("评论发出时间：{time}"); ?></li>
                        <li><?php _e("评论内容：{commentText}"); ?></li>
                        <li><?php _e("评论人昵称：{author}"); ?></li>
                        <li><?php _e("评论者邮箱：{mail}"); ?></li>
                        <li><?php _e("评论者邮箱md5：{md5}"); ?></li>
                        <li><?php _e("评论者ip：{ip}"); ?></li>
                        <li><?php _e("评论楼层链接：{permalink}"); ?></li>
                        
                        <?php if ($request->file == 'guest.html'): ?>
                            <li><?php _e("父评论昵称：{Pname}"); ?></li>
                            <li><?php _e("父评论内容：{Ptext}"); ?></li>
                            <li><?php _e("父评论邮箱：{Pmail}"); ?></li>
                            <li><?php _e("父评论邮箱md5：{Pmd5}"); ?></li>
                        <?php endif; ?>
                            
                        <li><?php _e("网站地址：{siteUrl}"); ?></li>
                        <li><?php _e("网站标题：{siteTitle}"); ?></li>
                        <li><?php _e("当前模板文件夹路径：{url}"); ?></li>
                        <li><?php _e("后台管理评论的入口链接：{manageurl}"); ?></li>
                        <li><strong><?php _e("文件说明"); ?></strong></li>
                        <li><?php _e("notice.html：待审核评论通知模板"); ?></li>
                        <li><?php _e("owner.html：文章作者邮件提醒模板"); ?></li>
                        <li><?php _e("guest.html：游客评论回复提醒模板"); ?></li>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
include 'copyright.php';
include 'common-js.php';
include 'footer.php';
?>
