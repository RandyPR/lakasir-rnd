// Runs the print script of the cashier (resources/views/filament/tenant/partials/receipt-print.blade.php, as rendered
// by Laravel) and the real Printer class (resources/js/printer.js) in a Node vm, and checks what they print.
//
// Usage: node tests/Support/receipt-harness.mjs <fixture.json> [dump directory for the preview texts]
// The fixture is written by tests/Feature/ReceiptPartialTest.php:
//   { script, printer, locale, payloads: { receipt, bill, kitchenNew, kitchenAddition, kitchenCancel, kitchenMoved,
//     kitchenReprint, kitchenVoid, kitchenTakeaway } }
// Prints one JSON line {ok, checks, failures} and exits with 1 when a check failed.
import fs from 'node:fs';
import vm from 'node:vm';

const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const dump = process.argv[3] || null;
const failures = [];
let checks = 0;

function check(name, condition, detail) {
  checks += 1;
  if (!condition) {
    failures.push(detail === undefined ? name : `${name}: ${typeof detail === 'string' ? detail : JSON.stringify(detail)}`);
  }
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// ---- the page ------------------------------------------------------------------------------

const events = [];
const notifications = [];
const storage = {};

class FakeNotification {
  constructor() {
    this.data = {};
  }

  title(title) {
    this.data.title = title;
    return this;
  }

  warning() {
    this.data.tone = 'warning';
    return this;
  }

  danger() {
    this.data.tone = 'danger';
    return this;
  }

  actions(actions) {
    this.data.actions = actions.length;
    return this;
  }

  send() {
    notifications.push(this.data);
  }
}

class FakeNotificationAction {
  icon() {
    return this;
  }

  button() {
    return this;
  }

  url() {
    return this;
  }
}

const context = {
  console: { log() {}, warn() {}, error() {}, info() {} },
  setTimeout,
  clearTimeout,
  localStorage: {
    get printer() {
      return storage.printer;
    },
    setItem(key, value) {
      storage[key] = String(value);
    },
    getItem(key) {
      return Object.prototype.hasOwnProperty.call(storage, key) ? storage[key] : null;
    },
    removeItem(key) {
      delete storage[key];
    },
  },
  CustomEvent: class {
    constructor(type, init) {
      this.type = type;
      this.detail = init ? init.detail : undefined;
    }
  },
  dispatchEvent(event) {
    events.push(event);
    return true;
  },
  FilamentNotification: FakeNotification,
  FilamentNotificationAction: FakeNotificationAction,
};
context.window = context;
vm.createContext(context);

vm.runInContext(fixture.printer, context, { filename: 'printer.js' });
vm.runInContext(fixture.script, context, { filename: 'receipt-print.js' });

const R = context.LakasirReceipt;
const RealPrinter = context.Printer;
const L = context.LakasirReceiptConfig.labels;
const P = fixture.payloads;

check('the API is installed', R && R.version === 2 && typeof R.print === 'function' && typeof RealPrinter === 'function');

if (fixture.locale === 'id') {
  check('the tickets speak Indonesian', L.kitchen === 'DAPUR' && L.newOrder === 'PESANAN BARU' && L.copy === 'SALINAN'
    && L.provisional === 'BILL SEMENTARA' && L.notProof === 'BUKAN BUKTI PEMBAYARAN', L);
}

// ---- helpers -------------------------------------------------------------------------------

function previewOf(payload, mode, paper, extra) {
  return R.preview(payload, Object.assign({ mode, paperWidth: paper, printerData: { paper_width: paper } }, extra || {}));
}

function linesOf(text) {
  return text.split('\n');
}

function has(text, needle) {
  return text.indexOf(needle) !== -1;
}

function countOf(text, needle) {
  return text.split(needle).length - 1;
}

// The text with every wrapped continuation line joined to the line before it.
function flat(text) {
  return text.replace(/\n +/g, ' ');
}

// The lines of a preview wider than the paper, a double width character counting as two columns.
function overflowOf(payload, mode, paper) {
  const printer = new R.TextPrinter({ paperWidth: paper });
  const emit = printer.emit;
  const wide = [];
  printer.emit = function (line) {
    if (line.length * this.widthScale > this.lineWidth) {
      wide.push(`${line} (x${this.widthScale})`);
    }

    return emit.call(this, line);
  };
  R.build(printer, payload, { mode, printerData: { paper_width: paper } });

  return wide;
}

// The ESC/POS bytes of one build on the real Printer.
function commandsOf(payload, mode, paper, extra) {
  const printer = new RealPrinter({ paper_width: paper, driver: 'usb' });
  R.build(printer, payload, Object.assign({ mode, printerData: { paper_width: paper } }, extra || {}));

  return printer.commands;
}

const money = (n) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(n);
const END = '\x1d\x21\x00' + '\x1b\x45\x00\x1b\x2d\x00' + '\x1b\x61\x00';

// ---- every document on both paper widths -----------------------------------------------------

const documents = [
  ['receipt', P.receipt, 'receipt'],
  ['bill', P.bill, 'bill'],
  ['kitchen new', P.kitchenNew, 'kitchen'],
  ['kitchen addition', P.kitchenAddition, 'kitchen'],
  ['kitchen cancel', P.kitchenCancel, 'kitchen'],
  ['kitchen moved', P.kitchenMoved, 'kitchen'],
  ['kitchen reprint', P.kitchenReprint, 'kitchen'],
  ['kitchen void', P.kitchenVoid, 'kitchen'],
  ['kitchen takeaway', P.kitchenTakeaway, 'kitchen'],
];

for (const paper of [58, 80]) {
  const width = paper === 80 ? 48 : 32;

  for (const [name, payload, mode] of documents) {
    const text = previewOf(payload, mode, paper);
    if (dump) {
      fs.writeFileSync(`${dump}/${name.replace(/ /g, '-')}-${paper}.txt`, text + '\n');
    }
    const tooWide = linesOf(text).filter((line) => line.length > width).concat(overflowOf(payload, mode, paper));
    check(`${name} ${paper}mm fits the paper`, tooWide.length === 0, tooWide);
    check(`${name} ${paper}mm is printable ASCII`, /^[\x20-\x7e\n]*$/.test(text), text.match(/[^\x20-\x7e\n]/g));

    const commands = commandsOf(payload, mode, paper);
    check(`${name} ${paper}mm ends with normal size, style and left alignment`, commands.endsWith(END),
      JSON.stringify(commands.slice(-24)));
    check(`${name} ${paper}mm cuts only where the queue cuts`, !has(commands, '\x1d\x56'));

    // preview() is build() on a TextPrinter.
    const textPrinter = new R.TextPrinter({ paperWidth: paper });
    R.build(textPrinter, payload, { mode, printerData: { paper_width: paper } });
    check(`${name} ${paper}mm preview equals the build`, textPrinter.toString() === text);
  }
}

// ---- the receipt ------------------------------------------------------------------------------

{
  const text = previewOf(P.receipt, 'receipt', 58);
  const lines = linesOf(text);

  check('receipt: qty x unit price and the line total', lines.some((l) => /^ {2}2 x 25\.000 +50\.000$/.test(l)), lines);
  check('receipt: a unit price that does not multiply back is marked ~', lines.some((l) => /^ {2}3 x ~3\.333,33 +10\.000$/.test(l)),
    lines);
  check('receipt: line discount', has(text, `${L.itemDiscount} (5.000)`), lines);
  check('receipt: the note of a line', has(text, '  > pedas, tanpa telur'));
  check('receipt: a name without Latin letters prints the SKU', has(text, 'TERIYAKI-01'));
  check('receipt: smart quotes become plain quotes', has(text, '"pedas" \'sedang\''), lines);
  // The order note is a TEXT column: 300 characters without a space are possible there (line notes stop at 255).
  const word = lines.filter((l) => /^a+$/.test(l));
  check('receipt: a 300 character word is wrapped, nothing is lost', word.length === 10 && word.join('').length === 300,
    word);
  const about = context.LakasirReceiptConfig.about || {};
  check('receipt: a long shop name and address are wrapped, nothing is lost', Boolean(about.shop_name)
    && has(flat(text), about.shop_name) && has(flat(text), about.shop_location), lines.slice(0, 8));
  check('receipt: two categories are titled', has(text, '[ MAKANAN ]'));
  check('receipt: a long category is wrapped', lines.some((l) => l.startsWith('[ MINUMAN DINGIN')));
  check('receipt: customer name', has(text, '"VIP"'));
  check('receipt: the order note', has(text, 'Tolong cepat'));
  check('receipt: paid and change', has(text, L.payedMoney) && has(text, L.change));
  check('receipt: no "not paid" on a sale', !has(text, L.notPaid) && !has(text, L.notProof));

  const commands = commandsOf(P.receipt, 'receipt', 58);
  check('receipt: printer commands in a note are neutralised', !has(commands, '\x1b@') && !has(commands, '\x1dV0')
    && has(commands, 'jangan dipotong'));

  const shown = previewOf(P.receipt, 'receipt', 58, { printerData: { paper_width: 58, show_currency: true } });
  check('receipt: currency shown when the printer asks for it', has(shown, 'Rp 50.000'));
  check('receipt: no currency by default', !has(text, 'Rp '));

  const wide = previewOf(P.receipt, 'receipt', 80);
  check('receipt 80mm: 48 columns are used', linesOf(wide).some((l) => l.length > 32));
}

// ---- the provisional bill -------------------------------------------------------------------------

{
  const text = previewOf(P.bill, 'bill', 58);

  check('bill: says provisional and not paid', has(text, L.provisional) && has(text, L.notPaid));
  check('bill: not a proof of payment', has(text, L.notProof));
  check('bill: no payment and no change rows', !has(text, L.payedMoney) && !has(text, L.change));
  check('bill: line prices and the total', /^ {2}2 x 25\.000 +50\.000$/m.test(text) && has(text, L.totalPrice)
    && has(text, money(P.bill.amount_due)), text);
  check('bill: copy marker only on a reprint', !has(text, L.copy) && has(previewOf(P.bill, 'bill', 58, { reprint: true }), L.copy));
}

// ---- kitchen tickets -------------------------------------------------------------------------------

function title(text) {
  return linesOf(text).map((l) => l.trim()).filter(Boolean)[0];
}

{
  const text = previewOf(P.kitchenNew, 'kitchen', 58);

  check('kitchen new: title', title(text) === `${L.kitchen} - ${L.newOrder}`, title(text));
  check('kitchen new: the table in big letters', has(text, L.tableWord.replace(':number', '5')));
  check('kitchen new: qty x name and the note', /^2 x Nasi Goreng Spesial$/m.test(text) && has(text, '  > pedas'), text);
  check('kitchen new: no prices by default', !has(text, '25.000') && !has(text, '50.000'), text);
  check('kitchen new: shipment number at the end', has(text, L.shipmentN.replace(':n', '1')));

  const priced = previewOf(P.kitchenNew, 'kitchen', 58, { showPrices: true });
  check('kitchen new: prices when the setting asks for them', /^ {2}2 x 25\.000 +50\.000$/m.test(priced), priced);
}

{
  const text = previewOf(P.kitchenAddition, 'kitchen', 58);

  check('kitchen addition: title with the shipment', title(text) === `${L.kitchen} - ${L.additionN.replace(':n', '2')}`,
    title(text));
  check('kitchen addition: only the difference, with the new total',
    has(flat(text), `1 x Nasi Goreng Spesial ${L.totalN.replace(':n', '3')}`), text);
  check('kitchen addition: "(total n)" is never split over two lines',
    linesOf(text).some((l) => has(l, L.totalN.replace(':n', '3'))), text);
  check('kitchen addition: lines not changed are not repeated', !has(text, 'Es Teh'), text);
}

{
  const text = previewOf(P.kitchenCancel, 'kitchen', 58);

  check('kitchen cancel: the cancelled line', has(text, `${L.cancelled}:`) && /^1 x Es Teh$/m.test(text), text);
  check('kitchen cancel: the changed note', has(text, `${L.changedNote}:`)
    && has(flat(text), 'Nasi Goreng Spesial: pedas -> tidak pedas'), text);
  check('kitchen cancel: nothing new to cook', title(text) === `${L.kitchen} - ${L.cancelOnly}`, title(text));
}

{
  const text = previewOf(P.kitchenMoved, 'kitchen', 58);
  const moved = L.moved.replace(':from', '5').replace(':to', '7');

  check('kitchen moved: from and to table', has(flat(text), moved), text);
  check('kitchen moved: the new table in big letters', has(text, L.tableWord.replace(':number', '7')));
}

{
  const text = previewOf(P.kitchenReprint, 'kitchen', 58);
  const lines = linesOf(text).map((l) => l.trim()).filter(Boolean);

  check('kitchen reprint: COPY at the top and at the bottom', lines[0] === L.copy && lines[lines.length - 1] === L.copy, lines);
}

{
  const text = previewOf(P.kitchenVoid, 'kitchen', 58);

  check('kitchen void: title', title(text) === `${L.kitchen} - ${L.voided}`, title(text));
  check('kitchen void: the reason', has(flat(text), `${L.voidReason}: Pelanggan pergi`), text);
  check('kitchen void: what the kitchen already made is cancelled', has(text, `${L.cancelled}:`)
    && /^3 x Nasi Goreng Spesial$/m.test(text), text);
}

{
  const text = previewOf(P.kitchenTakeaway, 'kitchen', 58);
  const number = String(P.kitchenTakeaway.daily_order_number).padStart(3, '0');

  check('kitchen takeaway: TAKEAWAY and the number in big letters', has(flat(text), L.takeaway.replace(':number', number)),
    text);
}

// ---- the print queue -----------------------------------------------------------------------------

const prints = [];
let failNext = false;

class FakePrinter {
  constructor(options) {
    this.paperWidth = Number(options && options.paper_width) === 80 ? 80 : 58;
    this.lineWidth = this.paperWidth === 80 ? 48 : 32;
    this.commands = '';
  }

  font() { return this; }

  align() { return this; }

  style() { return this; }

  size() { return this; }

  barcode() { return this; }

  newLine() { return this; }

  text(text) {
    this.commands += text + '\n';
    return this;
  }

  table(data) {
    this.commands += data.join(' ') + '\n';
    return this;
  }

  cut() {
    this.commands += '[cut]';
    return this;
  }

  async image() {
    return this;
  }

  // Like Printer.print(): the flag is up while it works and for a moment after.
  async print() {
    if (failNext) {
      failNext = false;
      throw new Error('offline');
    }
    context._lakasirIsPrintingNow = true;
    const started = Date.now();
    await sleep(100);
    prints.push({ started, ended: Date.now(), commands: this.commands });
    setTimeout(() => {
      context._lakasirIsPrintingNow = false;
    }, 20);
  }
}

async function queueChecks() {
  context.Printer = FakePrinter;
  R.timing.pollMs = 5;
  R.timing.dedupeMs = 400;
  R.timing.maxWaitMs = 3000;

  // No printer saved: false, and the page is told why.
  let ok = await R.print(P.receipt, { mode: 'receipt', jobKey: 'none' });
  const missing = events.filter((e) => e.type === 'lakasir-print-failed').pop();
  check('queue: no printer resolves false', ok === false && prints.length === 0);
  check('queue: no printer is reported', missing && missing.detail.reason === 'no_printer' && missing.detail.jobKey === 'none');

  storage.printer = JSON.stringify({ driver: 'usb', paper_width: 58 });

  // Two jobs at once: both printed, one after the other.
  const first = R.print(P.kitchenNew, { mode: 'kitchen', jobKey: 'kitchen-1-b1' });
  const second = R.print(P.kitchenAddition, { mode: 'kitchen', jobKey: 'kitchen-1-b2' });
  const results = await Promise.all([first, second]);
  check('queue: two jobs both print', results[0] === true && results[1] === true && prints.length === 2, results);
  check('queue: the second waits for the first', prints.length === 2 && prints[1].started >= prints[0].ended,
    prints.map((p) => [p.started, p.ended]));
  check('queue: each job ends with one cut', prints.every((p) => countOf(p.commands, '[cut]') === 1));

  // The same key while it runs and right after it printed: the same Promise, one print.
  const a = R.print(P.bill, { mode: 'bill', jobKey: 'bill-9' });
  const b = R.print(P.bill, { mode: 'bill', jobKey: 'bill-9' });
  check('queue: the same key gives the same Promise', a === b);
  await a;
  const c = R.print(P.bill, { mode: 'bill', jobKey: 'bill-9' });
  check('queue: the same key right after printing is not printed again', c === a && prints.length === 3, prints.length);
  await sleep(R.timing.dedupeMs + 50);
  ok = await R.print(P.bill, { mode: 'bill', jobKey: 'bill-9' });
  check('queue: after the guard time it prints again', ok === true && prints.length === 4, prints.length);

  // A failure resolves false, is reported, and can be retried at once.
  const before = notifications.length;
  failNext = true;
  ok = await R.print(P.kitchenNew, { mode: 'kitchen', jobKey: 'kitchen-2-b1', ack: { bill_id: 2, batch_no: 1 } });
  const failed = events.filter((e) => e.type === 'lakasir-print-failed').pop();
  check('queue: a failing printer resolves false', ok === false);
  check('queue: the failure carries the job and its ack', failed && failed.detail.jobKey === 'kitchen-2-b1'
    && failed.detail.reason === 'error' && failed.detail.ack && failed.detail.ack.batch_no === 1, failed && failed.detail);
  check('queue: a kitchen failure is left to the page (no notification here)', notifications.length === before);
  ok = await R.print(P.kitchenNew, { mode: 'kitchen', jobKey: 'kitchen-2-b1' });
  check('queue: a failed job can be retried at once', ok === true && prints.length === 5, prints.length);

  failNext = true;
  ok = await R.print(P.receipt, { mode: 'receipt', jobKey: 'receipt-3' });
  check('queue: a receipt failure is notified here', ok === false && notifications.length === before + 1, notifications);

  // A job that never reaches the printer (Printer.print() returned without raising the flag) is not a success.
  context.Printer = class extends FakePrinter {
    async print() {}
  };
  ok = await R.print(P.receipt, { mode: 'receipt', jobKey: 'receipt-4' });
  const skipped = events.filter((e) => e.type === 'lakasir-print-failed').pop();
  check('queue: a print that did not run is a failure', ok === false && skipped.detail.reason === 'not_executed');
}

try {
  await queueChecks();
} catch (error) {
  failures.push('queue: ' + (error && error.stack ? error.stack : error));
}

process.stdout.write(JSON.stringify({ ok: failures.length === 0, checks, failures }) + '\n');
process.exit(failures.length === 0 ? 0 : 1);
