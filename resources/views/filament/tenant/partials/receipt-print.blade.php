@php
  $receiptAbout = \App\Models\Tenants\About::first();
  $receiptConfig = [
    'about' => $receiptAbout ? [
      'shop_name' => $receiptAbout->shop_name,
      'shop_location' => $receiptAbout->shop_location,
    ] : null,
    'showCurrency' => (bool) \App\Models\Tenants\Setting::get('receipt_show_currency', false),
    'kitchenShowPrices' => (bool) \App\Models\Tenants\Setting::get('kitchen_ticket_show_price', false),
    'logo' => \App\Models\Tenants\Setting::get('receipt_logo'),
    'paperWidth' => (string) \App\Models\Tenants\Setting::get('receipt_paper_width', '58') === '80' ? '80' : '58',
    'tax' => (bool) feature(\App\Features\SellingTax::class),
    'discount' => (bool) feature(\App\Features\Discount::class),
    'settingsUrl' => '/member/printer',
    // Placeholders (:n, :number, :from, :to, :time) are kept as they are; the script fills them in.
    'labels' => [
      'cashier' => __('Cashier'),
      'table' => __('Table'),
      'paymentMethod' => __('Payment method'),
      'customer' => __('Customer'),
      'general' => __('General'),
      'tax' => __('Tax'),
      'taxPrice' => __('Tax price'),
      'subtotal' => __('Subtotal'),
      'discount' => __('Discount'),
      'itemDiscount' => __('Discount'),
      'totalPrice' => __('Total price'),
      'payedMoney' => __('Payed money'),
      'change' => __('Change'),
      'note' => __('Note'),
      'orderNote' => __('Order note'),
      'uncategorized' => __('Uncategorized'),
      'printerMissing' => __('Printer not configured'),
      'noTransaction' => __('No transaction to print'),
      'failed' => __('Print failed'),
      'busy' => __('Printing is busy, please wait'),
      'copy' => __('COPY'),
      'provisional' => __('PROVISIONAL BILL'),
      'notPaid' => __('NOT PAID'),
      'notProof' => __('NOT A PROOF OF PAYMENT'),
      'opened' => __('Opened :time'),
      'billNo' => __('Bill #:number'),
      'kitchen' => __('KITCHEN'),
      'newOrder' => __('NEW ORDER'),
      'additionN' => __('ADDITION - SHIPMENT :n'),
      'cancelOnly' => __('CANCELLATION'),
      'voided' => __('BILL VOIDED'),
      'voidReason' => __('Void reason'),
      'cancelled' => __('CANCELLED'),
      'changedNote' => __('CHANGED NOTE'),
      'moved' => __('MOVED: table :from -> :to'),
      'takeaway' => __('TAKEAWAY #:number'),
      'tableWord' => __('TABLE :number'),
      'shipmentN' => __('Shipment :n'),
      'totalN' => __('(total :n)'),
    ],
  ];
