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
    if (!window.mpcExpandAttr) {
      window.mpcExpandAttr = 'data-svg';
    }
    if (window.mpcLazyLoad) return window.mpcLazyLoad;

    const defaults = {
      rootSelector: `[${window.mpcLazyLoadAttr}]`,
      rootAttr: window.mpcLazyLoadAttr,
      expandSelector: `[${window.mpcExpandAttr}]`,
      loadedClass: 'mpc-lazy-loaded',
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
    const elements = document.querySelectorAll(`${this.config.rootSelector}:not(${this.config.expandSelector})`);
    if (elements.length) {
      const observer = new IntersectionObserver((entries) => this.handler(entries), {
        root: null,
        rootMargin: '0px',
        threshold: 0
      });
      elements.forEach(element => {
        if (element.tagName === 'SOURCE') {
          observer.observe(element.parentNode);
        } else {
          observer.observe(element);
        }
      });
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
      if (!elem.hasAttribute(this.config.rootAttr) && !['VIDEO', 'AUDIO', 'PICTURE'].includes(elem.tagName)) continue;

      if (entries[i].isIntersecting) {
        if (!elem.classList.contains(this.config.loadedClass)) {
          this.loading(elem);
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
    if (['IMG', 'IFRAME'].includes(elem.tagName)) {
      elem.src = elem.dataset[this.config.rootKey];
    } else if (['VIDEO', 'AUDIO'].includes(elem.tagName)) {
      if (elem.dataset[this.config.rootKey]) {
        elem.src = elem.dataset[this.config.rootKey];
      }
      this.loadingSources(elem);
    } else if (elem.tagName === 'PICTURE') {
      this.loadingSources(elem);
    } else if (elem.tagName === 'SOURCE') {
      if (['VIDEO', 'AUDIO'].includes(elem.parentNode.tagName)) {
        elem.src = elem.dataset[this.config.rootKey];
        elem.parentNode.load();
      } else {
        elem.srcset = elem.dataset[this.config.rootKey];
      }
    } else {
      if (elem.dataset[this.config.rootKey].indexOf('bg:') === 0) {
        const value = elem.dataset[this.config.rootKey].replace('bg:', '');
        elem.style.backgroundImage = 'url(' + value + ')';
      }
      if (elem.dataset[this.config.rootKey].indexOf('css:') === 0) {
        const value = elem.dataset[this.config.rootKey].replace('css:', '');
        this.loadStyles(value, true);
        elem.remove();
      }
    }

    elem.removeAttribute(this.config.rootAttr);
    elem.classList.add(this.config.loadedClass);
    this.opacityUp(elem);
  }

  /**
   * @param {HTMLElement} elem
   * @returns {void}
   */
  loadingSources(elem) {
    const source = elem.querySelectorAll(`source[${this.config.rootAttr}]`);
    source.length && source.forEach(source => this.loading(source))
  }

  /**
   * @param {string} cssPath
   * @param after
   * @returns {void}
   */
  loadStyles(cssPath, after = false) {
    let css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = cssPath;
    document.head[after ? 'append' : 'prepend'](css);
  }

  /**
   * @param elem
   * @param time
   * @returns {void}
   */
  opacityUp(elem, time = 300) {
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
