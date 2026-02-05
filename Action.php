<?php

class Enhancement_Action extends Typecho_Widget implements Widget_Interface_Do
{
    private $db;
    private $options;
    private $prefix;

    public function insertEnhancement()
    {
        if (Enhancement_Plugin::form('insert')->validate()) {
            $this->response->goBack();
        }
        /** 取出数据 */
        $item = $this->request->from('email', 'image', 'url', 'state');

        /** 过滤XSS */
        $item['name'] = $this->request->filter('xss')->name;
        $item['sort'] = $this->request->filter('xss')->sort;
        $item['description'] = $this->request->filter('xss')->description;
        $item['user'] = $this->request->filter('xss')->user;

        $maxOrder = $this->db->fetchObject(
            $this->db->select(array('MAX(order)' => 'maxOrder'))->from($this->prefix . 'links')
        )->maxOrder;
        $item['order'] = intval($maxOrder) + 1;

        /** 插入数据 */
        $item_lid = $this->db->query($this->db->insert($this->prefix . 'links')->rows($item));

        /** 设置高亮 */
        $this->widget('Widget_Notice')->highlight('enhancement-' . $item_lid);

        /** 提示信息 */
        $this->widget('Widget_Notice')->set(_t(
            '友链 <a href="%s">%s</a> 已经被增加',
            $item['url'],
            $item['name']
        ), null, 'success');

        /** 转向原页 */
        $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-enhancement.php', $this->options->adminUrl));
    }

    public function submitEnhancement()
    {
        if (Enhancement_Plugin::publicForm()->validate()) {
            $this->response->goBack();
        }

        /** 取出数据 */
        $item = $this->request->from('email', 'image', 'url');

        /** 过滤XSS */
        $item['name'] = $this->request->filter('xss')->name;
        $item['sort'] = $this->request->filter('xss')->sort;
        $item['description'] = $this->request->filter('xss')->description;
        $item['user'] = $this->request->filter('xss')->user;

        $maxOrder = $this->db->fetchObject(
            $this->db->select(array('MAX(order)' => 'maxOrder'))->from($this->prefix . 'links')
        )->maxOrder;
        $item['order'] = intval($maxOrder) + 1;
        $item['state'] = '0';

        /** 插入数据 */
        $item_lid = $this->db->query($this->db->insert($this->prefix . 'links')->rows($item));

        if ($this->request->isAjax()) {
            $this->response->throwJson(array(
                'success' => true,
                'message' => _t('提交成功，等待审核'),
                'lid' => $item_lid
            ));
        } else {
            $this->response->goBack('?enhancement_submitted=1');
        }
    }

    public function updateEnhancement()
    {
        if (Enhancement_Plugin::form('update')->validate()) {
            $this->response->goBack();
        }

        /** 取出数据 */
        $item = $this->request->from('email', 'image', 'url', 'state');
        $item_lid = $this->request->get('lid');

        /** 过滤XSS */
        $item['name'] = $this->request->filter('xss')->name;
        $item['sort'] = $this->request->filter('xss')->sort;
        $item['description'] = $this->request->filter('xss')->description;
        $item['user'] = $this->request->filter('xss')->user;

        /** 更新数据 */
        $this->db->query($this->db->update($this->prefix . 'links')->rows($item)->where('lid = ?', $item_lid));

        /** 设置高亮 */
        $this->widget('Widget_Notice')->highlight('enhancement-' . $item_lid);

        /** 提示信息 */
        $this->widget('Widget_Notice')->set(_t(
            '友链 <a href="%s">%s</a> 已经被更新',
            $item['url'],
            $item['name']
        ), null, 'success');

        /** 转向原页 */
        $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-enhancement.php', $this->options->adminUrl));
    }

    public function deleteEnhancement()
    {
        $lids = $this->request->filter('int')->getArray('lid');
        $deleteCount = 0;
        if ($lids && is_array($lids)) {
            foreach ($lids as $lid) {
                if ($this->db->query($this->db->delete($this->prefix . 'links')->where('lid = ?', $lid))) {
                    $deleteCount++;
                }
            }
        }
        /** 提示信息 */
        $this->widget('Widget_Notice')->set(
            $deleteCount > 0 ? _t('记录已经删除') : _t('没有记录被删除'),
            null,
            $deleteCount > 0 ? 'success' : 'notice'
        );

        /** 转向原页 */
        $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-enhancement.php', $this->options->adminUrl));
    }

