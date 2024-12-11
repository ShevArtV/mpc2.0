/**
 * Модуль отложенной загрузки картинок, видео, фреймов, блоков
 */

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 * @class mpcLazyLoad - класс отложенной загрузки
 * @property {Object} config - конфигурация модуля
 * @property {Object} events - события модуля
 * @method {void} initialize() - инициализация модуля
 * @method {void} handler(entries) - обработчик событий IntersectionObserver
 * @method {void} loading(elem) - загрузка элемента
 * @method {void} opacityUp(elem, time) - анимация прозрачности
 */
class mpcLazyLoad {
  constructor(config = {}) {
    if (!window.mpcLazyLoadAttr) {
      window.mpcLazyLoadAttr = 'data-lazy';
    }
    if (window.mpcLazyLoad) return window.mpcLazyLoad;

    const defaults = {
      rootSelector: `[${window.mpcLazyLoadAttr}]`,
      rootAttr: window.mpcLazyLoadAttr,
    }
    this.events = {
      onload: 'mpc:lazy:loaded'
    };
    this.config = Object.assign(defaults, config);
    this.initialize();
    window.mpcLazyLoad = this;
  }

  /**
   * @returns {void}
   */
  initialize() {
    this.config.rootKey = this.getDataAttrKey(this.config.rootAttr);
    const elements = document.querySelectorAll(this.config.rootSelector);
    if (elements.length) {
      const observer = new IntersectionObserver((entries) => this.handler(entries), {
        root: null,
        rootMargin: '0px',
        threshold: 0
      });
      elements.forEach(element => observer.observe(element));
    }
  }

  /**
   * @param attrName
   * @returns {string}
   */
  getDataAttrKey(attrName) {
    const prefix = 'data-';
    if (attrName.startsWith(prefix)) {
      return attrName.slice(prefix.length).replace(/-([a-z])/g, (match, group) => group.toUpperCase());
    } else {
      return attrName;
    }
  }

  /**
   * @param entries
   * @returns {void}
   */
  handler(entries) {
    for (let i in entries) {
      const elem = entries[i].target;
      if (!elem.hasAttribute(this.config.rootAttr)) continue;

      if (entries[i].isIntersecting) {
        if (elem.dataset[this.config.rootKey]) {
          this.loading(elem);
        } else {
          elem.removeAttribute(this.config.rootAttr);
        }

        elem.dispatchEvent(new CustomEvent(this.events.onload, {
          bubbles: true,
          cancelable: false,
          detail: {
            entry: entries[i],
            LazyLoad: this
          }
        }));
      }
    }
  }

  /**
   * @param elem
   * @returns {void}
   */
  loading(elem) {
    if (['IMG', 'IFRAME', 'VIDEO', 'SOURCE'].includes(elem.tagName)) {
      elem.src = elem.dataset[this.config.rootKey];
    } else {
      elem.style.backgroundImage = 'url(' + elem.dataset[this.config.rootKey] + ')';
    }
    elem.removeAttribute(this.config.rootAttr);
    this.opacityUp(elem);
  }

  /**
   * @param elem
   * @param time
   * @returns {void}
   */
  opacityUp(elem, time = 1000) {
    elem.style.opacity = 0;
    let num = 0;
    const t = Math.round(time / 100);
    const interval = setInterval(() => {
      num++;
      if (num === 100) {
        clearInterval(interval);
      }
      elem.style.opacity = `${num}%`;
    }, t)
  }
}

window.mpcLazyLoad = new mpcLazyLoad();
