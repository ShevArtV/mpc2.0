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
            $tmp = [
                'type'      => $contactAttrValue[0],
                'placement' => $contactAttrValue[1] ?? 'default',
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
                $tmp['key'] = $item->getAttribute('data-mpc-key') ?: md5($tmp['value']);
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
            $tmp = isset($this->modx->event->returnedValues) && !empty($this->modx->event->returnedValues['contact'])
                ? $this->modx->event->returnedValues['contact']
                : $tmp;

            if (in_array('contact', $this->properties['translatableContentTypes'])) {
                foreach ($tmp as $k => $v) {
                    if (in_array($k, ['placement', 'key', 'type'])) {
                        continue;
                    }
                    $lexiconOptions = [
                        'fieldName'       => $k,
                        'parentFieldName' => "{$tmp['key']}_{$tmp['type']}_{$tmp['placement']}",
                        'idx'             => 0,
                        'prefix'          => 'contact',
                    ];
                    $tmp[$k] = $this->lexiconManager->setLexicons($v, $lexiconOptions);
                }
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
            if ($oldContacts[$value]) {
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
