<?php
// This file is also accessed directly, so initialize Typecho before checking access.
if (!defined('__TYPECHO_ROOT_DIR__')) {
    $root = dirname(__DIR__, 5);
    if (!is_file($root . '/config.inc.php')) {
        http_response_code(404);
        exit;
    }
    require_once $root . '/config.inc.php';
}
Typecho_Widget::widget('Widget_Init');
$user = Typecho_Widget::widget('Widget_User');
if (!$user->hasLogin() || !$user->pass('administrator', true)) {
    Typecho_Response::getInstance()->setStatus(403);
    exit;
}
require_once dirname(__DIR__) . '/TemplateHelper.php';
$theme = isset($_GET['theme']) ? $_GET['theme'] : 'default';
$file = isset($_GET['file']) ? $_GET['file'] : 'owner.html';
try {
    Enhancement_CommentNotifier_TemplateHelper::resolve($theme, $file, true);
} catch (RuntimeException $error) {
    Typecho_Response::getInstance()->setStatus(404);
    exit;
}
header("Content-Security-Policy: sandbox; default-src 'none'; img-src https: http: data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'");
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
    /**
     * 获取评论模板
     *
     * @param template owner 为博主 guest 为访客
     * @return false|string
     */
function getTemplate($templateDir = 'default',$template = 'owner.html')
    {
$content=Enhancement_CommentNotifier_TemplateHelper::read($templateDir, $template, true);
$content=preg_replace('#<\?php#', '<!--', $content);
$content=preg_replace('#\?>#', '-->', $content);
$demouser = array(
    array('name' => '月宅', 'md5' => 'bf413cdf4570464b971cb6e0f0a0437a'),
    array('name' => '念', 'md5' => '138be792998aef019362d52276290752'),
    array('name' => 'Jochen', 'md5' => '076176b67617855818a00f3e5f963262'),
    array('name' => '吃猫的鱼', 'md5' => 'e2909ce0b9d612c601733aea588c0097'),
    array('name' => '清酒', 'md5' => '60dfa69a58fae040fb0feb753bef1535'),
    array('name' => '凡涛', 'md5' => 'b8f7b5a08bcba93c2c3eff7b1c5a7c32'),
);

// 随机选择两个不同的数组元素
$index1 = rand(0, count($demouser) - 1);
$index2 = array_rand(array_diff_key($demouser, [$index1 => '']));
$avatarUrl = 'https://cn.cravatar.com/avatar/' . $demouser[$index1]['md5'] . '?d=monsterid&s=40';
$PavatarUrl = 'https://cn.cravatar.com/avatar/' . $demouser[$index2]['md5'] . '?d=monsterid&s=40';

$search = array(
            '{title}',//文章标题
            '{PostAuthor}',//文章作者昵称
            '{time}',//评论发出时间
            '{commentText}',//评论内容
            '{author}',//评论人昵称
            '{mail}',//评论者邮箱
            '{md5}',//评论者邮箱
            '{avatar}',//评论者头像
            '{ip}',//评论者ip
            '{permalink}',//评论楼层链接
            '{siteUrl}',//网站地址
            '{siteTitle}',//网站标题
            '{Pname}',//父级评论昵称
            '{Ptext}',//父级评论内容
            '{Pmail}',//父级评论邮箱
            '{Pmd5}',//父级评论邮箱md5
            '{Pavatar}',//父级评论头像
            '{url}',//当前模板文件夹路径
            '{manageurl}',//后台管理评论的入口链接
            '{status}', //评论状态
        );
        $replace = array(
            'CommentNotifier邮件提醒插件',
            '泽泽社长',
            date('Y-m-d H:i:s'),
            '这个插件真好用！',
            $demouser[$index1]['name'],
            'bssf@qq.com',
            $demouser[$index1]['md5'],
            $avatarUrl,
            '192.168.1.1',
            'https://github.com/jrotty/CommentNotifier',
            'https://typecho.work',
            '泽泽社',
            $demouser[$index2]['name'],
            '这个插件真好用!',
            'zezeshe@foxmail.com',
            $demouser[$index2]['md5'],
            $PavatarUrl,
            './' . $templateDir.'/',
            'https://typecho.work',
            '通过'
        );

        return str_replace($search, $replace, $content);
    }
?>
<html lang="zh-CN">
<head> 
<meta charset="UTF-8">
<meta name="renderer" content="webkit">
<meta name="viewport" content="width=device-width,user-scalable=no,viewport-fit=cover,initial-scale=1, maximum-scale=1">
<title>预览<?php echo htmlspecialchars($file, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></title>
</head>
<body>
    
<?php echo getTemplate($theme,$file); ?>
    
</body>
</html>
