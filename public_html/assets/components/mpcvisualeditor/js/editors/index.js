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

export var editors = {
    text: { open: openTextEditor },           // инлайн (простой текст)
    textarea: { open: openTextareaEditor },   // модалка <textarea>
    richtext: { open: openRichtextEditor },   // модалка RTE
    image: { open: openImageEditor },
    picture: { open: openPictureEditor },
    rows: { open: openRowsEditor },
    link: { open: openLinkEditor },
    media: { open: openMediaEditor }
};
