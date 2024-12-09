<img src="##complexName.img[0].src}" width="##complexName.img[0].width}" height="##complexName.img[0].height}" alt="##complexName.img[0].alt}">
##foreach complexName.sources as $source index=$index last=$last}
    <source srcset="##$source.srcset}" type="##$source.type}" media="##$source.media}">
##/foreach}
