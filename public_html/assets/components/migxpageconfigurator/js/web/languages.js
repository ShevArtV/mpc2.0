/**
 * Модуль переключения языков
 */

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 * @class mpcExpand - класс отложенной загрузки
 * @property {Object} config - конфигурация модуля
 * @property {Object} events - события модуля
 */
class mpcLanguages {
  constructor(config = {}) {
    if (window.mpcLanguages) return window.mpcLanguages;

    const defaults = {
      rootSelector: '[data-choose-lang]',
      rootAttr: 'data-choose-lang',
      cookieName: 'mpc_lang'
    }
    this.events = {
      onChoose: 'mpc:change:language'
    };
    this.config = Object.assign(defaults, config);
    this.initialize();
    window.mpcLanguages = this;
  }

  /**
   * @returns {void}
   */
  initialize() {
    const roots = document.querySelectorAll(this.config.rootSelector);
    if(!roots.length) return;

    document.addEventListener('change', e => {
      if (e.target.closest(this.config.rootSelector)) {
        this.handle(e.target.closest(this.config.rootSelector));
      }
    })

  }

  handle(root) {
    this.setCookie(this.config.cookieName, root.value);
    if (!document.dispatchEvent(new CustomEvent(this.events.onChoose, {
      bubbles: true,
      cancelable: true,
      detail: {
        root: root,
        mpcLanguages: this
      }
    }))) {
      return;
    }
    window.location.reload();
  }

  setCookie(name, value, options = {}) {
    const fullDomain = window.location.hostname;
    const rootDomain = fullDomain.replace(/^[^.]*\./, '');
    options = {
      path: '/',
      domain: '.' + rootDomain,
      ...options
    };

    if (options.expires instanceof Date) {
      options.expires = options.expires.toUTCString();
    }

    let updatedCookie = encodeURIComponent(name) + "=" + encodeURIComponent(value);

    for (let optionKey in options) {
      updatedCookie += "; " + optionKey;
      let optionValue = options[optionKey];
      if (optionValue !== true) {
        updatedCookie += "=" + optionValue;
      }
    }

    document.cookie = updatedCookie;
  }
}

window.mpcLanguages = new mpcLanguages();
