/**
 * mpcVisualEditor — реестр редакторов по типу поля. bindClicks диспатчит
 * editors[type].open(el).
 */
import { openTextEditor } from './text.js';
import { openImageEditor } from './image.js';
import { openPictureEditor } from './picture.js';
import { openRowsEditor } from './rows.js';
import { openLinkEditor } from './link.js';
import { openMediaEditor } from './media.js';

export var editors = {
    text: { open: openTextEditor },
    richtext: { open: openTextEditor },
    image: { open: openImageEditor },
    picture: { open: openPictureEditor },
    rows: { open: openRowsEditor },
    link: { open: openLinkEditor },
    media: { open: openMediaEditor }
};
