<?php

namespace MpcVEServices\Handlers;

/**
 * Экшен contact/save: пер-полевая правка контакта (data-mpc-contact /
 * data-mpc-cfield) — value / caption / attributes. Контакты живут в ресурсе
 * «Контакты» и общие для всего сайта → требуется право `mpcve_edit_global`.
 *
 * Правка ТРЕБУЕТ data-mpc-key: без него identity контакта = md5(value) и
 * нестабилен (href/текст/смена значения). Запись делегируется в mpc: собираем
 * минимальный контакт-фрагмент и прогоняем через грабер (вся логика identity/
 * лексиконов/мёрж в TV — там же, что при нарезке).
 *
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class ContactSaveHandler extends BaseHandler
{
    private const FIELDS = ['value', 'caption', 'attributes', 'icon'];

    public function handle(array $request): array
    {
        if (!(new PermissionChecker($this->modx))->canEditGlobal()) {
            return $this->err($this->modx->lexicon('mpcve_err_permission'));
        }

        $key       = trim((string)($request['key'] ?? ''));
        $type      = trim((string)($request['type'] ?? ''));
        $placement = trim((string)($request['placement'] ?? 'default')) ?: 'default';
        $field     = (string)($request['field'] ?? '');
        $value     = (string)($request['value'] ?? '');
        $current   = (string)($request['currentValue'] ?? '');

        if ($key === '') {
            return $this->err('правка контакта требует data-mpc-key');
        }
        if ($type === '') {
            return $this->err('type required');
        }
        if (!in_array($field, self::FIELDS, true)) {
            return $this->err('unknown contact field: ' . $field);
        }
        if (!class_exists('\\MpcServices\\Mpc')) {
            return $this->err('migxpageconfigurator (mpc) required');
        }

        // value-cfield обязателен граберу (identity/мёрж). При правке value кладём
        // в него НОВОЕ значение; при правке caption/attributes — текущее (identity
        // всё равно по data-mpc-key, контент value нужен лишь чтобы пройти грабер).
        $valueContent = ($field === 'value') ? $value : $current;
        $frag = '<div data-mpc-contact="' . $this->esc($type . '|' . $placement) . '"'
            . ' data-mpc-key="' . $this->esc($key) . '">'
            . '<span data-mpc-cfield="value">' . $this->esc($valueContent) . '</span>';
        if ($field !== 'value') {
            $frag .= '<span data-mpc-cfield="' . $this->esc($field) . '">' . $this->esc($value) . '</span>';
        }
        $frag .= '</div>';

        try {
            (new \MpcServices\Mpc($this->modx))->saveContact($frag);
        } catch (\Throwable $e) {
            $this->modx->log(\modX::LOG_LEVEL_ERROR, '[mpcVE contact/save] ' . $e->getMessage());
            return $this->err('ошибка сохранения контакта');
        }

        (new ChangeLog($this->modx))->add([
            'resource_id' => (int)($this->modx->resource ? $this->modx->resource->get('id') : 0),
            'user_id'     => (int)($this->modx->user ? $this->modx->user->get('id') : 0),
            'username'    => (string)($this->modx->user ? $this->modx->user->get('username') : ''),
            'action'      => 'contact',
            'section'     => $type . '|' . $placement,
            'field'       => $key . '.' . $field,
            'new_value'   => $value,
            'revertable'  => 0,
        ]);

        return $this->ok('ok');
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