    public function approveEnhancement()
    {
        $lids = $this->request->filter('int')->getArray('lid');
        $approveCount = 0;
        if ($lids && is_array($lids)) {
            foreach ($lids as $lid) {
                if ($this->db->query($this->db->update($this->prefix . 'links')->rows(array('state' => '1'))->where('lid = ?', $lid))) {
                    $approveCount++;
                }
            }
        }
        /** 提示信息 */
        $this->widget('Widget_Notice')->set(
            $approveCount > 0 ? _t('已通过审核') : _t('没有记录被通过'),
            null,
            $approveCount > 0 ? 'success' : 'notice'
        );

        /** 转向原页 */
        $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-enhancement.php', $this->options->adminUrl));
    }

    public function rejectEnhancement()
    {
        $lids = $this->request->filter('int')->getArray('lid');
        $rejectCount = 0;
        if ($lids && is_array($lids)) {
            foreach ($lids as $lid) {
                if ($this->db->query($this->db->update($this->prefix . 'links')->rows(array('state' => '0'))->where('lid = ?', $lid))) {
                    $rejectCount++;
                }
            }
        }
        /** 提示信息 */
        $this->widget('Widget_Notice')->set(
            $rejectCount > 0 ? _t('已驳回') : _t('没有记录被驳回'),
            null,
            $rejectCount > 0 ? 'success' : 'notice'
        );

        /** 转向原页 */
        $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-enhancement.php', $this->options->adminUrl));
    }

    public function sortEnhancement()
    {
        $items = $this->request->filter('int')->getArray('lid');
        if ($items && is_array($items)) {
            foreach ($items as $sort => $lid) {
                $this->db->query($this->db->update($this->prefix . 'links')->rows(array('order' => $sort + 1))->where('lid = ?', $lid));
            }
        }
    }

    public function emailLogo()
    {
        /* 邮箱头像解API接口 by 懵仙兔兔 */
        $type = $this->request->type;
        $email = trim((string)$this->request->email);

        if ($email == null || $email == '') {
            $this->response->throwJson('请提交邮箱链接 [email=abc@abc.com]');
            exit;
        } else if ($type == null || $type == '' || ($type != 'txt' && $type != 'json')) {
            $this->response->throwJson('请提交type类型 [type=txt, type=json]');
            exit;
        } else {
            $lower = strtolower($email);
            $qqNumber = null;
            if (is_numeric($email)) {
                $qqNumber = $email;
            } elseif (substr($lower, -7) === '@qq.com') {
                $qqNumber = substr($lower, 0, -7);
            }

            if ($qqNumber !== null && is_numeric($qqNumber) && strlen($qqNumber) < 11 && strlen($qqNumber) > 4) {
                stream_context_set_default([
                    'ssl' => [
                        'verify_host' => false,
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                    ],
                ]);
                $geturl = 'https://s.p.qq.com/pub/get_face?img_type=3&uin=' . $qqNumber;
                $headers = get_headers($geturl, TRUE);
                if ($headers) {
                    $g = $headers['Location'];
                    $g = str_replace("http:", "https:", $g);
                } else {
                    $g = 'https://q.qlogo.cn/g?b=qq&nk=' . $qqNumber . '&s=100';
                }
            } else {
                $g = Enhancement_Plugin::buildAvatarUrl($email, 100, null);
            }
            $r = array('url' => $g);
            if ($type == 'txt') {
                $this->response->throwJson($g);
                exit;
            } else if ($type == 'json') {
                $this->response->throwJson(json_encode($r));
                exit;
            }
        }
    }

    public function insertMoment()
    {
        if (Enhancement_Plugin::momentsForm('insert')->validate()) {
            $this->response->goBack();
        }

        Enhancement_Plugin::ensureMomentsTable();

        $moment = array();
        $moment['content'] = (string)$this->request->get('content');
        $moment['tags'] = $this->request->filter('xss')->tags;
        $moment['created'] = $this->options->time;
        $mediaRaw = $this->request->get('media');
        $mediaRaw = is_string($mediaRaw) ? trim($mediaRaw) : $mediaRaw;
        if (empty($mediaRaw)) {
            $cleanedContent = $moment['content'];
            $mediaItems = Enhancement_Plugin::extractMediaFromContent($moment['content'], $cleanedContent);
            $moment['media'] = !empty($mediaItems) ? json_encode($mediaItems, JSON_UNESCAPED_UNICODE) : null;
            $moment['content'] = $cleanedContent;
        } else {
            $moment['media'] = $mediaRaw;
        }

        try {
            $mid = $this->db->query($this->db->insert($this->prefix . 'moments')->rows($moment));
        } catch (Exception $e) {
            $this->widget('Widget_Notice')->set(_t('瞬间发布失败，可能是数据表不存在'), null, 'error');
            $this->response->goBack();
        }

        $this->widget('Widget_Notice')->highlight('moment-' . $mid);
        $this->widget('Widget_Notice')->set(_t('瞬间已发布'), null, 'success');
        $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-moments.php', $this->options->adminUrl));
    }

