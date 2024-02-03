// определение видимости элемента
export function visible(target) {
    let targetPosition = {
            top: window.pageYOffset + target.getBoundingClientRect().top,
            bottom: window.pageYOffset + target.getBoundingClientRect().bottom
        },
        windowPosition = {
            top: window.pageYOffset,
            bottom: window.pageYOffset + document.documentElement.clientHeight
        };
    if (targetPosition.bottom > windowPosition.top && targetPosition.top < windowPosition.bottom) {
        return true;
    } else {
        return false;
    }
}

// ленивая загрузка
export function lazyLoad(lazyLoadAttr, parent, show) {
    lazyLoadAttr = lazyLoadAttr || 'data-lazy';
    parent = parent || document;
    let media = parent.querySelectorAll('[' + lazyLoadAttr + ']');
    let key = lazyLoadAttr.replace('data-', '');
    media.forEach(function (elem) {
        if (visible(elem) || show) {
            if (elem.tagName == 'IMG' || elem.tagName == 'IFRAME') {
                elem.src = elem.dataset[key];
            } else {
                elem.style.backgroundImage = 'url(' + elem.dataset[key] + ')';
            }
            elem.removeAttribute(lazyLoadAttr);
        }
    });
}