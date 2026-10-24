// Injected before the page scripts: a configured printer (browser driver) whose print() only records the commands.
(() => {
  try {
    localStorage.setItem('printer', JSON.stringify({ driver: 'browser', paper_width: 58, name: 'Fake printer' }));
  } catch (e) {}
  window.__prints = [];
  window.__printFail = false;
  let RealPrinter;
  Object.defineProperty(window, 'Printer', {
    configurable: true,
    get() { return RealPrinter; },
    set(v) {
      RealPrinter = v;
      if (v && v.prototype && !v.prototype.__stubbed) {
        v.prototype.__stubbed = true;
        v.prototype.print = async function () {
          if (window.__printFail) {
            throw new Error('fake printer offline');
          }
          window._lakasirIsPrintingNow = true;
          window.__prints.push(String(this.commands || ''));
          setTimeout(() => { window._lakasirIsPrintingNow = false; }, 200);
        };
      }
    },
  });
})();