@endphp
<script>
  (function () {
    // Config is refreshed on every render; the API below is installed once per page load.
    window.LakasirReceiptConfig = @js($receiptConfig);

    if (window.LakasirReceipt && Number(window.LakasirReceipt.version) >= 2) {
      return;
    }

    var DEFAULT_LABELS = {
      cashier: 'Cashier',
      table: 'Table',
      paymentMethod: 'Payment method',
      customer: 'Customer',
      general: 'General',
      tax: 'Tax',
      taxPrice: 'Tax price',
      subtotal: 'Subtotal',
      discount: 'Discount',
      itemDiscount: 'Discount',
      totalPrice: 'Total price',
      payedMoney: 'Payed money',
      change: 'Change',
      note: 'Note',
      orderNote: 'Order note',
      uncategorized: 'Uncategorized',
      printerMissing: 'Printer not configured',
      noTransaction: 'No transaction to print',
      failed: 'Print failed',
      busy: 'Printing is busy, please wait',
      copy: 'COPY',
      provisional: 'PROVISIONAL BILL',
      notPaid: 'NOT PAID',
      notProof: 'NOT A PROOF OF PAYMENT',
      opened: 'Opened :time',
      billNo: 'Bill #:number',
      kitchen: 'KITCHEN',
      newOrder: 'NEW ORDER',
      additionN: 'ADDITION - SHIPMENT :n',
      cancelOnly: 'CANCELLATION',
      voided: 'BILL VOIDED',
      voidReason: 'Void reason',
      cancelled: 'CANCELLED',
      changedNote: 'CHANGED NOTE',
      moved: 'MOVED: table :from -> :to',
      takeaway: 'TAKEAWAY #:number',
      tableWord: 'TABLE :number',
      shipmentN: 'Shipment :n',
      totalN: '(total :n)',
    };

    // Queue and duplicate guard tuning (milliseconds). Exposed as LakasirReceipt.timing for tests.
    var timing = { pollMs: 100, maxWaitMs: 8000, dedupeMs: 3000 };
    var MODES = { receipt: true, bill: true, kitchen: true };

    function config() {
      return window.LakasirReceiptConfig || {};
    }

    // Replaces :name placeholders; unknown placeholders stay untouched.
    function fill(text, params) {
      return String(text).replace(/:([A-Za-z_]+)/g, function (match, name) {
        return params && Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : match;
      });
    }

    function label(key, params) {
      var labels = config().labels || {};
      var text = labels[key] || DEFAULT_LABELS[key] || key;

      return toPrintable(params ? fill(text, params) : text);
    }

    function lineWidthOf(printer) {
      return Number(printer && printer.lineWidth) || 32;
    }

    function normalizeMode(mode) {
      return Object.prototype.hasOwnProperty.call(MODES, mode) ? mode : 'receipt';
    }

    // Plain text only; never parses markup into the live document.
    function htmlToPlain(html) {
      if (html === null || html === undefined) {
        return '';
      }

      var source = String(html)
        .replace(/<br\s*\/?>/gi, '\n')
        .replace(/<\/(p|div|li|h[1-6]|tr)\s*>/gi, '\n');
      var text = '';

      if (typeof DOMParser !== 'undefined') {
        try {
          var doc = new DOMParser().parseFromString(source, 'text/html');
          doc.querySelectorAll('script,style,template').forEach(function (node) {
            node.remove();
          });
          text = (doc.body && doc.body.textContent) || '';
        } catch (e) {
          text = stripTags(source);
        }
      } else {
        text = stripTags(source);
      }

      return text
        .replace(/\r\n?/g, '\n')
        .replace(/[\u0000-\u0009\u000B-\u001F\u007F]/g, '')
        .split('\n')
        .map(function (line) {
          return line.replace(/[ \t ]+/g, ' ').trim();
        })
        .join('\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
    }

    function stripTags(source) {
      var named = { nbsp: ' ', amp: '&', lt: '<', gt: '>', quot: '"', apos: "'" };

      return source
        .replace(/<(script|style|template)\b[\s\S]*?<\/\1\s*>/gi, '')
        .replace(/<\/?[a-z!][^>]*>?/gi, '')
        .replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, function (match, entity) {
          if (entity.charAt(0) === '#') {
            var code = entity.charAt(1).toLowerCase() === 'x'
              ? parseInt(entity.slice(2), 16)
              : parseInt(entity.slice(1), 10);
            return code > 0 && code < 0x110000 ? String.fromCodePoint(code) : '';
          }
          var key = entity.toLowerCase();
          return Object.prototype.hasOwnProperty.call(named, key) ? named[key] : match;
        });
    }

    // Printable ASCII only: thermal printers without a code page print garbage for anything else.
    // Control characters (ESC, GS, ...) become spaces, so a note can never inject printer commands.
    function toPrintable(text) {
      if (text === null || text === undefined) {
        return '';
      }

      var s = String(text)
        .replace(/[‘’‚‛′´]/g, "'")
        .replace(/[“”„‟″]/g, '"')
        .replace(/[‐-―−]/g, '-')
        .replace(/[•‣◦⁃∙·]/g, '*')
        .replace(/×/g, 'x')
        .replace(/…/g, '...')
        .replace(/[       ]/g, ' ');

      if (typeof s.normalize === 'function') {
        s = s.normalize('NFKD').replace(/[̀-ͯ]/g, '');
      }

      return s
        .replace(/[\u0000-\u001F\u007F]+/g, ' ')
        .replace(/[^ -~]/g, '')
        .replace(/ {2,}/g, ' ')
        .trim();
    }

    // Wraps to `width` columns. Continuation lines start with `indent`; leading spaces of the
    // text are kept on the first line. Words longer than a line are split.
    function wrapText(text, width, indent) {
      var limit = Math.max(4, parseInt(width, 10) || 32);
      var cont = indent === undefined || indent === null ? '    ' : String(indent);
      var source = text === null || text === undefined ? '' : String(text);
      var lead = (source.match(/^ */) || [''])[0];
      var words = source.trim().split(/\s+/).filter(Boolean);

      if (lead.length > limit - 2) {
        lead = lead.slice(0, Math.max(0, limit - 2));
      }
      if (cont.length > limit - 2) {
        cont = cont.slice(0, Math.max(0, limit - 2));
      }

      var lines = [];
      var current = lead;
      var hasWord = false;

      words.forEach(function (word) {
        while (word.length > 0) {
          var separator = hasWord ? ' ' : '';
          if (current.length + separator.length + word.length <= limit) {
            current += separator + word;
            hasWord = true;
            word = '';
          } else if (hasWord) {
            lines.push(current);
            current = cont;
            hasWord = false;
          } else {
            var room = limit - current.length;
            lines.push(current + word.slice(0, room));
            word = word.slice(room);
            current = cont;
            hasWord = false;
          }
        }
      });

      if (hasWord) {
        lines.push(current);
      }

      return lines;
    }

    function itemName(detail) {
      var product = (detail && detail.product) || {};
      var name = toPrintable(product.name);
      if (name) {
        return name;
      }
      var sku = toPrintable(product.sku);
      if (sku) {
        return sku;
      }
      var id = product.id !== undefined && product.id !== null ? product.id : (detail && detail.product_id);

      return '#' + (id === undefined || id === null ? '' : id);
    }

    function detailsOf(selling) {
      var details = (selling && (selling.selling_details || selling.sellingDetails)) || [];

      return Array.isArray(details) ? details : [];
    }

    // Groups by category id in first-seen order; does not sort (the server already did).
    function groupDetails(details) {
      var groups = [];
      var index = {};

      (Array.isArray(details) ? details : []).forEach(function (detail) {
        var category = detail && detail.product ? detail.product.category : null;
        var hasCategory = category && category.id !== undefined && category.id !== null;
        var key = hasCategory ? 'c' + category.id : 'none';

        if (!Object.prototype.hasOwnProperty.call(index, key)) {
          index[key] = groups.length;
          groups.push({
            key: key,
            category: hasCategory && category.name !== undefined && category.name !== null ? category.name : null,
            lines: [],
          });
        }
        groups[index[key]].lines.push(detail);
      });

      return groups;
    }

    // ---- numbers -------------------------------------------------------------------------

    var numberFormats = {};

    function localNumber(number, maxFraction) {
      if (!numberFormats[maxFraction]) {
        numberFormats[maxFraction] = new Intl.NumberFormat('id-ID', {
          minimumFractionDigits: 0,
          maximumFractionDigits: maxFraction,
        });
      }

      return numberFormats[maxFraction].format(Number(number) || 0).replace(/ /g, ' ');
    }

    function round2(value) {
      return Math.round((Number(value) || 0) * 100) / 100;
    }

    function qtyText(qty) {
      return localNumber(qty, 3);
    }

    // Shared by build() and preview(): one money format, so the preview equals the print.
    function moneyFormatter(showCurrency) {
      return function (number, fraction) {
        var formatted = localNumber(number, fraction || 0);

        return showCurrency ? 'Rp ' + formatted : formatted;
      };
    }

    // The unit price is price / qty, where price is the whole line total (before the line discount).
    // The total is the authority: "~" marks a unit price that is rounded and does not multiply back exactly.
    function lineFigures(detail) {
      var qty = Number(detail && detail.qty);
      if (!(qty > 0)) {
        qty = 1;
      }
      var total = round2(detail && detail.price);
      var unit = round2(total / qty);

      return { qty: qty, total: total, unit: unit, exact: Math.abs(round2(unit * qty) - total) < 0.005 };
    }

    // "25.000", "~3.333,33" (rounded unit price) or "Rp 25.000" when the currency is shown.
    function unitText(figures, showCurrency) {
      return (figures.exact ? '' : '~') + (showCurrency ? 'Rp ' : '') + localNumber(figures.unit, 2);
    }

    // ---- layout helpers ------------------------------------------------------------------

    function separatorOf(width) {
      return new Array(Math.max(8, width - 1) + 1).join('-');
    }

    function spaces(count) {
      return new Array(Math.max(0, count) + 1).join(' ');
    }

    // glue() turns spaces into a character wrapText does not break at (it is not whitespace, and toPrintable has
    // already removed every control character), so "(total 3)" never ends up split over two lines.
    var GLUE = '\u0001';

    function glue(text) {
      return String(text).split(' ').join(GLUE);
    }

    function unglue(line) {
      return String(line).split(GLUE).join(' ');
    }

    function printLines(printer, text, width, indent) {
      String(text || '').split('\n').forEach(function (part) {
        var clean = toPrintable(part);
        if (clean) {
          wrapText(clean, width, indent).forEach(function (line) {
            printer.text(line);
          });
        }
      });
    }

    // left text and right text on one line; stacked (left lines, then the right text right-aligned)
    // when they do not fit. Builds the row itself so the indent of `left` survives.
    function twoColumns(printer, left, right, width) {
      if (left.length + right.length + 1 <= width) {
        printer.text(left + spaces(width - left.length - right.length) + right);

        return;
      }
      wrapText(left, width, '    ').forEach(function (line) {
        printer.text(line);
      });
      printer.align('right').text(right).align('left');
    }

    // "Label      value" on one line; a value that does not fit goes below the label (never cut, never wider than the paper).
    function labelRow(printer, name, value, width) {
      var text = toPrintable(value);

      if (name.length + text.length + 1 <= width) {
        printer.table([name, text]);

        return;
      }
      printer.text(name + ':');
      wrapText('  ' + text, width, '  ').forEach(function (line) {
        printer.text(line);
      });
    }

    function centered(printer, text, width, bold) {
      wrapText(text, width, '').forEach(function (line) {
        if (bold) {
          printer.style('bold');
        }
        printer.align('center').text(line);
        if (bold) {
          printer.style('normal');
        }
      });
      printer.align('left');
    }

    function orderNumberText(selling, fallbackLabel) {
      if (selling.formatted_daily_order_number) {
        return toPrintable(selling.formatted_daily_order_number);
      }
      if (selling.daily_order_number) {
        var digits = String(selling.daily_order_number).padStart(3, '0');

        return fallbackLabel === 'billNo' ? label('billNo', { number: digits }) : 'Order #' + digits;
      }

      return '';
    }

    function orderNoteOf(selling) {
      return selling.note_plain !== undefined && selling.note_plain !== null
        ? String(selling.note_plain)
        : htmlToPlain(selling.note);
    }

    function customerNameOf(selling) {
      if (selling.customer_name) {
        var name = toPrintable(selling.customer_name);
        if (name) {
          return name;
        }
      }
      if (selling.member && selling.member.name) {
        return toPrintable(selling.member.name);
      }

      return '';
    }

    // ---- receipt and provisional bill ----------------------------------------------------

    function shopHeader(printer, ctx) {
      var about = ctx.cfg.about;

      printer.font('a');
      if (about !== undefined && about !== null) {
        // The name is printed twice as wide, so it wraps at half the columns.
        printer.size(1).align('center');
        wrapText(toPrintable(about.shop_name), Math.floor(ctx.width / 2), '').forEach(function (line) {
          printer.text(line);
        });
        printer.size(0);
        wrapText(toPrintable(about.shop_location), ctx.width, '').forEach(function (line) {
          printer.text(line);
        });
        if (ctx.printerData.header) {
          printLines(printer, ctx.printerData.header, ctx.width, '');
        }
      }
    }

    function lineBlock(printer, ctx, detail) {
      var width = ctx.width;
      var figures = lineFigures(detail);

      wrapText(itemName(detail), width, '  ').forEach(function (line) {
        printer.text(line);
      });

      twoColumns(
        printer,
        '  ' + qtyText(figures.qty) + ' x ' + unitText(figures, ctx.showCurrency),
        ctx.money(figures.total),
        width
      );

      if (Number(detail.discount_price) > 0) {
        wrapText('  ' + label('itemDiscount') + ' (' + ctx.money(detail.discount_price) + ')', width, '    ')
          .forEach(function (line) {
            printer.text(line);
          });
      }

      var note = toPrintable(detail.note);
      if (note) {
        wrapText('  > ' + note, width).forEach(function (line) {
          printer.text(line);
        });
      }
    }

    function itemGroups(printer, ctx, selling) {
      var groups = groupDetails(detailsOf(selling));
      var titled = groups.length >= 2;

      groups.forEach(function (group, position) {
        if (position > 0) {
          printer.text('');
        }
        if (titled) {
          var title = toPrintable(group.category) || label('uncategorized');
          printer.style('bold');
          wrapText('[ ' + title.toUpperCase() + ' ]', ctx.width, '').forEach(function (line) {
            printer.text(line);
          });
          printer.style('normal');
        }
        group.lines.forEach(function (detail) {
          lineBlock(printer, ctx, detail);
        });
      });
    }

    function buildSale(printer, ctx, selling, opts, mode) {
      var cfg = ctx.cfg;
      var width = ctx.width;
      var money = ctx.money;
      var separator = separatorOf(width);
      var isBill = mode === 'bill';

      shopHeader(printer, ctx);

      if (isBill) {
        printer.align('center').style('bold').text(label('provisional')).text(label('notPaid')).style('normal');
      }

      var orderLabel = orderNumberText(selling, isBill ? 'billNo' : 'order');
      if (orderLabel) {
        printer.align('center')
          .size(1, 1)
          .style('bold')
          .text(orderLabel)
          .style('normal')
          .size(0, 0);
      }
      if (isBill && selling.business_date_label) {
        printer.align('center').style('bold').text(toPrintable(selling.business_date_label)).style('normal');
      }

      printer.align('left').text(separator);

      var user = selling.user || {};
      labelRow(printer, label('cashier'), user.name || user.cashier_name, width);
      if (selling.table !== undefined && selling.table !== null) {
        labelRow(printer, label('table'), String(selling.table.number || ''), width);
      }
      if (!isBill) {
        var payment = selling.payment_method || selling.paymentMethod || {};
        labelRow(printer, label('paymentMethod'), toPrintable(payment.name) || 'Cash', width);
      }
      labelRow(printer, label('customer'), customerNameOf(selling) || label('general'), width);
      if (isBill && selling.business_date_label && selling.created_at_label) {
        wrapText(label('opened', { time: toPrintable(selling.created_at_label) }), width, '  ').forEach(function (line) {
          printer.text(line);
        });
      }

      var orderNote = orderNoteOf(selling);
      if (toPrintable(orderNote)) {
        printer.text(label('orderNote') + ':');
        printLines(printer, orderNote, width, '');
      }

      printer.text(separator);
      itemGroups(printer, ctx, selling);
      printer.text(separator);

      var itemDiscounts = Number(selling.total_discount_per_item) || 0;
      var headerDiscount = Number(selling.discount_price) || 0;
      var discountTotal = itemDiscounts + headerDiscount;

      if (isBill) {
        var subtotal = selling.subtotal;
        if (subtotal === undefined || subtotal === null) {
          subtotal = detailsOf(selling).reduce(function (sum, detail) {
            return sum + (Number(detail.price) || 0);
          }, 0);
        }
        if (cfg.tax || Number(selling.tax_price) > 0) {
          printer.table([label('tax'), String(selling.tax === undefined || selling.tax === null ? 0 : selling.tax) + '%'])
            .table([label('taxPrice'), money(selling.tax_price || 0)]);
        }
        printer.table([label('subtotal'), money(subtotal)]);
        if (cfg.discount || discountTotal > 0) {
          printer.table([label('discount'), '(' + money(discountTotal) + ')']);
        }
        printer.style('bold')
          .table([label('totalPrice'), money(selling.amount_due || 0)])
          .style('normal')
          .text(separator);
        printer.style('bold').align('center').text(label('notProof')).style('normal');
        if (ctx.reprint) {
          printer.align('left').text(label('copy'));
        }

        return;
      }

      if (cfg.tax) {
        printer.table([label('tax'), String(selling.tax === undefined || selling.tax === null ? 0 : selling.tax) + '%'])
          .table([label('taxPrice'), money(selling.tax_price || 0)]);
      }
      printer.table([label('subtotal'), money(selling.total_price || 0)]);
      if (cfg.discount || discountTotal > 0) {
        printer.table([label('discount'), '(' + money(discountTotal) + ')']);
      }
      printer
        .table([label('totalPrice'), money(selling.grand_total_price || 0)])
        .text(separator)
        .table([label('payedMoney'), money(selling.payed_money || 0)])
        .table([label('change'), money(selling.money_changes || 0)])
        .align('center');

      if (ctx.printerData.footer) {
        printLines(printer, ctx.printerData.footer, width, '');
      }

      if (ctx.reprint) {
        printer.align('left').text(label('copy'));
      }
    }

    // ---- kitchen ticket ------------------------------------------------------------------

    function movedText(moved) {
      var takeawayWord = label('takeaway', { number: '' }).replace(/\s*#\s*$/, '');
      var from = moved && moved.from !== undefined && moved.from !== null ? toPrintable(String(moved.from)) : '';
      var to = moved && moved.to !== undefined && moved.to !== null ? toPrintable(String(moved.to)) : '';

      if (from && to) {
        return label('moved', { from: from, to: to });
      }
      var word = function (value) {
        return value ? label('tableWord', { number: value }) : takeawayWord;
      };

      return label('moved', { from: '', to: '' }).split(':')[0] + ': ' + word(from) + ' -> ' + word(to);
    }

    function bigLine(printer, text, width) {
      var limit = Math.max(4, Math.floor(width / 2));

      printer.align('center').style('bold').size(1, 1);
      wrapText(text, limit, '').forEach(function (line) {
        printer.text(line);
      });
      printer.size(0, 0).style('normal').align('left');
    }

    // The items are printed twice as wide AND twice as high: twice as high alone (size(0, 1)) makes narrow letters that
    // look squeezed. A 2x wide character takes two columns, so the lines wrap at half the columns, like bigLine().
    function bigItemLines(printer, ctx, prefix, name, note) {
      var width = ctx.width;
      var limit = Math.max(4, Math.floor(width / 2));

      printer.size(1, 1).style('bold');
      wrapText(prefix + name, limit, spaces(prefix.length)).forEach(function (line) {
        printer.text(unglue(line));
      });
      printer.size(0, 0);
      if (note) {
        wrapText('  > ' + note, width).forEach(function (line) {
          printer.text(line);
        });
      }
      printer.style('normal');
    }

    function buildKitchen(printer, ctx, selling, opts) {
      var width = ctx.width;
      var kitchen = selling.kitchen || {};
      var separator = separatorOf(width);
      var showPrices = opts.showPrices !== undefined && opts.showPrices !== null
        ? Boolean(opts.showPrices)
        : Boolean(ctx.cfg.kitchenShowPrices);
      var batchNo = Number(kitchen.batch_no) || 0;
      var kind = kitchen.kind;

      printer.font('a');
      if (ctx.reprint) {
        centered(printer, label('copy'), width, true);
      }

      var type;
      if (kitchen.is_void || kind === 'void') {
        type = label('voided');
      } else if (kind === 'cancel_only') {
        type = label('cancelOnly');
      } else if (kind === 'change_only') {
        // Nothing new to cook: only a table move and/or changed notes.
        type = Array.isArray(kitchen.changed) && kitchen.changed.length
          ? label('changedNote')
          : label('moved', { from: '', to: '' }).split(':')[0];
      } else if (kind === 'new' || (!kind && batchNo <= 1)) {
        type = label('newOrder');
      } else {
        type = label('additionN', { n: batchNo });
      }
      centered(printer, label('kitchen') + ' - ' + type, width, true);

      var digits = selling.daily_order_number
        ? String(selling.daily_order_number).padStart(3, '0')
        : toPrintable(String(selling.number_label || '')).replace(/^#/, '');
      var tableLabel = kitchen.table_label !== undefined && kitchen.table_label !== null
        ? kitchen.table_label
        : (selling.table ? selling.table.number : null);
      tableLabel = tableLabel === null || tableLabel === undefined ? '' : toPrintable(String(tableLabel));
      var customer = customerNameOf(selling);
      var big;
      if (tableLabel) {
        big = label('tableWord', { number: tableLabel });
      } else if (customer) {
        big = customer;
      } else {
        big = label('takeaway', { number: digits });
      }
      bigLine(printer, big.toUpperCase(), width);

      var orderLabel = orderNumberText(selling, 'billNo');
      var dateLabel = toPrintable(selling.business_date_label);
      var head = (orderLabel + (dateLabel ? ' ' + dateLabel : '')).trim();
      // Date and time: a ticket of yesterday that is still lying around must not pass for today's.
      var stamp = [toPrintable(selling.printed_on_label), toPrintable(selling.printed_at_label)].filter(Boolean).join(' ');
      printer.style('bold');
      if (head && stamp) {
        twoColumns(printer, head, stamp, width);
      } else if (head || stamp) {
        printer.text(head || stamp);
      }
      printer.style('normal');

      var user = selling.user || {};
      labelRow(printer, label('cashier'), user.name || user.cashier_name, width);
      if (customer) {
        labelRow(printer, label('customer'), customer, width);
      }

      if ((kitchen.is_void || kind === 'void') && toPrintable(kitchen.void_reason)) {
        printer.style('bold');
        wrapText(label('voidReason') + ': ' + toPrintable(kitchen.void_reason), width, '  ').forEach(function (line) {
          printer.text(line);
        });
        printer.style('normal');
      }

      if (kitchen.moved) {
        printer.style('bold');
        wrapText(movedText(kitchen.moved), width, '  ').forEach(function (line) {
          printer.text(line);
        });
        printer.style('normal');
      }

      printer.text(separator);

      var groups = groupDetails(detailsOf(selling));
      var titled = groups.length >= 2;
      groups.forEach(function (group, position) {
        if (position > 0) {
          printer.text('');
        }
        if (titled) {
          var title = toPrintable(group.category) || label('uncategorized');
          printer.style('bold');
          wrapText('[ ' + title.toUpperCase() + ' ]', width, '').forEach(function (line) {
            printer.text(line);
          });
          printer.style('normal');
        }
        group.lines.forEach(function (detail) {
          var qty = Number(detail.qty) || 0;
          var totalQty = detail.total_qty !== undefined && detail.total_qty !== null ? Number(detail.total_qty) : qty;
          var name = itemName(detail);
          if (Math.abs(totalQty - qty) > 0.0005) {
            name += ' ' + glue(label('totalN', { n: qtyText(totalQty) }));
          }
          bigItemLines(printer, ctx, qtyText(qty) + ' x ', name, toPrintable(detail.note));

          if (showPrices) {
            var figures = lineFigures({ qty: totalQty > 0 ? totalQty : 1, price: detail.price });
            var shown = round2(figures.unit * qty);
            twoColumns(printer, '  ' + qtyText(qty) + ' x ' + unitText(figures, ctx.showCurrency), ctx.money(shown), width);
          }
        });
      });

      var cancelled = Array.isArray(kitchen.cancelled) ? kitchen.cancelled : [];
      if (cancelled.length) {
        if (groups.length) {
          printer.text(separator);
        }
        printer.style('bold').text(label('cancelled') + ':').style('normal');
        cancelled.forEach(function (entry) {
          var name = toPrintable(entry && entry.name)
            || '#' + (entry && entry.product_id !== undefined && entry.product_id !== null ? entry.product_id : '');
          bigItemLines(printer, ctx, qtyText(Number(entry && entry.qty) || 0) + ' x ', name, toPrintable(entry && entry.note));
        });
      }

      var changed = Array.isArray(kitchen.changed) ? kitchen.changed : [];
      if (changed.length) {
        if (groups.length || cancelled.length) {
          printer.text(separator);
        }
        printer.style('bold').text(label('changedNote') + ':').style('normal');
        changed.forEach(function (entry) {
          var name = toPrintable(entry && entry.name)
            || '#' + (entry && entry.product_id !== undefined && entry.product_id !== null ? entry.product_id : '');
          var was = toPrintable(entry && entry.was) || '-';
          var now = toPrintable(entry && entry.note) || '-';
          wrapText(name + ': ' + was + ' -> ' + now, width, '  ').forEach(function (line) {
            printer.text(line);
          });
        });
      }

      var orderNote = orderNoteOf(selling);
      if (toPrintable(orderNote)) {
        printer.text(separator);
        printer.text(label('orderNote') + ':');
        printLines(printer, orderNote, width, '');
      }

      if (groups.length || cancelled.length || changed.length || toPrintable(orderNote)) {
        printer.text(separator);
      }
      if (batchNo > 0) {
        centered(printer, label('shipmentN', { n: batchNo }), width, false);
      }
      if (ctx.reprint) {
        centered(printer, label('copy'), width, true);
      }
    }

    // ---- build ---------------------------------------------------------------------------

    // Pure: only writes ESC/POS-style calls to `printer` (a Printer or a TextPrinter).
    // The last calls are always size(0, 0), style('normal'), align('left'), so the cut is clean.
    function build(printer, selling, opts) {
      opts = opts || {};
      selling = selling || {};

      var cfg = config();
      var mode = normalizeMode(opts.mode);
      var printerData = opts.printerData || {};
      var showCurrency = Boolean(
        printerData.show_currency !== undefined && printerData.show_currency !== null
          ? printerData.show_currency
          : cfg.showCurrency
      );
      var reprint = opts.reprint !== undefined && opts.reprint !== null
        ? Boolean(opts.reprint)
        : Boolean(selling.kitchen && selling.kitchen.reprint);
      var ctx = {
        cfg: cfg,
        printerData: printerData,
        width: lineWidthOf(printer),
        showCurrency: showCurrency,
        money: moneyFormatter(showCurrency),
        reprint: reprint,
      };

      if (mode === 'kitchen') {
        buildKitchen(printer, ctx, selling, opts);
      } else {
        buildSale(printer, ctx, selling, opts, mode);
      }

      printer.size(0, 0).style('normal').align('left');

      return printer;
    }

    // ---- text printer (preview) ----------------------------------------------------------

    // Records the same calls as Printer, as plain text lines. Alignment and the 2x sizes use the
    // effective width (a 2x wide character takes two columns), like the paper does.
    function TextPrinter(options) {
      options = options || {};
      var paper = parseInt(options.paper_width || options.paperWidth, 10) === 80 ? 80 : 58;

      this.paperWidth = paper;
      this.lineWidth = paper === 80 ? 48 : 32;
      this.lines = [];
      this.alignment = 'left';
      this.widthScale = 1;
    }

    TextPrinter.prototype.font = function () {
      return this;
    };
    TextPrinter.prototype.style = function () {
      return this;
    };
    TextPrinter.prototype.barcode = function () {
      return this;
    };
    TextPrinter.prototype.align = function (alignment) {
      this.alignment = alignment === 'center' || alignment === 'right' ? alignment : 'left';

      return this;
    };
    TextPrinter.prototype.size = function (width) {
      this.widthScale = (Number(width) || 0) + 1;

      return this;
    };
    TextPrinter.prototype.emit = function (line) {
      var effective = line.length * this.widthScale;
      var pad = 0;

      if (this.alignment === 'center') {
        pad = Math.max(0, Math.floor((this.lineWidth - effective) / 2));
      } else if (this.alignment === 'right') {
        pad = Math.max(0, this.lineWidth - effective);
      }
      this.lines.push(spaces(pad) + line);

      return this;
    };
    TextPrinter.prototype.text = function (text) {
      var self = this;

      String(text === null || text === undefined ? '' : text).split('\n').forEach(function (line) {
        self.emit(line);
      });

      return this;
    };
    // Same arithmetic as Printer.table
    TextPrinter.prototype.table = function (data) {
      var total = data.reduce(function (sum, cell) {
        return sum + String(cell).length;
      }, 0);
      var padding = Math.max(1, this.lineWidth - total);
      var row = '';

      data.forEach(function (cell, index) {
        row += cell;
        if (index < data.length - 1) {
          row += spaces(padding);
        }
      });

      return this.emit(row.trim());
    };
    TextPrinter.prototype.tableCustom = function (data) {
      return this.table(data.map(function (cell) {
        return cell.text;
      }));
    };
    TextPrinter.prototype.newLine = function (count) {
      for (var i = 0; i < (count || 1); i++) {
        this.emit('');
      }

      return this;
    };
    TextPrinter.prototype.cut = function () {
      return this;
    };
    TextPrinter.prototype.image = function () {
      return Promise.resolve(this);
    };
    TextPrinter.prototype.print = function () {
      return Promise.resolve();
    };
    TextPrinter.prototype.toString = function () {
      return this.lines.join('\n');
    };

    function readPrinter() {
      try {
        if (typeof getPrinter === 'function') {
          return getPrinter();
        }
        if (typeof window.getPrinter === 'function') {
          return window.getPrinter();
        }
        if (window.localStorage && window.localStorage.printer) {
          return JSON.parse(window.localStorage.printer);
        }
      } catch (e) {
        console.error('Cannot read the printer settings:', e);
      }

      return null;
    }

    // The exact text of the print, 32 or 48 columns (width of the saved printer unless opts.paperWidth).
    function preview(selling, opts) {
      opts = opts || {};

      var printerData = opts.printerData || readPrinter() || {};
      var paper = opts.paperWidth || printerData.paper_width || config().paperWidth || 58;
      var text = new TextPrinter({ paperWidth: paper });

      build(text, selling, {
        mode: opts.mode,
        reprint: opts.reprint,
        showPrices: opts.showPrices,
        printerData: printerData,
      });

      return text.toString();
    }

    // ---- print queue ---------------------------------------------------------------------

    function notify(title, type, withSettings) {
      if (typeof FilamentNotification !== 'undefined') {
        var notification = new FilamentNotification().title(title);
        notification = type === 'warning' ? notification.warning() : notification.danger();
        if (withSettings && typeof FilamentNotificationAction !== 'undefined') {
          notification = notification.actions([
            new FilamentNotificationAction('Setting')
              .icon('heroicon-o-cog-6-tooth')
              .button()
              .url(config().settingsUrl || '/member/printer'),
          ]);
        }
        notification.send();
      } else if (typeof alert === 'function') {
        alert(title);
      }
    }

    function setButtonBusy(button, busy) {
      if (!button) {
        return;
      }
      button.style.pointerEvents = busy ? 'none' : '';
      button.style.opacity = busy ? '0.6' : '';
    }

    function sleep(ms) {
      return new Promise(function (resolve) {
        setTimeout(resolve, ms);
      });
    }

    // Printer.print() sets window._lakasirIsPrintingNow while it works (and for a moment after) and silently
    // does nothing if it is called meanwhile, so every job first waits for the flag to clear.
    function waitUntilIdle() {
      var started = Date.now();

      return new Promise(function (resolve) {
        (function check() {
          if (!window._lakasirIsPrintingNow) {
            resolve(true);
          } else if (Date.now() - started >= timing.maxWaitMs) {
            resolve(false);
          } else {
            setTimeout(check, timing.pollMs);
          }
        })();
      });
    }

    function jobKeyOf(payload, opts, mode) {
      if (opts.jobKey !== undefined && opts.jobKey !== null && opts.jobKey !== '') {
        return String(opts.jobKey);
      }
      var id = payload && payload.id !== undefined && payload.id !== null
        ? payload.id
        : (payload && payload.daily_order_number !== undefined && payload.daily_order_number !== null
          ? payload.daily_order_number
          : '');

      return mode + ':' + id;
    }

    // A job never rejects and never disappears: it resolves true (printed) or false (and tells the page).
    function failed(job, reason, message, notified) {
      console.warn('Print job failed (' + job.mode + ', ' + job.key + '): ' + reason);
      if (typeof window.dispatchEvent === 'function' && typeof CustomEvent === 'function') {
        window.dispatchEvent(new CustomEvent('lakasir-print-failed', {
          detail: {
            mode: job.mode,
            jobKey: job.key,
            payload: job.payload,
            ack: job.opts.ack,
            reprint: Boolean(job.opts.reprint),
            reason: reason,
            message: message || '',
            notified: Boolean(notified),
          },
        }));
      }

      return false;
    }

    async function runJob(job) {
      try {
        if (!(await waitUntilIdle())) {
          return failed(job, 'busy', label('busy'), false);
        }

        var printerData = readPrinter();
        // Kitchen failures are shown by the page (permanent toast with retry); the others are toasted here.
        // A page without that (the sale view) asks for the toast with opts.toast.
        var toast = job.mode !== 'kitchen' || Boolean(job.opts.toast);

        if (!printerData) {
          if (toast) {
            notify(label('printerMissing'), 'danger', true);
          }

          return failed(job, 'no_printer', label('printerMissing'), toast);
        }
        if (!job.payload) {
          if (toast) {
            notify(label('noTransaction'), 'warning', false);
          }

          return failed(job, 'no_payload', label('noTransaction'), toast);
        }
        if (typeof window.Printer !== 'function') {
          return failed(job, 'no_printer_class', label('printerMissing'), false);
        }

        var printer = new window.Printer(printerData);
        var logo = printerData.logo || config().logo;
        if (job.mode !== 'kitchen' && logo && printerData.driver !== 'bluetooth') {
          await printer.image(logo, printerData.paper_width || config().paperWidth || 58);
        }

        build(printer, job.payload, {
          mode: job.mode,
          reprint: Boolean(job.opts.reprint),
          showPrices: job.opts.showPrices,
          printerData: printerData,
        });

        await printer
          .cut()
          .print();

        // Printer.print() raises the flag when it really runs; without it nothing was sent.
        if (!window._lakasirIsPrintingNow) {
          return failed(job, 'not_executed', label('failed'), false);
        }

        return true;
      } catch (error) {
        console.error('Print error:', error);
        var message = label('failed') + ': ' + ((error && error.message) || error);
        var show = job.mode !== 'kitchen' || Boolean(job.opts.toast);
        if (show) {
          notify(message, 'danger', false);
        }

        return failed(job, 'error', message, show);
      }
    }

    var queueTail = Promise.resolve();
    var recentJobs = {};

    // print(payload, opts?: {buttonId, reprint, mode: 'receipt'|'bill'|'kitchen', showPrices, jobKey, ack, toast}): Promise<boolean>
    // Jobs run one after another. The same jobKey (default mode:id) returns the same Promise while the job is
    // queued or running and for dedupeMs after it printed; a failed job can be retried at once.
    function print(payload, opts) {
      opts = opts || {};

      var mode = normalizeMode(opts.mode);
      var key = jobKeyOf(payload, opts, mode);
      var known = recentJobs[key];

      if (known && (known.open || Date.now() < known.until)) {
        return known.promise;
      }

      var button = opts.buttonId && typeof document !== 'undefined' ? document.getElementById(opts.buttonId) : null;
      var entry = { open: true, until: 0, promise: null };
      var job = { mode: mode, key: key, payload: payload, opts: opts };

      setButtonBusy(button, true);
      entry.promise = queueTail
        .then(function () {
          return runJob(job);
        })
        .then(function (ok) {
          entry.open = false;
          if (ok) {
            entry.until = Date.now() + timing.dedupeMs;
            setTimeout(function () {
              setButtonBusy(button, false);
              if (recentJobs[key] === entry) {
                delete recentJobs[key];
              }
            }, timing.dedupeMs);
          } else {
            setButtonBusy(button, false);
            if (recentJobs[key] === entry) {
              delete recentJobs[key];
            }
          }

          return ok;
        });
      queueTail = entry.promise;
      recentJobs[key] = entry;

      return entry.promise;
    }

    window.LakasirReceipt = {
      version: 2,
      print: print,
      build: build,
      preview: preview,
      TextPrinter: TextPrinter,
      timing: timing,
      groupDetails: groupDetails,
      htmlToPlain: htmlToPlain,
      toPrintable: toPrintable,
      wrapText: wrapText,
      itemName: itemName,
    };
  })();
</script>
