<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class Enhancement_Go_Action extends Typecho_Widget implements Widget_Interface_Do
{
    public function execute()
    {
        // no-op
    }

    public function action()
    {
        require_once __DIR__ . '/Renderer.php';
        Enhancement_Go_Renderer::respond($this->request->get('target'), $this->response);
    }
}
