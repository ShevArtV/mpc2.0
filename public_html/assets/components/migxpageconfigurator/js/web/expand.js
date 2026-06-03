/**
 * Модуль разворачивания svg
 */

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 * @class mpcExpand - класс отложенной загрузки
 * @property {Object} config - конфигурация модуля
 * @property {Object} events - события модуля
 */
class mpcExpand {
  constructor(config = {}) {
    if (!window.mpcExpandAttr) {
      window.mpcExpandAttr = 'data-svg';
    }
    if (!window.mpcLazyLoadAttr) {
      window.mpcLazyLoadAttr = 'data-lazy';
    }
    if (window.mpcExpand) return window.mpcExpand;

    const defaults = {
      rootSelector: `[${window.mpcExpandAttr}]`,
      rootAttr: window.mpcExpandAttr,
    }
    this.events = {
      onload: 'mpc:svg:loaded'
    };
    this.config = Object.assign(defaults, config);
    this.initialize();
    window.mpcExpand = this;
  }

  /**
   * @returns {void}
   */
  initialize() {
    this.config.rootKey = this.getDataAttrKey(this.config.rootAttr);
    this.config.lazyLoadKey = this.getDataAttrKey(window.mpcLazyLoadAttr);
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
        this.loading(elem);
        elem.removeAttribute(this.config.rootAttr);

        elem.dispatchEvent(new CustomEvent(this.events.onload, {
          bubbles: true,
          cancelable: false,
          detail: {
            entry: entries[i],
            Expand: this
          }
        }));
      }
    }
  }

  /**
   * @param elem
   * @returns {void}
   */
  async loading(elem) {
    const path = elem.dataset[this.config.lazyLoadKey] || elem.src;
    const content = await this.getFileContents(path);
    content && this.getImgData(elem, content);
  }

  /**
   * @param {string} src - The URL or file path of the file to read.
   * @returns {Promise<string>} A promise that resolves with the contents of the file as text.
   */
  getFileContents(src) {
    return fetch(src)
      .then(response => {
        if (!response.ok) {
          throw new Error(`Error reading file: ${response.statusText}`);
        }
        return response.text();
      })
      .catch(error => {
        console.error(`Error reading file: ${error}`);
        return null;
      });
  }

  /**
   * @param {Element} el
   * @param {string} content
   * @returns {void}
   */
  getImgData(el, content) {
    const svg = new DOMParser().parseFromString(content, "text/html").getElementsByTagName("svg")[0];
    // Контент по data-lazy/src оказался не SVG (битый путь, растровый файл,
    // медиа): <svg> нет — выходим, не роняя страницу (иначе removeAttribute
    // of undefined). Элемент остаётся как есть.
    if (!svg) return;
    const attributes = ['width', 'height', 'id', 'class'];

    svg.removeAttribute('xmlns');

    for (let i in attributes) {
      if (el.hasAttribute(attributes[i])) {
        svg.setAttribute(attributes[i], el.getAttribute(attributes[i]));
      }
    }

    if (!svg.getAttribute('viewBox') && svg.getAttribute('height') && svg.getAttribute('width')) {
      svg.setAttribute('viewBox', '0 0 ' + svg.getAttribute('height') + svg.getAttribute('width'));
    }
    el.replaceWith(svg);
  }
}

window.mpcExpand = new mpcExpand();
