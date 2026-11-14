<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class Enhancement_Sitemap_Action extends Typecho_Widget implements Widget_Interface_Do
{
    public function action()
    {
        $settings = Enhancement_Plugin::runtimeSettings();
        $options = Typecho_Widget::widget('Widget_Options');
        if (isset($settings->enable_sitemap) && $settings->enable_sitemap != '1') {
            header("HTTP/1.1 404 Not Found");
            echo 'Not Found';
            return;
        }

        $db = Typecho_Db::get();

        $pages = $db->fetchAll($db->select()->from('table.contents')
            ->where('table.contents.status = ?', 'publish')
            ->where('table.contents.created < ?', $options->gmtTime)
            ->where('table.contents.type = ?', 'page')
            ->order('table.contents.created', Typecho_Db::SORT_DESC));

        $articles = $db->fetchAll($db->select()->from('table.contents')
            ->where('table.contents.status = ?', 'publish')
            ->where('table.contents.created < ?', $options->gmtTime)
            ->where('table.contents.type = ?', 'post')
            ->order('table.contents.created', Typecho_Db::SORT_DESC));

        Typecho_Widget::widget('Widget_Metas_Category_List@cate')->to($cates);

        $tags = $db->fetchAll($db->select()->from('table.metas')
            ->where('table.metas.type = ?', 'tag')
            ->order('table.metas.mid', Typecho_Db::SORT_DESC));

        header("Content-Type: application/xml");
        echo "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n";
        $xslUrl = Enhancement_Plugin::appendVersionToAssetUrl($options->pluginUrl . "/Enhancement/sitemap.xsl");
        echo "<?xml-stylesheet type='text/xsl' href='" . $xslUrl . "'?>\n";
        echo "<urlset xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"\n";
        echo "xsi:schemaLocation=\"http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd\"\n";
        echo "xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">";
        echo "\t<url>\n";
        echo "\t\t<loc>" . $options->siteUrl . "</loc>\n";
        echo "\t\t<changefreq>daily</changefreq>\n";
        echo "\t\t<priority>1.0</priority>\n";
        echo "\t</url>\n";

        $permalink = function ($row) use ($options) {
            $pathinfo = (NULL != Typecho_Router::get($row['type'])) ? Typecho_Router::url($row['type'], $row) : '#';
            return Typecho_Common::url($pathinfo, $options->index);
        };
        $emit = function ($loc, $row, $changefreq, $priority) {
            echo "\t<url>\n";
            echo "\t\t<loc>" . $loc . "</loc>\n";
            echo "\t\t<lastmod>" . date('Y-m-d', $row['modified']) . "</lastmod>\n";
            echo "\t\t<changefreq>" . $changefreq . "</changefreq>\n";
            echo "\t\t<priority>" . $priority . "</priority>\n";
            echo "\t</url>\n";
        };

        foreach ($pages as $page) {
            $emit($permalink($page), $page, 'always', '0.6');
        }

        foreach ($articles as $article) {
            $article['categories'] = $db->fetchAll($db->select()->from('table.metas')
                ->join('table.relationships', 'table.relationships.mid = table.metas.mid')
                ->where('table.relationships.cid = ?', $article['cid'])
                ->where('table.metas.type = ?', 'category')
                ->order('table.metas.order', Typecho_Db::SORT_ASC));
            $article['category'] = urlencode(current(Typecho_Common::arrayFlatten($article['categories'], 'slug')));
            $article['slug'] = urlencode($article['slug']);
            $article['date'] = new Typecho_Date($article['created']);
            $article['year'] = $article['date']->year;
            $article['month'] = $article['date']->month;
            $article['day'] = $article['date']->day;
            $emit($permalink($article), $article, 'always', '0.8');
        }

        while ($cates->next()) {
            $art_rs = $db->fetchRow($db->select()->from('table.contents')
                ->join('table.relationships', 'table.contents.cid = table.relationships.cid')
                ->where('table.contents.status = ?', 'publish')
                ->where('table.relationships.mid = ?', $cates->mid)
                ->order('table.relationships.cid', Typecho_Db::SORT_DESC)
                ->limit(1));
            $emit($cates->permalink, $art_rs, 'daily', '0.5');
        }

        foreach ($tags as $tag) {
            $art_rt = $db->fetchRow($db->select()->from('table.contents')
                ->join('table.relationships', 'table.contents.cid = table.relationships.cid')
                ->where('table.contents.status = ?', 'publish')
                ->where('table.relationships.mid = ?', $tag['mid'])
                ->order('table.relationships.cid', Typecho_Db::SORT_DESC)
                ->limit(1));
            $emit($permalink($tag), $art_rt, 'daily', '0.5');
        }
        echo "</urlset>";
    }
}
