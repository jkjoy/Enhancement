<?php

/** Signed management configuration for the independent QQ service. */
class Enhancement_QqCommandSettingsHelper
{
    public static function commandsSupported($settings): bool
    {
        return ($settings->qq_notify_provider ?? '') === 'standalone';
    }

    public static function validateSettings(array $settings)
    {
        foreach (array('qq_agent_commands', 'qq_agent_command_secret', 'qq_agent_allowed_senders', 'qq_agent_admin_uid') as $key) {
            if (isset($settings[$key]) && !is_scalar($settings[$key])) {
                throw new Typecho_Widget_Exception(_t('QQ 指令配置必须是文本或开关值'), 400);
            }
        }
        if (($settings['qq_agent_commands'] ?? '0') !== '1') {
            return;
        }
        if (!self::commandsSupported((object)$settings)) {
            throw new Typecho_Widget_Exception(_t('启用 QQ 侧指令时请选择独立 QQ 通道'), 400);
        }
        $secret = $settings['qq_agent_command_secret'] ?? '';
        if (!is_string($secret) || !preg_match('/\A[\x21-\x7e]{32,1024}\z/D', $secret)) {
            throw new Typecho_Widget_Exception(_t('QQ 指令密钥须为 32 至 1024 位非空白 ASCII 字符'), 400);
        }
        if (hash_equals((string)($settings['qq_official_token'] ?? ''), $secret)) {
            throw new Typecho_Widget_Exception(_t('指令密钥必须与通知凭据不同'), 400);
        }
        $senders = self::allowedSenders((object)$settings);
        if (count($senders) < 1 || count($senders) > 20) {
            throw new Typecho_Widget_Exception(_t('请填写 1 至 20 个允许操作博客的 QQ 用户 OpenID'), 400);
        }
        foreach ($senders as $sender) {
            if (!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $sender)) {
                throw new Typecho_Widget_Exception(_t('QQ 指令用户 OpenID 格式无效，不支持通配符'), 400);
            }
        }
        if (!preg_match('/\A[1-9][0-9]{0,9}\z/D', (string)($settings['qq_agent_admin_uid'] ?? ''))) {
            throw new Typecho_Widget_Exception(_t('请填写执行 QQ 指令的 Typecho 管理员 UID'), 400);
        }
    }

    public static function allowedSenders($settings): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)($settings->qq_agent_allowed_senders ?? ''))), 'strlen')));
    }
}