    public function updateMoment()
    {
        if (Enhancement_Plugin::momentsForm('update')->validate()) {
            $this->response->goBack();
        }

        Enhancement_Plugin::ensureMomentsTable();

        $moment = array();
        $moment['content'] = (string)$this->request->get('content');
        $moment['tags'] = $this->request->filter('xss')->tags;
        $mid = $this->request->get('mid');
        $mediaRaw = $this->request->get('media');
        $mediaRaw = is_string($mediaRaw) ? trim($mediaRaw) : $mediaRaw;
        if (empty($mediaRaw)) {
            $cleanedContent = $moment['content'];
            $mediaItems = Enhancement_Plugin::extractMediaFromContent($moment['content'], $cleanedContent);
            $moment['media'] = !empty($mediaItems) ? json_encode($mediaItems, JSON_UNESCAPED_UNICODE) : null;
            $moment['content'] = $cleanedContent;
        } else {
            $moment['media'] = $mediaRaw;
        }

        try {
            $this->db->query($this->db->update($this->prefix . 'moments')->rows($moment)->where('mid = ?', $mid));
        } catch (Exception $e) {
            $this->widget('Widget_Notice')->set(_t('瞬间更新失败，可能是数据表不存在'), null, 'error');
            $this->response->goBack();
        }

        $this->widget('Widget_Notice')->highlight('moment-' . $mid);
        $this->widget('Widget_Notice')->set(_t('瞬间已更新'), null, 'success');
        $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-moments.php', $this->options->adminUrl));
    }

    public function deleteMoment()
    {
        $mids = $this->request->filter('int')->getArray('mid');
        $deleteCount = 0;
        if ($mids && is_array($mids)) {
            foreach ($mids as $mid) {
                try {
                    if ($this->db->query($this->db->delete($this->prefix . 'moments')->where('mid = ?', $mid))) {
                        $deleteCount++;
                    }
                } catch (Exception $e) {
                    // ignore delete errors
                }
            }
        }
        $this->widget('Widget_Notice')->set(
            $deleteCount > 0 ? _t('瞬间已经删除') : _t('没有瞬间被删除'),
            null,
            $deleteCount > 0 ? 'success' : 'notice'
        );
        $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-moments.php', $this->options->adminUrl));
    }

    public function action()
    {
        $this->db = Typecho_Db::get();
        $this->prefix = $this->db->getPrefix();
        $this->options = Typecho_Widget::widget('Widget_Options');

        $action = $this->request->get('action');
        $pathInfo = $this->request->getPathInfo();
        $hasContent = false;
        $this->request->get('content', null, $hasContent);
        $hasMid = false;
        $this->request->get('mid', null, $hasMid);
        $hasMidArray = !empty($this->request->getArray('mid'));

        if ($action === 'enhancement-submit' || $this->request->is('do=submit')) {
            Helper::security()->protect();
            $this->submitEnhancement();
            return;
        }

        $isMomentsAction = ($action === 'enhancement-moments-edit')
            || (is_string($pathInfo) && strpos($pathInfo, 'enhancement-moments-edit') !== false)
            || $hasContent
            || $hasMid
            || $hasMidArray;

        if ($isMomentsAction) {
            Helper::security()->protect();
            $user = Typecho_Widget::widget('Widget_User');
            $user->pass('administrator');

            $this->on($this->request->is('do=insert'))->insertMoment();
            $this->on($this->request->is('do=update'))->updateMoment();
            $this->on($this->request->is('do=delete'))->deleteMoment();
            $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-moments.php', $this->options->adminUrl));
            return;
        }

        Helper::security()->protect();
        $user = Typecho_Widget::widget('Widget_User');
        $user->pass('administrator');

        $this->on($this->request->is('do=insert'))->insertEnhancement();
        $this->on($this->request->is('do=update'))->updateEnhancement();
        $this->on($this->request->is('do=delete'))->deleteEnhancement();
        $this->on($this->request->is('do=approve'))->approveEnhancement();
        $this->on($this->request->is('do=reject'))->rejectEnhancement();
        $this->on($this->request->is('do=sort'))->sortEnhancement();
        $this->on($this->request->is('do=email-logo'))->emailLogo();
        $this->response->redirect($this->options->adminUrl);
    }
}

/** Enhancement */
