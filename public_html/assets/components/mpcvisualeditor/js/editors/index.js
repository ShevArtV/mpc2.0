/**
 * mpcVisualEditor — реестр редакторов по типу поля. bindClicks диспатчит
 * editors[type].open(el).
 */
import { openTextEditor } from './text.js';
import { openTextareaEditor } from './textarea.js';
import { openRichtextEditor } from './richtext.js';
import { openImageEditor } from './image.js';
import { openPictureEditor } from './picture.js';
import { openRowsEditor } from './rows.js';
import { openLinkEditor } from './link.js';
import { openMediaEditor } from './media.js';
import { openListboxEditor } from './listbox.js';
import { openScalarEditor } from './scalar.js';
import { openTagsEditor } from './tags.js';
import { openFileEditor } from './file.js';
import { openInfoEditor } from './info.js';
import { openContactEditor } from './contact.js';

export var editors = {
    info: { open: openInfoEditor },           // служебная инфа (data-mpc-info) — глобальные настройки
    contact: { open: openContactEditor },     // контакт (data-mpc-cfield) — ресурс «Контакты»
    text: { open: openTextEditor },           // инлайн (простой текст)
    textarea: { open: openTextareaEditor },   // модалка <textarea>
    richtext: { open: openRichtextEditor },   // модалка RTE
    image: { open: openImageEditor },
    picture: { open: openPictureEditor },
    rows: { open: openRowsEditor },
    link: { open: openLinkEditor },
    media: { open: openMediaEditor },
    listbox: { open: openListboxEditor },              // выпадающий список (data-mpc-values / TV elements)
    'listbox-multiple': { open: openListboxEditor },   // мультивыбор (<select multiple>)
    option: { open: openListboxEditor },               // TV option — радио (одиночный)
    checkbox: { open: openListboxEditor },             // TV checkbox — чекбоксы (множественный)
    number: { open: openScalarEditor },                // TV number — модалка input[number]
    date: { open: openScalarEditor },                  // TV date — модалка datetime-local
    tags: { open: openTagsEditor },                    // TV tag/autotag — чипсы
    file: { open: openFileEditor }                     // TV file — файловый менеджер
};
