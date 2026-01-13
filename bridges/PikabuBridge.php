<?php

class PikabuBridge extends BridgeAbstract
{
    const NAME = 'Пикабу';
    const URI = 'https://pikabu.ru';
    const DESCRIPTION = 'Выводит посты по тегу, сообществу или пользователю';
    const MAINTAINER = 'em92';

    const PARAMETERS_FILTER = [
        'name' => 'Фильтр',
        'type' => 'list',
        'values' => [
            'Горячее' => 'hot',
            'Свежее' => 'new',
        ],
        'defaultValue' => 'hot',
    ];

    const PARAMETERS = [
        'По тегу' => [
            'tag' => [
                'name' => 'Тег',
                'exampleValue' => 'it',
                'required' => true
            ],
            'filter' => self::PARAMETERS_FILTER
        ],
        'По сообществу' => [
            'community' => [
                'name' => 'Сообщество',
                'exampleValue' => 'linux',
                'required' => true
            ],
            'filter' => self::PARAMETERS_FILTER
        ],
        'По пользователю' => [
            'user' => [
                'name' => 'Пользователь',
                'exampleValue' => 'admin',
                'required' => true
            ]
        ]
    ];

    protected $title = null;

    public function getURI()
    {
        if ($this->getInput('tag')) {
            return self::URI . '/tag/' . rawurlencode($this->getInput('tag')) . '/' . rawurlencode($this->getInput('filter'));
        } elseif ($this->getInput('user')) {
            return self::URI . '/@' . rawurlencode($this->getInput('user'));
        } elseif ($this->getInput('community')) {
            $uri = self::URI . '/community/' . rawurlencode($this->getInput('community'));
            if ($this->getInput('filter') != 'hot') {
                $uri .= '/' . rawurlencode($this->getInput('filter'));
            }
            return $uri;
        } else {
            return parent::getURI();
        }
    }

    public function getIcon()
    {
        return 'https://cs.pikabu.ru/assets/favicon.ico';
    }

    public function getName()
    {
        if (is_null($this->title)) {
            return parent::getName();
        } else {
            return $this->title . ' - ' . parent::getName();
        }
    }

    public function collectData()
    {
        $link = $this->getURI();

        // --- БЛОК АВТОРИЗАЦИИ (Northflank) ---
        $my_cookies = getenv('PIKABU_COOKIES');
        $header = [];
        
        if ($my_cookies) {
            $header[] = 'Cookie: ' . $my_cookies;
            $header[] = 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:120.0) Gecko/20100101 Firefox/120.0';
            $header[] = 'X-Requested-With: XMLHttpRequest';
        }

        $text_html = getContents($link, $header);
        $text_html = iconv('windows-1251', 'utf-8', $text_html);
        $html = str_get_html($text_html);

        $this->title = $html->find('title', 0)->innertext;

        foreach ($html->find('article.story') as $post) {
            $time = $post->find('time.story__datetime', 0);
            if (is_null($time)) {
                continue;
            }

            // Удаляем лишние элементы
            $el_to_remove_selectors = [
                '.story__read-more',
                'script',
                'svg.story-image__stretch',
            ];

            foreach ($el_to_remove_selectors as $el_to_remove_selector) {
                foreach ($post->find($el_to_remove_selector) as $el) {
                    $el->outertext = '';
                }
            }

            // --- ОБРАБОТКА ВИДЕО И ГИФОК (Убираем черный экран) ---
            foreach ($post->find('div.story-block_type_video, div[data-type=video], [data-type=gifx]') as $media) {
                $video_url = $media->getAttribute('data-source');
                $preview = $media->getAttribute('data-preview');
                
                if ($video_url) {
                    $media->outertext = '<br><a href="' . $video_url . '">📺 Смотреть медиа/видео (внешняя ссылка)</a><br>';
                    if ($preview) {
                        $media->outertext .= '<img src="' . $preview . '" style="max-width:100%;">';
                    }
                }
            }

            // --- ФОРСИРОВАННАЯ ЗАМЕНА ДЛЯ ОБХОДА БЕЛОЙ ЗАГЛУШКИ ---
            foreach ($post->find('img') as $img) {
                $src = $img->getAttribute('data-src') ?: $img->getAttribute('src');
                $large_src = $img->getAttribute('data-large-image');
                $final_src = $large_src ?: $src;

                if ($final_src) {
                    // Используем wsrv.nl — это бесплатный кэширующий прокси для картинок.
                    // Он скачает картинку сам и отдаст её вам, обходя блокировки Пикабу.
                    $proxy_src = 'https://wsrv.nl/?url=' . urlencode($final_src);

                    $img->outertext = '<img src="' . $proxy_src . '" 
                        style="max-width:100%;" 
                        referrerpolicy="no-referrer">';
                    
                    if ($img->parent()->tag == 'a') {
                        $img->parent()->outertext = $img->outertext;
                    }
                }
            }

            $categories = [];
            foreach ($post->find('.tags__tag') as $tag) {
                if ($tag->getAttribute('data-tag')) {
                    $categories[] = $tag->innertext;
                }
            }

            $title_element = $post->find('.story__title-link', 0);
            if (!$title_element || str_contains($title_element->href, 'from=cpm')) {
                continue;
            }

            $title = $title_element->plaintext;
            $community_link = $post->find('.story__community-link', 0);
            if (!is_null($community_link) && $community_link->getAttribute('href') == '/community/maybenews') {
                $title = '[' . trim($community_link->plaintext) . '] ' . $title;
            }

            $item = [];
            $item['categories'] = $categories;
            $item['author'] = trim($post->find('.user__nick', 0)->plaintext);
            $item['title'] = $title;
            
            $content_inner = $post->find('.story__content-inner', 0);
            if ($content_inner) {
                $item['content'] = strip_tags(
                    backgroundToImg($content_inner->innertext),
                    '<br><p><img><a><s>'
                );
            } else {
                $item['content'] = 'Контент скрыт. Проверьте PIKABU_COOKIES в Northflank.';
            }
            
            $item['uri'] = $title_element->href;
            $item['timestamp'] = strtotime($time->getAttribute('datetime'));
            $this->items[] = $item;
        }
    }
}
