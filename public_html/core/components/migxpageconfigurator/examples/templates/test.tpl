<!--##{"templatename":"Тестовый шаблон","pagetitle":"Тестовый шаблон","icon":"icon-gear"}##-->

<!-- php -d display_errors -d error_reporting=E_ALL public_html/core/components/migxpageconfigurator/console/mgr_tpl.php web test.tpl -->

<section id="{$id}" data-mpc-section="test" data-mpc-name="Тестовая секция" class="section-test section js-section">
    <p data-mpc-remove="" data-mpc-field="bg_img">assets/components/migxpageconfigurator/images/fake-img.png</p>
    <h1 data-mpc-field="title">Заголовок</h1>
    <div data-mpc-field="list_simple">
        <div data-mpc-item="" data-mpc-cond="$i %3C 4">
            <p data-mpc-field-1="content">Заголовок элемента 1</p>
        </div>
        <div data-mpc-item="">
            <p data-mpc-field-1="content">Заголовок элемента 2</p>
        </div>
    </div>
    <div data-mpc-chunk="common/chunk_3.tpl" data-mpc-parse="['id' => 1]" data-mpc-symbol="##">
        <p>Контент распарсенного чанка - {$id}</p>
    </div>
    <div data-mpc-snippet="!Test|preset">
        <div data-mpc-chunk="common/chunk_1.tpl">
            <div data-mpc-symbol="{ " data-mpc-snippet="Test2">
                <div data-mpc-unwrap="1" data-mpc-chunk="common/chunk_4.tpl">
                    <p>Содержимое чанка вложенного снипета</p>
                    {$placeholder}
                    <div data-mpc-remove="" data-mpc-chunk="common/chunk_5.tpl">
                        <p>Содержимое чанка вложенного снипета заменяемого на плейсхолдер</p>
                    </div>
                </div>
            </div>
            <p>Содержимое чанка</p>
            <div data-mpc-chunk="common/chunk_2.tpl" data-mpc-include>
                <p>Контент включаемого чанка.</p>
            </div>
        </div>
    </div>
    <form action="#" data-mpc-chunk="forms/test_form.tpl" data-mpc-form="" data-mpc-preset="test_form" data-mpc-name="Тестовая форма">
        <div data-mpc-chunk="common/common_fields.tpl" data-mpc-include="">
            <input class="visually-hidden" type="hidden" name="formName" value="{$formName}">
            <input class="visually-hidden" type="text" name="secret" data-secret="{$secret}" style="position: absolute;opacity:0;z-index: -1;" autocomplete="off">
            <small class="text-danger error_secret"></small>
        </div>
        <div class="text-center">
            <button class="btn btn-danger text-white px-lg-60 px-35">Выйти</button>
        </div>
    </form>
</section>

<section id="{$id}" data-mpc-copy="test.tpl" data-mpc-section="test" data-mpc-name="Тестовая секция" class="section-test section js-section">
    <p data-mpc-remove="" data-mpc-field="bg_img"></p>
    <h1 data-mpc-field="title">Заголовок 1</h1>
    <div data-mpc-field="list_simple"></div>
</section>