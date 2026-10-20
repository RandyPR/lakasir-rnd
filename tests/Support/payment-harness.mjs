// Runs the Alpine component of the payment dialog (paymentFlow() of
// resources/views/filament/tenant/pages/cashier/payment/script.blade.php, as rendered by Laravel) in a Node vm with a
// fake $wire, and checks its arithmetic and what it sends to the server.
//
// Usage: node tests/Support/payment-harness.mjs <fixture.json>
// The fixture is written by tests/Feature/PaymentFlowTest.php: { script, locale }
// Prints one JSON line {ok, checks, failures} and exits with 1 when a check failed.
import fs from 'node:fs';
import vm from 'node:vm';

const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const failures = [];
let checks = 0;

function check(name, condition, detail) {
  checks += 1;
  if (!condition) {
    failures.push(detail === undefined ? name : `${name}: ${typeof detail === 'string' ? detail : JSON.stringify(detail)}`);
  }
}

const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

// Lets every pending promise of the component run (its awaits may cross the vm realm boundary).
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

// ---- the page ------------------------------------------------------------------------------

const storage = {};
const events = [];

const context = {
  console: { log() {}, warn() {}, error() {}, info() {} },
  setTimeout,
  clearTimeout,
  innerWidth: 1024,
  localStorage: {
    getItem(key) {
      return Object.prototype.hasOwnProperty.call(storage, key) ? storage[key] : null;
    },
    setItem(key, value) {
      storage[key] = String(value);
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
};
context.window = context;
vm.createContext(context);
vm.runInContext(fixture.script, context, { filename: 'payment-script.js' });

const L = context.lakasirPaymentLabels || {};

check('the component is installed', typeof context.paymentFlow === 'function');
if (fixture.locale === 'id') {
  check('the dialog speaks Indonesian', L.change === 'Kembalian' && L.shortBy === 'Kurang', L);
}

const CASH = { id: 1, name: 'Cash', is_cash: true, is_credit: false, is_wallet: false, is_debit: false };
const QRIS = { id: 2, name: 'QRIS', is_cash: false, is_credit: false, is_wallet: true, is_debit: false };
const CREDIT = { id: 3, name: 'Kredit', is_cash: false, is_credit: true, is_wallet: false, is_debit: false };
const METHODS = [CASH, QRIS, CREDIT];

function fakeWire(over) {
  const wire = {
    total_price: 55000,
    paymentMethods: METHODS,
    cartItems: [{ id: 10 }],
    cartDetail: {},
    openBillId: null,
    paymentPinned: false,
    calls: [],
    onProceed: null,
    set(name, value, live) {
      this.calls.push(['set', name, value, live]);
    },
    async syncCartForPayment() {
      this.calls.push(['syncCartForPayment']);
    },
    paymentClosed() {
      this.calls.push(['paymentClosed']);
    },
    async proceedThePayment() {
      this.calls.push(['proceedThePayment']);
      if (this.onProceed) {
        await this.onProceed();
      }
    },
  };

  return Object.assign(wire, over || {});
}

const dispatched = [];

// A dialog as it is on screen right after it opened: method chosen, ready, nothing typed.
function dialog(wireOver, refs) {
  const flow = context.paymentFlow();
  flow.$wire = fakeWire(wireOver);
  flow.$refs = refs || {};
  flow.$nextTick = (fn) => fn();
  flow.$dispatch = (name, detail) => dispatched.push([name, detail]);
  flow.reset();
  flow.sheetOpen = true;
  flow.chooseInitialMethod();
  flow.ready = true;

  return flow;
}

function sets(flow) {
  const map = {};
  flow.$wire.calls.filter((c) => c[0] === 'set').forEach((c) => {
    map[c[1]] = c[2];
  });

  return map;
}

function proceeds(flow) {
  return flow.$wire.calls.filter((c) => c[0] === 'proceedThePayment').length;
}

function type(flow, keys) {
  keys.forEach((key) => flow.press(key));
}

async function main() {
  const f = dialog();

  // ---- pure helpers ----------------------------------------------------------------------------

  check('suggestions for 55.000', same(f.suggestionsFor(55000), [60000, 100000]), f.suggestionsFor(55000));
  check('suggestions for nothing to pay', same(f.suggestionsFor(0), [10000, 20000]) && same(f.suggestionsFor(-5), [10000, 20000]));
  check('suggestions skip steps far below the total', !f.suggestionsFor(100000).includes(105000), f.suggestionsFor(100000));

  const totals = [1, 999, 4999, 5000, 12500, 49999, 50000, 55000, 99999.5, 100000, 123456, 750000, 2345678, 9999999];
  totals.forEach((total) => {
    const list = f.suggestionsFor(total);
    check(`suggestions for ${total}: two notes above it, ascending, rounded to 5.000`, list.length === 2
      && list[0] > total && list[1] > list[0] && list.every((n) => Number.isInteger(n) && n % 5000 === 0), list);
  });

  const typed = (start, keys) => keys.reduce((value, key) => f.nextValue(value, key), start);
  check('numpad: no leading zero', typed('', ['0', '0', '5']) === '5' && typed('', ['000']) === '');
  check('numpad: 000 appends three zeros', typed('5', ['000']) === '5000');
  check('numpad: at most 11 digits', typed('12345678901', ['2']) === '12345678901' && typed('12345678', ['000']) === '12345678000'
    && typed('123456789', ['000']) === '123456789');
  check('numpad: backspace and clear', typed('1234', ['backspace']) === '123' && typed('1234', ['bk']) === '123'
    && typed('1234', ['clear']) === '');
  check('numpad: unknown keys change nothing', typed('12', ['x', '.', '']) === '12');

  check('kind: cash flags', f.kindOf(CASH) === 'cash');
  check('kind: wallet is exact', f.kindOf(QRIS) === 'exact');
  check('kind: cash that is also debit or wallet is exact', f.kindOf({ is_cash: true, is_debit: true }) === 'exact'
    && f.kindOf({ is_cash: true, is_wallet: true }) === 'exact');
  check('kind: credit wins over cash', f.kindOf({ is_cash: true, is_credit: true }) === 'credit');
  check('kind: the kind sent by the server wins', f.kindOf({ kind: 'cash', is_credit: true }) === 'cash');
  check('kind: no method is cash', f.kindOf(null) === 'cash');
  [CASH, QRIS, CREDIT, { is_debit: true }, {}].forEach((flags) => {
    const kinds = ['Cash', 'QRIS', 'Kredit', 'GoPay', 'Debit BCA'].map((name) => f.kindOf(Object.assign({}, flags, { name })));
    check(`kind: never from the name (${JSON.stringify(flags)})`, kinds.every((k) => k === kinds[0]), kinds);
  });

  check('numbers in Indonesian', f.fmtNum(1234567.5) === '1.234.567,5', f.fmtNum(1234567.5));

  // ---- cash ------------------------------------------------------------------------------------

  check('cash: the first method when nothing is remembered', String(f.methodId) === '1' && f.kind === 'cash');
  check('cash: asks for the received amount first', f.reason === L.enterReceived && !f.canSubmit
    && f.changeLine.text === '', f.reason);
  check('cash: the pay button shows the total', f.submitLabel === L.payAmount.replace(':amount', f.fmt(55000)), f.submitLabel);

  type(f, ['5', '0', '0', '0', '0']);
  check('cash: typed 50.000 is short by 5.000', f.received === '50000' && f.short === 5000 && !f.cashOk
    && f.reason === `${L.shortBy} ${f.fmt(5000)}` && f.status.tone === 'bad' && f.changeLine.tone === 'bad', [f.reason, f.status]);

  f.quick(60000);
  check('cash: a quick amount replaces the typed one', f.received === '60000' && f.cashOk && f.change === 5000
    && f.reason === '' && f.canSubmit, [f.received, f.change, f.reason]);
  check('cash: the change is shown', f.status.text === `${L.change} ${f.fmt(5000)}` && f.status.tone === 'good'
    && f.changeLine.tone === 'good', f.status);

  f.press('clear');
  f.add(20000);
  f.add(50000);
  check('cash: banknotes add up', f.received === '70000' && f.change === 15000, f.received);

  f.quick(55000);
  check('cash: the exact amount', f.change === 0 && f.status.text === L.exactPayment, f.status);

  f.press('backspace');
  check('cash: backspace on the amount', f.received === '5500' && f.short === 49500);

  f.add(1e12);
  check('cash: banknotes stop at 11 digits', f.received === '99999999999', f.received);

  const cents = dialog({ total_price: 55000.5 });
  cents.quick(55000);
  const shortCent = !cents.cashOk && cents.short === 0.5;
  cents.quick(cents.exactAmount);
  check('cash: cents count (55.000,5 needs 55.001)', cents.exactAmount === 55001 && shortCent && cents.cashOk
    && cents.change === 0.5, [cents.exactAmount, cents.change]);

  const free = dialog({ total_price: 0 });
  check('cash: nothing to pay needs no amount', free.cashOk && free.reason === '');

  const busy = dialog();
  busy.submitting = true;
  busy.press('7');
  busy.add(20000);
  busy.quick(50000);
  busy.selectMethod(QRIS);
  check('cash: nothing changes while paying', busy.received === '' && String(busy.methodId) === '1');

  // ---- exact (wallet, debit, ...) -------------------------------------------------------------------

  const exact = dialog();
  exact.quick(60000);
  exact.selectMethod(QRIS);
  check('exact: asks to confirm the money arrived', exact.kind === 'exact' && exact.reason === L.confirmReceived);
  exact.toggleExact();
  check('exact: confirmed', exact.reason === '' && exact.status.text === L.exactPayment);
  exact.selectMethod(CASH);
  exact.selectMethod(QRIS);
  check('exact: choosing the method again asks again', exact.exactConfirmed === false);

  // ---- credit ------------------------------------------------------------------------------------

  const credit = dialog();
  credit.selectMethod(CREDIT);
  type(credit, ['2', '0', '0', '0', '0']);
  check('credit: digits are the down payment', credit.down === '20000' && credit.received === ''
    && credit.remaining === 35000, [credit.down, credit.remaining]);
  credit.press('clear');
  type(credit, ['6', '0', '0', '0', '0']);
  check('credit: a down payment above the total is refused', credit.down === '6000' && credit.downLimit === true, credit.down);
  credit.press('0');
  credit.press('backspace');
  check('credit: the warning goes away with the next valid key', credit.downLimit === false && credit.down === '600');

  credit.memberId = '';
  check('credit: a member is required', credit.reason === L.memberRequired);
  credit.memberId = '3';
  credit.dueDate = '';
  check('credit: a due date is required', credit.reason === L.dueRequired);
  credit.dueDate = '2000-01-01';
  check('credit: a due date in the past is refused', credit.reason === L.dueRequired);
  credit.dueDate = credit.today;
  check('credit: due today is fine', credit.reason === '' && credit.submitLabel === L.pay);
  check('credit: the remaining receivable is shown', credit.status.text === `${L.receivable} ${credit.fmt(credit.remaining)}`,
    credit.status);

  // ---- what is sent ------------------------------------------------------------------------------

  const cash = dialog();
  cash.quick(60000);
  cash.stockOverride = true; // no override panel on screen: not sent as true
  await cash.submitPayment();
  const cashSets = sets(cash);
  check('submit cash: the method, the money and the change', cashSets['cartDetail.payment_method_id'] === 1
    && cashSets['cartDetail.payed_money'] === 60000 && cashSets['cartDetail.money_changes'] === 5000
    && cashSets['cartDetail.due_date'] === null && !('cartDetail.member_id' in cashSets), cashSets);
  check('submit cash: deferred sets, then one request', cash.$wire.calls.filter((c) => c[0] === 'set').every((c) => c[3] === false)
    && proceeds(cash) === 1 && cash.$wire.calls[cash.$wire.calls.length - 1][0] === 'proceedThePayment');
  check('submit cash: the stock override needs its panel', cashSets.stockOverride === false && cashSets.printKitchenOnPay === true);
  const submitEvent = events.filter((e) => e.type === 'lakasir-payment-submit').pop();
  check('submit cash: the page is told', submitEvent && submitEvent.detail.kind === 'cash' && submitEvent.detail.bill === false);
  check('submit cash: the method is remembered', storage['lakasir.cashier.lastPaymentMethod'] === '1');

  const override = dialog({}, { overridePanel: {} });
  override.quick(60000);
  override.stockOverride = true;
  override.printKitchen = false;
  await override.submitPayment();
  check('submit: the stock override with its panel, the kitchen switch off', sets(override).stockOverride === true
    && sets(override).printKitchenOnPay === false);

  const qris = dialog();
  qris.selectMethod(QRIS);
  qris.received = '12345';
  qris.toggleExact();
  await qris.submitPayment();
  check('submit exact: no client amount', sets(qris)['cartDetail.payed_money'] === null
    && sets(qris)['cartDetail.money_changes'] === 0 && sets(qris)['cartDetail.payment_method_id'] === 2, sets(qris));

  const remembered = dialog();
  check('the last method is chosen next time', String(remembered.methodId) === '2');
  delete storage['lakasir.cashier.lastPaymentMethod'];
  check('the method of the order is chosen when nothing is remembered',
    String(dialog({ cartDetail: { payment_method_id: 3, member_id: 7 } }).methodId) === '3'
    && dialog({ cartDetail: { member_id: 7 } }).memberId === '7');
  storage['lakasir.cashier.lastPaymentMethod'] = '99';
  check('a remembered method that is gone is ignored', String(dialog().methodId) === '1');
  delete storage['lakasir.cashier.lastPaymentMethod'];

  const kredit = dialog();
  kredit.selectMethod(CREDIT);
  type(kredit, ['2', '0', '0', '0', '0']);
  kredit.memberId = '3';
  await kredit.submitPayment();
  const creditSets = sets(kredit);
  check('submit credit: down payment, due date and member', creditSets['cartDetail.payed_money'] === 20000
    && creditSets['cartDetail.money_changes'] === 0 && creditSets['cartDetail.due_date'] === kredit.dueDate
    && creditSets['cartDetail.member_id'] === 3, creditSets);
  delete storage['lakasir.cashier.lastPaymentMethod'];

  const twice = dialog();
  twice.quick(60000);
  let release;
  twice.$wire.onProceed = () => new Promise((resolve) => {
    release = resolve;
  });
  const first = twice.submitPayment();
  const second = twice.submitPayment();
  check('submit: a second press while paying is ignored', proceeds(twice) === 1 && twice.submitting === true);
  release();
  await Promise.all([first, second]);
  check('submit: the button is free again afterwards', twice.submitting === false);

  const success = dialog();
  success.quick(60000);
  success.$wire.onProceed = async () => success.onCloseModal({ detail: { id: 'proceed-the-payment' } });
  await success.submitPayment();
  check('submit: a sale closes the dialog without an error', success.error === '' && success.sheetOpen === false
    && success.$wire.calls.some((c) => c[0] === 'paymentClosed'));

  const notified = dialog();
  notified.quick(60000);
  await notified.submitPayment();
  check('submit: still open with no field message means the server only sent a notification', notified.error === L.failed);

  const fieldErrors = dialog({}, { serverErrors: {} });
  fieldErrors.quick(60000);
  await fieldErrors.submitPayment();
  check('submit: field messages are shown by the page, not doubled', fieldErrors.error === '');

  const offline = dialog();
  offline.quick(60000);
  offline.$wire.onProceed = async () => {
    throw new Error('offline');
  };
  await offline.submitPayment();
  check('submit: a lost connection says so and frees the button', offline.error === L.failed && offline.submitting === false);

  const unsaved = dialog();
  unsaved.quick(60000);
  context.LakasirPending = { settle: async () => ({ ok: false }) };
  await unsaved.submitPayment();
  delete context.LakasirPending;
  check('submit: notes still being saved stop the payment', unsaved.error === L.unsaved && proceeds(unsaved) === 0
    && sets(unsaved)['cartDetail.payed_money'] === undefined);

  const refused = dialog();
  await refused.submitPayment();
  check('submit: nothing is sent while the pay button is disabled', refused.$wire.calls.length === 0);

  // ---- keyboard ----------------------------------------------------------------------------------

  const keys = dialog();
  const key = (k, target, extra) => {
    let prevented = false;
    keys.onKey(Object.assign({ key: k, target: target || { tagName: 'DIV' }, preventDefault() { prevented = true; } }, extra || {}));

    return prevented;
  };
  key('6');
  key('0');
  key('0', { tagName: 'INPUT', dataset: {} });
  key('0', { tagName: 'INPUT', dataset: { payDisplay: '' } });
  key('1', { tagName: 'DIV' }, { ctrlKey: true });
  check('keys: digits type the amount, except in other fields and with Ctrl', keys.received === '600', keys.received);
  key('Backspace');
  check('keys: Backspace', keys.received === '60');
  key('Delete');
  check('keys: Delete clears', keys.received === '');
  keys.quick(60000);
  key('Enter', { tagName: 'BUTTON' });
  check('keys: Enter on a button only clicks the button', proceeds(keys) === 0);
  key('Enter');
  await settle();
  check('keys: Enter pays', proceeds(keys) === 1);
  const closes = dispatched.length;
  keys.submitting = false;
  key('Escape');
  check('keys: Escape closes the dialog', dispatched.length === closes + 1 && dispatched[closes][0] === 'close-modal'
    && dispatched[closes][1].id === 'proceed-the-payment');
  keys.sheetOpen = false;
  key('9');
  check('keys: ignored while the dialog is closed', !keys.received.endsWith('9'));

  // ---- another device changed the bill ---------------------------------------------------------------

  const bill = dialog({ openBillId: 42, paymentPinned: true });
  bill.quick(60000);
  bill.onConflict({ detail: { id: 41, amount_due: 70000 } });
  check('conflict: another bill is ignored', bill.conflict === null);
  bill.onConflict({ detail: [{ id: 42, amount_due: 70000, serverVersion: 5 }] });
  check('conflict: the new total is shown and paying is blocked', bill.conflict && bill.conflict.amount === 70000
    && bill.reason === L.conflict.replace(':amount', bill.fmt(70000)) && !bill.canSubmit, bill.reason);
  await bill.reloadBill();
  check('conflict: reload takes the new bill and starts over', bill.conflict === null && bill.received === ''
    && bill.$wire.calls.some((c) => c[0] === 'syncCartForPayment'));

  // ---- opening -----------------------------------------------------------------------------------

  const opening = context.paymentFlow();
  opening.$wire = fakeWire();
  opening.$refs = {};
  opening.$nextTick = (fn) => fn();
  opening.onOpenModal({ detail: { id: 'another-modal' } });
  check('open: another dialog is ignored', opening.sheetOpen === false);
  opening.onOpenModal({ detail: { id: 'proceed-the-payment' } });
  await settle();
  check('open: starts from the newest cart', opening.sheetOpen === true && opening.ready === true
    && opening.$wire.calls.some((c) => c[0] === 'syncCartForPayment'));

  const pinned = context.paymentFlow();
  pinned.$wire = fakeWire({ paymentPinned: true });
  pinned.$refs = {};
  pinned.$nextTick = (fn) => fn();
  pinned.reset();
  await pinned.begin();
  check('open: a payment the server already pinned is not synced again', pinned.$wire.calls.length === 0 && pinned.ready);

  const broken = context.paymentFlow();
  broken.$wire = fakeWire({
    async syncCartForPayment() {
      throw new Error('offline');
    },
  });
  broken.$refs = {};
  broken.$nextTick = (fn) => fn();
  broken.reset();
  await broken.begin();
  check('open: a failed sync is shown, the dialog still works', broken.error === L.failed && broken.ready === true);
}

try {
  await main();
} catch (error) {
  failures.push('harness: ' + (error && error.stack ? error.stack : error));
}

process.stdout.write(JSON.stringify({ ok: failures.length === 0, checks, failures }) + '\n');
process.exit(failures.length === 0 ? 0 : 1);
