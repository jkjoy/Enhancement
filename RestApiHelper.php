<?php

class Enhancement_RestApiHelper
{
    public static function enabled($settings = null): bool
    {
        if ($settings === null) {
            $settings = Enhancement_Plugin::runtimeSettings();
        }

        return isset($settings->enable_rest_api) && (string)$settings->enable_rest_api === '1';
    }

    public static function routes(): array
    {
        $endpoints = array(
            'root' => array('', 'root'),
            'wp_v2' => array('/wp/v2', 'wpv2'),
            'posts' => array('/wp/v2/posts', 'posts'),
            'post_item' => array('/wp/v2/posts/[cid:digital]', 'post'),
            'posts_by_tag' => array('/wp/v2/posts/tag/[slug]', 'posts_by_tag'),
            'post_by_slug' => array('/wp/v2/posts/slug/[slug]', 'post_by_slug'),
            'pages' => array('/wp/v2/pages', 'pages'),
            'page_item' => array('/wp/v2/pages/[cid:digital]', 'page'),
            'page_by_slug' => array('/wp/v2/pages/slug/[slug]', 'page_by_slug'),
            'categories' => array('/wp/v2/categories', 'categories'),
            'category_item' => array('/wp/v2/categories/[mid:digital]', 'category'),
            'tags' => array('/wp/v2/tags', 'tags'),
            'tag_item' => array('/wp/v2/tags/[mid:digital]', 'tag'),
            'settings' => array('/wp/v2/settings', 'settings'),
            'links' => array('/wp/v2/links', 'links'),
            'link_categories' => array('/wp/v2/link-categories', 'linkCategories'),
            'comments' => array('/wp/v2/comments', 'comments'),
            'users' => array('/wp/v2/users', 'users'),
            'user_item' => array('/wp/v2/users/[uid:digital]', 'user')
        );

        $routes = array();
        foreach (array('' => '/wp-json', '_index' => '/index.php/wp-json') as $prefixName => $prefix) {
            foreach ($endpoints as $name => $endpoint) {
                foreach (array('' => '', '_slash' => '/') as $suffixName => $suffix) {
                    $routes['enhancement_restapi_' . $name . $prefixName . $suffixName] = array(
                        'url' => $prefix . $endpoint[0] . $suffix,
                        'widget' => 'Enhancement_RestApi_Action',
                        'action' => $endpoint[1]
                    );
                }
            }
        }

        return $routes;
    }

    public static function syncRoutes($settings = null)
    {
        if (!self::enabled($settings)) {
            self::removeRoutes();
            return;
        }

        $registered = Typecho_Widget::widget('Widget_Options')->routingTable;
        foreach (self::routes() as $name => $route) {
            if (!isset($registered[$name]) || $registered[$name] != $route) {
                Helper::addRoute($name, $route['url'], $route['widget'], $route['action']);
            }
        }
    }

    public static function removeRoutes()
    {
        $registered = Typecho_Widget::widget('Widget_Options')->routingTable;
        foreach (self::routes() as $name => $route) {
            if (isset($registered[$name])) {
                Helper::removeRoute($name);
            }
        }
    }
}
