<?php

namespace MpcServices\Handlers;

use MpcServices\Handlers\Grabber\LexiconKeyHelper;

/**
 * Лексикон-мерж media/record-значений при записи config-поля. Вынесено из
 * FieldWriter (God-класс, самый сцепленный кусок): глубокий мерж picture/video/
 * audio (вложенный img + sources + верхнеуровневые src/poster), плоский мерж
 * img-записи, генерация ключей для НОВОЙ media-записи. Лексиконизируемые листья
 * (src/srcset/alt/title/poster): существующий ключ → пишем значение в лексикон и
 * сохраняем ключ; новый → генерим единой LexiconKeyHelper::getLexiconKey.
 *
 * Контекст записи (writer + identifier ресурса) — в конструкторе; FieldWriter
 * создаёт мерджер на одну запись. prefix секции вычисляет вызывающий.
 *
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class MediaLexiconMerger
{
    /** @var LexiconWriter */
    private $writer;
    private string $ident;

    /** @param LexiconWriter $writer LexiconWriter-совместимый (has/set). */
    public function __construct($writer, string $ident)
    {
        $this->writer = $writer;
        $this->ident = $ident;
    }

    /** Запись — media с вложенным img/sources (picture/video/audio)? */
    public function isMediaWithSources($value): bool
    {
        $rows = RecordUtil::decodeRecord($value);
        $row  = $rows[0] ?? null;
        return is_array($row) && (isset($row['sources']) || isset($row['img']));
    }

    /** Есть ли в media-записи лексикон-ключи (существующая запись vs новая). */
    public function recordHasLexiconKeys($current): bool
    {
        foreach (RecordUtil::decodeRecord($current) as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $v) {
                if (is_string($v) && $v !== '' && $this->writer->has($this->ident, $v)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Плоский мерж media-записи с лексиконом: под-поле, чьё ТЕКУЩЕЕ значение —
     * ключ лексикона, → пишем туда новое значение и сохраняем ключ; иначе литерал
     * (так width/height обновляются прямо, а src/alt/title — в лексикон).
     */
    public function mergeRecordWithLexicon($current, $value): string
    {
        $curRows = RecordUtil::decodeRecord($current);
        $newRows = RecordUtil::decodeRecord($value);

        foreach ($newRows as $i => $newRow) {
            if (!is_array($newRow)) {
                continue;
            }
            $curRow = (isset($curRows[$i]) && is_array($curRows[$i])) ? $curRows[$i] : [];
            foreach ($newRow as $sub => $newSub) {
                $curSub = $curRow[$sub] ?? null;
                if (is_string($curSub) && $curSub !== '' && $this->writer->has($this->ident, $curSub)) {
                    $this->writer->set($this->ident, $curSub, is_scalar($newSub) ? (string)$newSub : '');
                    $newRows[$i][$sub] = $curSub; // сохраняем ключ в записи
                }
            }
        }

        return json_encode($newRows, JSON_UNESCAPED_UNICODE);
    }

    /**
     * НОВАЯ media-запись: генерим ключи лексикона для лексиконизируемых под-полей
     * (src/srcset/alt/title) единой getLexiconKey, пишем значения, в запись кладём
     * КЛЮЧИ. width/height и пр. — литералом. Пустой prefix → литерал.
     */
    public function newRecordWithLexiconKeys(array $address, $value, string $prefix): string
    {
        $rows = RecordUtil::decodeRecord($value);
        if ($prefix === '' || !$rows) {
            return json_encode($rows, JSON_UNESCAPED_UNICODE);
        }
        // База ключа = parentField (строка media-списка) либо fieldName (top-level img).
        $base = (string)($address['parentField'] ?? '');
        if ($base === '') {
            $base = (string)($address['fieldName'] ?? '');
        }
        $idx     = $address['idx'] ?? '';
        $lexSubs = ['src' => '', 'srcset' => '', 'alt' => '_alt', 'title' => '_title'];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($lexSubs as $sub => $suffix) {
                if (isset($row[$sub]) && is_string($row[$sub]) && $row[$sub] !== '') {
                    $key = LexiconKeyHelper::getLexiconKey([
                        'prefix' => $prefix, 'fieldName' => $base . $suffix, 'idx' => $idx,
                    ]);
                    if ($key !== '' && $this->writer->set($this->ident, $key, $row[$sub])) {
                        $rows[$i][$sub] = $key;
                    }
                }
            }
        }
        return json_encode($rows, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Глубокий мерж media-записи (picture/video/audio): вложенный `img`
     * (JSON-строка) + массив `sources` + верхнеуровневые src/poster.
     */
    public function mergeMediaRecord($current, $value, string $prefix, string $field): string
    {
        $cur = RecordUtil::decodeRecord($current);
        $new = RecordUtil::decodeRecord($value);

        foreach ($new as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $curRow = (isset($cur[$i]) && is_array($cur[$i])) ? $cur[$i] : [];

            // вложенная картинка img (JSON-строка записи): src/alt/title
            if (isset($row['img'])) {
                $new[$i]['img'] = $this->mergeImgKeys($curRow['img'] ?? '', $row['img'], $prefix, $field);
            }
            // массив sources: srcset (picture) / src (video/audio)
            if (isset($row['sources']) && is_array($row['sources'])) {
                $curSources = (isset($curRow['sources']) && is_array($curRow['sources'])) ? $curRow['sources'] : [];
                foreach ($row['sources'] as $k => $src) {
                    if (!is_array($src)) {
                        continue;
                    }
                    foreach (['srcset', 'src'] as $leaf) {
                        if (isset($src[$leaf]) && is_string($src[$leaf]) && $src[$leaf] !== '') {
                            $key = $this->resolveLeafKey($curSources[$k][$leaf] ?? null, $prefix, $field . '_source', $k);
                            if ($key !== '') {
                                // значение == ключ → фронт прислал ключ (источник не
                                // меняли) → лексикон НЕ трогаем, только ключ в конфиг.
                                if ($src[$leaf] !== $key) {
                                    $this->writer->set($this->ident, $key, $src[$leaf]);
                                }
                                $new[$i]['sources'][$k][$leaf] = $key;
                            }
                        }
                    }
                }
            }
            // верхнеуровневые листья video/audio: src / poster
            foreach (['src' => '_source', 'poster' => '_poster'] as $leaf => $suffix) {
                if (isset($row[$leaf]) && is_string($row[$leaf]) && $row[$leaf] !== '' && !RecordUtil::isRecordValue($row[$leaf])) {
                    $key = $this->resolveLeafKey($curRow[$leaf] ?? null, $prefix, $field . $suffix, '');
                    if ($key !== '') {
                        if ($row[$leaf] !== $key) {
                            $this->writer->set($this->ident, $key, $row[$leaf]);
                        }
                        $new[$i][$leaf] = $key;
                    }
                }
            }
        }

        return json_encode($new, JSON_UNESCAPED_UNICODE);
    }

    /** Мерж ключей вложенной картинки img (src/alt/title). */
    private function mergeImgKeys($curImg, $newImg, string $prefix, string $field): string
    {
        $cur = RecordUtil::decodeRecord($curImg);
        $new = RecordUtil::decodeRecord($newImg);
        foreach ($new as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $curRow = (isset($cur[$i]) && is_array($cur[$i])) ? $cur[$i] : [];
            foreach (['src' => '', 'alt' => '_alt', 'title' => '_title'] as $leaf => $suffix) {
                if (isset($row[$leaf]) && is_string($row[$leaf]) && $row[$leaf] !== '') {
                    $key = $this->resolveLeafKey($curRow[$leaf] ?? null, $prefix, $field . $suffix, '');
                    if ($key !== '') {
                        if ($row[$leaf] !== $key) {
                            $this->writer->set($this->ident, $key, $row[$leaf]);
                        }
                        $new[$i][$leaf] = $key;
                    }
                }
            }
        }
        return json_encode($new, JSON_UNESCAPED_UNICODE);
    }

    /** Ключ листа: существующий (если он лексикон-ключ) либо новый через getLexiconKey ('' если нет prefix). */
    private function resolveLeafKey($currentLeaf, string $prefix, string $fieldName, $idx): string
    {
        if (is_string($currentLeaf) && $currentLeaf !== '' && $this->writer->has($this->ident, $currentLeaf)) {
            return $currentLeaf;
        }
        if ($prefix === '') {
            return '';
        }
        return LexiconKeyHelper::getLexiconKey([
            'prefix' => $prefix, 'fieldName' => $fieldName, 'idx' => $idx,
        ]);
    }
}
