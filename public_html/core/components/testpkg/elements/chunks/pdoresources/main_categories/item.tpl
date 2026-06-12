<div class="column {$idx < 4 ? 'col-4 md-col-12': 'col-6 md-col-12'}">

            <div class="item">
                {$is_hit ? '<div class="item-label">Хит продаж</div>' : ''}
                <div class="item-image" style="" data-lazy="{$tv.img}"></div>
                <div class="item-title">{$res.pagetitle}</div>
                <div class="item-price">
                    Выплаты: <span>{$res.introtext}</span>
                </div>
                <div>
                    
                        <p>{$subchunk_field}</p>
                        <p>{$subchunk_field2}</p>
                    
                    ##$_modx->parseChunk("@FILE chunks/parsechunk/chunkname.tpl", ['$parsechunk_field' => $parsechunk_field])}
                </div>

            </div>
        </div>