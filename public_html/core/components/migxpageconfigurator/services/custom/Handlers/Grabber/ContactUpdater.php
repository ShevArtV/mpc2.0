<?php

namespace MpcServices\Handlers\Grabber;

use MpcServices\Handlers\Parser;

/**
 * Обновление контактов ресурса по [data-mpc-contact].
 */
class ContactUpdater
{
    private \modX               $modx;
    private array               $properties;
    private Parser              $parser;
    private FieldValueExtractor $fieldValueExtractor;
    private LexiconManager      $lexiconManager;

    public function __construct(
        \modX               $modx,
        array               $properties,
        Parser              $parser,
        FieldValueExtractor $fieldValueExtractor,
        LexiconManager      $lexiconManager
    ) {
        $this->modx               = $modx;
        $this->properties         = $properties;
        $this->parser             = $parser;
        $this->fieldValueExtractor = $fieldValueExtractor;
        $this->lexiconManager     = $lexiconManager;
    }

    public function handleContacts(string $html, bool $updContent): void
    {
        if (!$updContent) {
            return;
        }

        $items = $this->parser->findByAttribute($html, '[data-mpc-contact]');
        if (!count($items)) {
            return;
        }

        $contacts = [];
        foreach ($items as $item) {
            $fields = $this->parser->findByAttribute($this->parser->getHTMLString($item), '[data-mpc-cfield]');
            if (!count($fields)) {
                continue;
            }

            $contactAttrValue = explode('|', trim($item->getAttribute('data-mpc-contact')));
            // Все ключи инициализированы: cfield-поля заполняются в цикле ниже
            // выборочно, без инициализации был бы Undefined index на PHP 8.
            $tmp = [
                'type'       => $contactAttrValue[0] ?? '',
                'placement'  => $contactAttrValue[1] ?? 'default',
                'value'      => '',
                'key'        => '',
                'fvalue'     => '',
                'caption'    => '',
                'attributes' => '',
            ];

            foreach ($fields as $field) {
                $key = $field->getAttribute('data-mpc-cfield');
                if ($key === 'fvalue') {
                    continue;
                }
                if ($key === 'value') {
                    if ($href = $field->getAttribute('href')) {
                        $tmp[$key] = $href;
                    } else {
                        $tmp[$key] = trim($this->fieldValueExtractor->getValue($field));
                    }
                } else {
                    $tmp[$key] = $field->getAttribute('src') ?: $this->fieldValueExtractor->getValue($field);
                }
            }

            if (!$tmp['value']) {
                continue;
            }

            if (!$tmp['key']) {
                // ckey = data-mpc-key, иначе md5 от НОРМАЛИЗОВАННОГО значения
                // (strip_tags+trim) — единообразно с каттером (Base::getContactKey),
                // иначе контакты без data-mpc-key не резолвятся на рендере.
                $tmp['key'] = $item->getAttribute('data-mpc-key') ?: md5(trim(strip_tags($tmp['value'])));
            }

            if ($tmp['type'] === 'phone') {
                $tmp['value'] = preg_replace('/[^0-9]/', '', trim($tmp['value']));
                if (!$tmp['fvalue']) {
                    $tmp['fvalue'] = preg_replace(
                        $this->properties['phoneRegExp'],
                        $this->properties['phoneFormat'],
                        trim($tmp['value'])
                    );
                }
            } else {
                $tmp['fvalue'] = $tmp['value'];
            }

            $this->modx->invokeEvent('mpcOnHandleContact', ['contact' => [$tmp], 'Grabber' => $this]);
            // Принимаем ответ события, только если это валидный контакт-массив
            // (иначе дальнейший foreach $tmp с ключами сломался бы).
            $returned = $this->modx->event->returnedValues['contact'] ?? null;
            if (is_array($returned) && isset($returned['value'])) {
                $tmp = $returned;
            }

            // Лексиконим только переводимые под-поля (настройка mpc_contact_lexicon_fields).
            // Ключ строится по РОЛИ поля (identity контакта = data-mpc-key / md5(value)):
            //   value/fvalue — данные самого контакта (один телефон/email на все места)
            //     → ключ БЕЗ плейсмента: contact_{ckey}_{type}_value (один перевод на все плейсменты);
            //   caption/attributes — оформление (подпись/иконка зависят от места)
            //     → ключ С плейсментом: contact_{ckey}_{type}_{placement}_caption.
            $translatable = $this->properties['contactLexiconFields'] ?? ['caption'];
            // Пер-маркер override: data-mpc-translate="caption,value" перекрывает настройку.
            if ($override = trim((string)$item->getAttribute('data-mpc-translate'))) {
                $translatable = array_values(array_filter(array_map('trim', explode(',', $override))));
            }
            foreach ($tmp as $k => $v) {
                if (!in_array($k, $translatable, true)) {
                    continue;
                }
                $parent = in_array($k, ['value', 'fvalue'], true)
                    ? "{$tmp['key']}_{$tmp['type']}"
                    : "{$tmp['key']}_{$tmp['type']}_{$tmp['placement']}";
                $tmp[$k] = $this->lexiconManager->setLexicons($v, [
                    'fieldName'       => $k,
                    'parentFieldName' => $parent,
                    'idx'             => 0,
                    'prefix'          => 'contact',
                ]);
            }

            $contacts[$tmp['value']]['type']   = $tmp['type'];
            $contacts[$tmp['value']]['ckey']   = $tmp['key'];
            $contacts[$tmp['value']]['value']  = $tmp['value'];
            $contacts[$tmp['value']]['fvalue'] = $tmp['fvalue'];
            $contacts[$tmp['value']]['contact_info'][$tmp['placement']] = [
                'caption'    => $tmp['caption'],
                'attributes' => $tmp['attributes'],
                'placement'  => $tmp['placement'],
            ];
        }

        if (empty($contacts)) {
            return;
        }

        if (!$resource = $this->modx->getObject('modResource', (int)$this->properties['contactsPageId'])) {
            return;
        }

        if (!empty($this->lexiconManager->lexicons)) {
            $this->lexiconManager->createLexicons($this->lexiconManager->lexicons);
        }

        $tvValue     = json_decode($resource->getTVValue($this->properties['contactsTvName']), true);
        $oldContacts = $tvValue ? $this->reformatMigx($tvValue, 'value') : [];

        foreach ($contacts as $value => $item) {
            $ckey = (string)($item['ckey'] ?? '');
            // СМЕНА значения keyed-контакта: новое значение не совпадёт с ключом
            // $oldContacts (он по value) → без этого появится ДУБЛЬ с тем же ckey.
            // ckey — истинный identity: находим старую запись по ckey (под другим
            // значением), переносим её contact_info (прочие плейсменты) и удаляем.
            if ($ckey !== '' && !isset($oldContacts[$value])) {
                foreach ($oldContacts as $oldVal => $old) {
                    if ((string)($old['ckey'] ?? '') === $ckey) {
                        $oldInfo = $this->reformatMigx(json_decode((string)$old['contact_info'], true) ?: [], 'placement');
                        $item['contact_info'] = array_merge($oldInfo, $item['contact_info']);
                        unset($oldContacts[$oldVal]);
                        break;
                    }
                }
            }
            if (isset($oldContacts[$value])) {
                $contactInfo                     = json_decode($oldContacts[$value]['contact_info'], true) ?: [];
                $contactInfo                     = $this->reformatMigx($contactInfo, 'placement');
                $contactInfo                     = array_merge($contactInfo, $item['contact_info']);
                $oldContacts[$value]['contact_info'] = $contactInfo;
            } else {
                $oldContacts[$value] = $item;
            }
        }

        $newContacts = [];
        $i = 0;
        foreach ($oldContacts as $item) {
            $item['MIGX_id'] = ++$i;
            if (!empty($item['contact_info'])) {
                $item['contact_info'] = !is_array($item['contact_info'])
                    ? json_decode($item['contact_info'], true)
                    : $item['contact_info'];
                $j           = 0;
                $contactInfo = [];
                foreach ($item['contact_info'] as $info) {
                    $info['MIGX_id'] = ++$j;
                    $contactInfo[]   = $info;
                }
                $item['contact_info'] = json_encode($contactInfo, JSON_UNESCAPED_UNICODE);
            }
            $newContacts[] = $item;
        }

        $resource->setTVValue($this->properties['contactsTvName'], json_encode($newContacts, JSON_UNESCAPED_UNICODE));
    }

    private function reformatMigx(array $data, string $key): array
    {
        $result = [];
        foreach ($data as $item) {
            $result[$item[$key]] = $item;
        }
        return $result;
    }
}
