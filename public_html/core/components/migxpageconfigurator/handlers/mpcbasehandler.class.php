<?php


class MpcBaseHandler
{
    public modX $modx;
    public array $properties = [];

    public function __construct(modX $modx, array $properties = [])
    {
        $this->modx = $modx;
        $this->setProperties($properties);
    }

    protected function setProperties(array $properties)
    {
        $this->properties = array_merge($this->properties, $properties);
    }

    /**
     *
     * @param string $className
     * @param array $data
     * @return array
     */
    protected function createObject(string $className, array $data): array
    {
        $obj = $this->modx->newObject($className);
        $obj->fromArray($data, '', true);
        if (!$obj->save()) {
            $this->modx->error->addError('Не сохранить объект класса ' . $className);
            return $this->modx->error->failure('', $data);
        }
        return $this->modx->error->success('', $obj);
    }

    protected function getObjectData(string $className, array $conditions)
    {
        if ($data = $this->modx->getObject($className, $conditions)) { // получаем объект
            $data = $data->toArray(); // преобразуем в массив
            unset($data['id']); // удаляем id, т.к. он задается автоматически при создании объекта
            return $this->modx->error->success('', $data);
        } else {
            $this->modx->error->addError('[MpcTemplate::getObjectData] Не удалось получить данные объекта класса ' . $className);
            return $this->modx->error->failure('', $conditions);
        }
    }

    /**
     *
     * @param array $config
     * @return array
     */
    protected function reformatConfig(array $config): array
    {
        $result = array();
        if (!empty($config)) {
            $c = 1;
            foreach ($config as $item) {
                $key = $item['section_name'];
                $item['position'] = (int)$item['position'] ?: $c++;
                $item['copy_from_origin'] = 0;
                $result[$key] = $item;
            }
        }
        return $result;
    }

    public function getPolylangConfig(int $rid, string $lang_key, $tv_id = false)
    {
        if ($config_polylang = $this->modx->getObject('PolylangTv', [
            'tmplvarid' => $tv_id ?: $this->config_tv_id,
            'culture_key' => $lang_key,
            'content_id' => $rid
        ])) {
            return $config_polylang->get('value');
        }
        return '';
    }

    private function find()
    {
        $html = '<div id="{$id}" data-mpc-section="lp_utp" data-mpc-name="УТП" class="showcase">
    <div class="container">
        <div class="showcase-layout">
            <div class="showcase-layout__content test">
                <h1 class="showcase-title" data-mpc-field="title">Зарабатывайте на дизайне товаров для маркетплейсов</h1>
                <div class="showcase-text" data-mpc-field="subtitle">Постоянный доход на всю жизнь для дизайнеров, художников, иллюстраторов</div>
                <img src="assets/project_files/img/landing/showcase.png" alt="" width="712" height="740" class="showcase-image" data-mpc-field="img">
                <div class="showcase-button">
                    <a href="registracziya" class="btn" data-mpc-field="target"><span data-mpc-unwrap="1" data-mpc-field="btn_text">Попробовать сейчас</span></a>
                </div>
                <div class="showcase-advantages">
                    <ul data-mpc-field="list_simple">
                        <li data-mpc-item><span data-mpc-unwrap="1" data-mpc-field-1="content">Деньги — на карту</span></li>
                        <li data-mpc-item><span data-mpc-field-1="content">По договору</span></li>
                        <li data-mpc-item><span data-mpc-field-1="content">Каждый месяц</span></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>';
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8')); // $html - ваш HTML-код
        $xpath = new DOMXpath($dom);

// Поиск элементов по произвольному селектору
//$elements = $xpath->query('//*[@data-mpc-field]');
        //$elements = $xpath->query('//*[@data-mpc-field="target"]');
//$elements = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' btn ')]");
        $elements = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' btn ')]/span");
//$elements = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' showcase-layout__content test ')]");
        foreach ($elements as $element) {
            //echo $dom->saveHTML($element);
            if ($element->hasAttribute('href')) {
                echo trim($element->getAttribute('data-mpc-field')) . "\n";
            }
            //echo trim($element->nodeValue) . "\n";
            //echo $dom->saveHTML($element);
        }
    }

    private function createDOM(string $html, string $version, string $encoding): DOMDocument
    {
        $dom = new DOMDocument($version, $encoding);
        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', $encoding));
        libxml_use_internal_errors(false);
        return $dom;
    }

    protected function findByAttribute(string $html, string $selector, string $version = '1.0', string $encoding = 'UTF-8'): DOMNodeList
    {
        $dom = $this->createDOM($html, $version, $encoding);
        $xpath = new DOMXpath($dom);
        $selector = str_replace('[', '[@', $selector);
        return $xpath->query("//*{$selector}");
    }


    /**
     * @param DOMText|DOMElement $element
     * @return string
     */
    protected function getHTMLString($element): string
    {
        return urldecode(html_entity_decode($element->ownerDocument->saveHTML($element)));
    }
}