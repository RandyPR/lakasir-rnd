{{--
  Shared by the bills drawer (livewire.cashier.open-bills) and the print preview modal (bills.preview-modal).
  Raw JavaScript, no <script> tag: each caller wraps it. Defines window.LakasirDialog once per page.

  LakasirDialog.activate(root, options) makes `root` a modal layer:
    - everything around it (the siblings along its ancestor chain) gets `inert`, except Filament notifications
      (.fi-no) and anything marked data-dialog-exempt, so toasts stay clickable;
    - Tab and Shift+Tab stay inside `root`; focus that escapes is pulled back;
    - Escape calls options.onEscape() (only the topmost layer reacts);
    - the page does not scroll behind it.
  options: onEscape(): void, initialFocus: Element|null (default: root, which needs tabindex="-1").
  Returns { deactivate(returnTo?: Element|Element[]) }: restores everything it changed, then focuses the first
  element of returnTo (else the element focused when the layer opened) that can take focus.

  A Livewire morph can strip the `inert` attribute from elements it re-renders, so a MutationObserver puts it back.
--}}
@verbatim
window.LakasirDialog = window.LakasirDialog || (function () {
  var layers = [];
  var savedOverflow = null;
  var EXEMPT = '.fi-no, [data-dialog-exempt]';
  var FOCUSABLE = 'a[href], button, input, select, textarea, summary, [tabindex], [contenteditable="true"]';
  var SKIPPED_TAGS = ['SCRIPT', 'STYLE', 'TEMPLATE', 'LINK', 'META'];

  function isVisible(el) {
    return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
  }

  function focusables(root) {
    return Array.prototype.filter.call(root.querySelectorAll(FOCUSABLE), function (el) {
      if (el.disabled || el.hidden || el.getAttribute('tabindex') === '-1') {
        return false;
      }
      if (el.tagName === 'INPUT' && el.type === 'hidden') {
        return false;
      }
      if (el.closest('[inert]')) {
        return false;
      }

      return isVisible(el);
    });
  }

  function top() {
    return layers[layers.length - 1] || null;
  }

  function inertAround(layer) {
    var node = layer.root;

    while (node && node.parentElement && node !== document.body) {
      var parent = node.parentElement;
      var keep = node;

      Array.prototype.forEach.call(parent.children, function (sibling) {
        if (sibling === keep || sibling.hasAttribute('inert') || SKIPPED_TAGS.indexOf(sibling.tagName) !== -1) {
          return;
        }
        if (sibling.matches(EXEMPT) || sibling.querySelector(EXEMPT)) {
          return;
        }
        sibling.setAttribute('inert', '');
        layer.inerted.push(sibling);
      });

      node = parent;
    }

    layer.observer = new MutationObserver(function (records) {
      records.forEach(function (record) {
        var target = record.target;
        if (layer.active && layer.inerted.indexOf(target) !== -1 && !target.hasAttribute('inert')) {
          target.setAttribute('inert', '');
        }
      });
    });
    layer.inerted.forEach(function (el) {
      layer.observer.observe(el, { attributes: true, attributeFilter: ['inert'] });
    });
  }

  function onKeydown(event) {
    var layer = top();
    if (!layer) {
      return;
    }

    if (event.key === 'Escape') {
      if (event.isComposing) {
        return;
      }
      event.preventDefault();
      event.stopPropagation();
      if (typeof layer.onEscape === 'function') {
        layer.onEscape(event);
      }

      return;
    }

    if (event.key !== 'Tab') {
      return;
    }

    var items = focusables(layer.root);
    if (!items.length) {
      event.preventDefault();
      layer.root.focus();

      return;
    }

    var first = items[0];
    var last = items[items.length - 1];
    var active = document.activeElement;

    if (!layer.root.contains(active) || active === layer.root) {
      event.preventDefault();
      (event.shiftKey ? last : first).focus();
    } else if (event.shiftKey && active === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && active === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function onFocusin(event) {
    var layer = top();
    var target = event.target;
    if (!layer || !(target instanceof Element) || layer.root.contains(target) || target.closest(EXEMPT)) {
      return;
    }

    var items = focusables(layer.root);
    (items[0] || layer.root).focus();
  }

  function activate(root, options) {
    options = options || {};

    var layer = {
      root: root,
      onEscape: options.onEscape,
      opener: document.activeElement,
      inerted: [],
      observer: null,
      active: true,
    };

    if (!layers.length) {
      savedOverflow = document.documentElement.style.overflow;
      document.documentElement.style.overflow = 'hidden';
      document.addEventListener('keydown', onKeydown, true);
      document.addEventListener('focusin', onFocusin);
    }

    layers.push(layer);
    inertAround(layer);

    var initial = options.initialFocus || root;
    if (initial && typeof initial.focus === 'function') {
      initial.focus({ preventScroll: true });
    }

    return {
      deactivate: function (returnTo) {
        if (!layer.active) {
          return;
        }

        layer.active = false;
        if (layer.observer) {
          layer.observer.disconnect();
        }
        layer.inerted.forEach(function (el) {
          el.removeAttribute('inert');
        });
        layers.splice(layers.indexOf(layer), 1);

        if (!layers.length) {
          document.documentElement.style.overflow = savedOverflow || '';
          document.removeEventListener('keydown', onKeydown, true);
          document.removeEventListener('focusin', onFocusin);
        }

        var candidates = [].concat(returnTo || [], [layer.opener]);
        for (var i = 0; i < candidates.length; i++) {
          var candidate = candidates[i];
          if (candidate && candidate.isConnected && typeof candidate.focus === 'function') {
            candidate.focus({ preventScroll: true });
            if (document.activeElement === candidate) {
              return;
            }
          }
        }
      },
    };
  }

  return { activate: activate, focusables: focusables };
})();
@endverbatim
